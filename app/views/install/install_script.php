<?php header("Content-Type: text/plain");
?>#!/bin/bash

BASEURL="<?php echo
	(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https://' : 'http://')
	. $_SERVER['HTTP_HOST']
	. conf('subdirectory'); ?>"
TPL_BASE="${BASEURL}/assets/client_installer/"
MUNKIPATH="/usr/local/munki/" # TODO read munkipath from munki config
CACHEPATH="${MUNKIPATH}preflight.d/cache/"
PREFPATH="/Library/Preferences/MunkiReport"
PREFLIGHT=1
PREF_CMDS=( ) # Pref commands array
CURL="/usr/bin/curl --insecure --fail --silent  --show-error"
# Exit status
ERR=0

# Packaging
BUILDPKG=0
IDENTIFIER="com.github.munkireport"
RESULT=""

VERSION="<?php echo get_version(); ?>"

function usage {
	PROG=$(basename $0)
	cat <<EOF >&2
Usage: ${PROG} [OPTIONS]

  -b URL    Base url to munki report server
            Current value: ${BASEURL}
  -m PATH   Path to installation directory
            Current value: ${MUNKIPATH}
  -p PATH   Path to preferences file (without the .plist extension)
            Current value: ${PREFPATH}
  -n        Do not run preflight script after the installation
  -i PATH   Create a full installer at PATH
  -c ID     Change pkg id to ID
  -h        Display this help message
	-r PATH   Path to installer result plist
  -v VERS   Override version number

Example:
  * Install munkireport client scripts into the default location and run the
    preflight script.

        $PROG

  * Install munkireport and preferences into a custom location ready to be
    packaged.

        $PROG -b ${BASEURL} \\
              -m ~/Desktop/munkireport-$VERSION/usr/local/munki/ \\
              -p ~/Desktop/munkireport-$VERSION/Library/Preferences/MunkiReport \\
              -n

  * Create a package installer for munkireport.

        $PROG -i ~/Desktop
EOF
}

# Set munkireport preference
function setpref {
	PREF_CMDS=( "${PREF_CMDS[@]}" "defaults write ${PREFPATH} ${1} \"${2}\"" )
}

# Set munkireport reportitem preference
function setreportpref {
	setpref "ReportItems -dict-add ${1}" "${2}"
}

# Reset reportitems
function resetreportpref {
	PREF_CMDS=( "${PREF_CMDS[@]}" "defaults write ${PREFPATH} ReportItems -dict" )
}

while getopts b:m:p:r:c:v:i:nh flag; do
	case $flag in
		b)
			BASEURL="$OPTARG"
			;;
		m)
			MUNKIPATH="$OPTARG"
			;;
		p)
			PREFPATH="$OPTARG"
			;;
		r)
			RESULT="$OPTARG"
			;;
		c)
			IDENTIFIER="$OPTARG"
			;;
		v)
			VERSION="$OPTARG"
			;;
		i)
			PKGDEST="$OPTARG"
			# Create temp directory
			INSTALLTEMP=$(mktemp -d -t mrpkg)
			INSTALLROOT="$INSTALLTEMP"/install_root
			MUNKIPATH="$INSTALLROOT"/usr/local/munki/
			PREFPATH=/Library/Preferences/MunkiReport
			PREFLIGHT=0
			BUILDPKG=1
			;;
		n)
			PREFLIGHT=0
			;;
		h|?)
			usage
			exit
			;;
	esac
done

echo "Preparing ${MUNKIPATH} and ${PREFPATH}"
mkdir -p "$(dirname ${PREFPATH})"
mkdir -p "${MUNKIPATH}munkilib"

echo "BaseURL is ${BASEURL}"

echo "Retrieving munkireport scripts"

cd ${MUNKIPATH}
$CURL "${TPL_BASE}{preflight,postflight,report_broken_client}" --remote-name --remote-name --remote-name \
	&& $CURL "${TPL_BASE}reportcommon" -o "${MUNKIPATH}munkilib/reportcommon.py" \
	&& $CURL "${TPL_BASE}phpserialize" -o "${MUNKIPATH}munkilib/phpserialize.py"

if [ "${?}" != 0 ]
then
	echo "Failed to download all required components!"
	rm -f "${MUNKIPATH}"{preflight,postflight,report_broken_client} \
		"${MUNKIPATH}"munkilib/reportcommon.py
	exit 1
fi

chmod a+x "${MUNKIPATH}"{preflight,postflight,report_broken_client}

# Create preflight.d + download scripts
mkdir -p "${MUNKIPATH}preflight.d"
cd "${MUNKIPATH}preflight.d"
${CURL} "${TPL_BASE}submit.preflight" --remote-name

if [ "${?}" != 0 ]
then
	echo "Failed to download preflight script!"
	rm -f "${MUNKIPATH}preflight.d/submit.preflight"
else
	chmod a+x "${MUNKIPATH}preflight.d/submit.preflight"
fi

# Create postflight.d
mkdir -p "${MUNKIPATH}postflight.d"

# Create preflight_abort.d
mkdir -p "${MUNKIPATH}preflight_abort.d"

echo "Configuring munkireport"
#### Configure Munkireport ####

# Set BaseUrl preference
setpref 'BaseUrl' "${BASEURL}"

# Reset ReportItems array
resetreportpref

# Include module scripts
<?php foreach($install_scripts AS $scriptname => $filepath): ?>

<?php echo "## $scriptname ##"; ?>
echo '+ Installing <?php echo $scriptname; ?>'

<?php echo file_get_contents($filepath); ?>

<?php endforeach; ?>

# Store munkipath when building a package
if [ $BUILDPKG = 1 ]; then
	STOREPATH=${MUNKIPATH}
	MUNKIPATH='/usr/local/munki/'
fi

# Capture uninstall scripts
read -r -d '' UNINSTALLS << EOF

<?php foreach($uninstall_scripts AS $scriptname => $filepath): ?>

<?php echo "## $scriptname ##"; ?>
echo '- Uninstalling <?php echo $scriptname; ?>'

<?php echo file_get_contents($filepath); ?>

<?php endforeach; ?>

EOF

# Restore munkipath when building a package
if [ $BUILDPKG = 1 ]; then
	MUNKIPATH=${STOREPATH}
fi


# If not building a package, execute uninstall scripts
if [ $BUILDPKG = 0 ]; then
	eval "$UNINSTALLS"
	# Remove munkireport version file
	rm -f "${MUNKIPATH}munkireport-"*
fi

if [ $ERR = 0 ]; then

	if [ $BUILDPKG = 1 ]; then

		# Create scripts directory
		SCRIPTDIR="$INSTALLTEMP"/scripts
		mkdir -p "$SCRIPTDIR"

		# Add uninstall script to preinstall
		echo  "#!/bin/bash" > $SCRIPTDIR/preinstall
		echo  "$UNINSTALLS" >> $SCRIPTDIR/preinstall
		chmod +x $SCRIPTDIR/preinstall

		# Add Preference setting commands to postinstall
		echo  "#!/bin/bash" > $SCRIPTDIR/postinstall
		for i in "${PREF_CMDS[@]}";
			do echo $i >> $SCRIPTDIR/postinstall
		done
		chmod +x $SCRIPTDIR/postinstall


		echo "Building MunkiReport v${VERSION} package."
		pkgbuild --identifier "$IDENTIFIER" \
				 --version "$VERSION" \
				 --root "$INSTALLROOT" \
				 --scripts "$SCRIPTDIR" \
				 "$PKGDEST/munkireport-${VERSION}.pkg"

		if [[ $RESULT ]]; then
			defaults write $RESULT version ${VERSION}
			defaults write $RESULT pkg_path "$PKGDEST/munkireport-${VERSION}.pkg"
		fi

	else

		# Set preferences
		echo "Setting preferences"
		for i in "${PREF_CMDS[@]}"; do
			eval $i
		done

		# Set munkireport version file
		touch "${MUNKIPATH}munkireport-${VERSION}"

		echo "Installation of MunkiReport v${VERSION} complete."
		echo 'Running the preflight script for initialization'
		if [ $PREFLIGHT = 1 ]; then
			${MUNKIPATH}preflight
		fi

	fi

else
	echo "! Installation of MunkiReport v${VERSION} incomplete."
fi

if [ "$INSTALLTEMP" != "" ]; then
	echo "Cleaning up temporary directory $INSTALLTEMP"
	rm -r $INSTALLTEMP
fi



exit $ERR
