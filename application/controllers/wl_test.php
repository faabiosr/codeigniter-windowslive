<?php

class Wl_test extends CI_Controller {
    
    public function __construct() {
        parent::__construct();
        
        $this->load->library('wl');
		
		if(!$this->wl->logged_in()){
			$this->wl->login('wl.signin');
		}
    }
    
    public function index(){
		$user = $this->wl->user();
		
		var_dump($user);
    }

}