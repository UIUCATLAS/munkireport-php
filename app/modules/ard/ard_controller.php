<?php

namespace modules\ard;

use munkireport\Module_controller as Module_controller;
use munkireport\View as View;

/**
 * Ard_controller class
 *
 * @package munkireport
 * @author  AvB
 **/
class Ard_controller extends Module_controller
{
    public function __construct()
    {
        $this->module_path = dirname(__FILE__);
    }

    /**
     * Default method
     *
     * @author AvB
     **/
    public function index()
    {
        echo "You've loaded the ard module!";
    }

    /**
     * Retrieve data in json format
     **/
    public function get_data($serial_number = '')
    {
        $obj = new View();

        if (! $this->authorized()) {
            $obj->view('json', array('msg' => 'Not authorized'));
            return;
        }

        $ard = new Ard_model($serial_number);
        $obj->view('json', array('msg' => $ard->rs));
    }
} // END class Ard_controller
