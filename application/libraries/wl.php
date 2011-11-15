<?php

class wl {
		
	private $_obj;
	private $_api_key;
	private $_api_secret;
	private $_api_url;
	private $_errors = array();
	private $_enable_debug = FALSE;
	private $_conn;
	private $_wl_session;
		
	public function __construct() {
		$this->_obj =& get_instance();
		
		$this->_obj->load->library('session');
		$this->_obj->load->config('wl');
		$this->_obj->load->helper('url');
		
		$this->_api_url = $this->_obj->config->item('wl_api_url');
		$this->_api_key = $this->_obj->config->item('wl_client_id');
		$this->_api_secret = $this->_obj->config->item('wl_client_secret');
		
		$this->_conn = new wlConnection();
		$this->_wl_session = new wlSession();
	}
		
	public function logged_in(){
		return $this->_wl_session->logged_in();
	}
	
	public function login($scope = NULL){
		return $this->_wl_session->login($scope);
	}
	
	public function logout(){
		return $this->_wl_session->logout();
	}
	
	public function login_url($scope = NULL){
		return $this->_wl_session->login_url($scope);
	}
	
	public function user(){
		return $this->_wl_session->get();
	}
	
	public function set_callback($url){
		return $this->_wl_session->set_callback($url);
	}
	
	public function errors(){
		return $this->_errors;			
	}
	
	public function last_error(){
		
		if(count($this->_errors) === 0){
			return NULL;
		}
		
		return $this->_errors[count($this->_errors) - 1];			
	}
	
	public function append_token($url){
	
		return $this->_wl_session->append_token($url);			
	}
	
	public function enable_debug($debug = TRUE){
		$this->_enable_debug = (bool) $debug;
	}
	
	public function call($method,$uri,$data = array()){
		$response = FALSE;
		
		try{
			switch($method){
				case 'get':
						$response = $this->_conn->get($this->append_token($this->_api_url.$uri));
					break;
				case 'post':
						$response = $this->_conn->post($this->append_token($this->_api_url.$uri),$data);
					break;
			}
		
		}
		catch(wlException $e){
			$this->_errors[] = $e;
			
			if($this->_enable_debug){
				echo $e;
			}				
		}
		
		return $response;
	}
} 
	
class wlConnection {
	
	// Allow multi-threading.
	
	private $_mch = NULL;
	private $_properties = array();
	
	function __construct()
	{
		$this->_mch = curl_multi_init();
		
		$this->_properties = array(
			'code' 		=> CURLINFO_HTTP_CODE,
			'time' 		=> CURLINFO_TOTAL_TIME,
			'length'	=> CURLINFO_CONTENT_LENGTH_DOWNLOAD,
			'type' 		=> CURLINFO_CONTENT_TYPE
		);
	}
	
	private function _initConnection($url)
	{
		$this->_ch = curl_init($url);
		curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, TRUE);
	}
		
	public function get($url, $params = array())
	{
		if ( count($params) > 0 )
		{
			$url .= '?';
		
			foreach( $params as $k => $v )
			{
				$url .= "{$k}={$v}&";
			}
			
			$url = substr($url, 0, -1);
		}
		
		$this->_initConnection($url);
		$response = $this->_addCurl($url, $params);

		return $response;
	}
	
	public function post($url, $params = array())
	{
		// Todo
		$post = '';
		
		foreach ( $params as $k => $v )
		{
			$post .= "{$k}={$v}&";
		}
		
		$post = substr($post, 0, -1);
		
		$this->_initConnection($url, $params);
		curl_setopt($this->_ch, CURLOPT_POST, 1);
		curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $post);
		
		$response = $this->_addCurl($url, $params);

		return $response;
	}
	
	private function _addCurl($url, $params = array())
	{
		$ch = $this->_ch;
		
		$key = (string) $ch;
		$this->_requests[$key] = $ch;
		
		$response = curl_multi_add_handle($this->_mch, $ch);

		if ( $response === CURLM_OK || $response === CURLM_CALL_MULTI_PERFORM )
		{
			do {
				$mch = curl_multi_exec($this->_mch, $active);
			} while ( $mch === CURLM_CALL_MULTI_PERFORM );
			
			return $this->_getResponse($key);
		}
		else
		{
			return $response;
		}
	}
	
	private function _getResponse($key = NULL)
	{
		if ( $key == NULL ) return FALSE;
		
		if ( isset($this->_responses[$key]) )
		{
			return $this->_responses[$key];
		}
		
		$running = NULL;
		
		do
		{
			$response = curl_multi_exec($this->_mch, $running_curl);
			
			if ( $running !== NULL && $running_curl != $running )
			{
				$this->_setResponse($key);
				
				if ( isset($this->_responses[$key]) )
				{
					$response = new wlResponse( (object) $this->_responses[$key] );
					
					if ( $response->__resp->code !== 200 )
					{
						$error = $response->__resp->code.' | Request Failed';
						
						if ( isset($response->__resp->data->error->type) )
						{
							$error .= ' - '.$response->__resp->data->error->type.' - '.$response->__resp->data->error->message;
						}
						
						throw new wlException($error);
					}
					
					return $response;
				}
			}
			
			$running = $running_curl;
			
		} while ( $running_curl > 0);
		
	}
	
	private function _setResponse($key)
	{
		while( $done = curl_multi_info_read($this->_mch) )
		{
			$key = (string) $done['handle'];
			$this->_responses[$key]['data'] = curl_multi_getcontent($done['handle']);
			
			foreach ( $this->_properties as $curl_key => $value )
			{
				$this->_responses[$key][$curl_key] = curl_getinfo($done['handle'], $value);
				
				curl_multi_remove_handle($this->_mch, $done['handle']);
			}
		}
	}
}
	
class wlResponse {
	
	private $__construct;

	public function __construct($resp)
	{
		$this->__resp = $resp;

		$data = json_decode($this->__resp->data);
		
		if ( $data !== NULL )
		{
			$this->__resp->data = $data;
		}
	}

	public function __get($name)
	{
		if ($this->__resp->code < 200 || $this->__resp->code > 299) return FALSE;

		$result = array();

		if ( is_string($this->__resp->data ) )
		{
			parse_str($this->__resp->data, $result);
			$this->__resp->data = (object) $result;
		}
		
		if ( $name === '_result')
		{
			return $this->__resp->data;
		}
		
		return $this->__resp->data->$name;
	}
}

class wlException extends Exception {
	
	function __construct($string)
	{
		parent::__construct($string);
	}
	
	public function __toString() {
		return "exception '".__CLASS__ ."' with message '".$this->getMessage()."' in ".$this->getFile().":".$this->getLine()."\nStack trace:\n".$this->getTraceAsString();
	}
}
	
class wlSession {
		
	private $_obj;
	private $_api_key;
	private $_api_secret;
	private $_api_url;
	private $_oauth_url = 'https://oauth.live.com/';
	private $_token_url = 'token';
	private $_user_url = 'me';
	private $_conn;
	
	public function __construct() {
		$this->_obj =& get_instance();
		
		$this->_obj->config->load('wl');
		
		$this->_api_key = $this->_obj->config->item('wl_client_id');
		$this->_api_secret = $this->_obj->config->item('wl_client_secret');
		$this->_api_url = $this->_obj->config->item('wl_api_url');
		$this->_user_url = $this->_obj->config->item('wl_api_url').$this->_user_url;

		$this->_set('scope',$this->_obj->config->item('wl_default_scope'));
			
		$this->_conn = new wlConnection();
		
		if(!$this->logged_in()){
			$this->set_callback();
		}
	}
	
	public function logged_in(){
		return ($this->get() === NULL) ? FALSE : TRUE;
	}
	
	public function logout(){
		$this->_unset('token');
	}
	
	public function login_url($scope = NULL){
		
		$url = $this->_oauth_url.'authorize?client_id='.$this->_api_key.'&response_type=code&redirect_uri='.urlencode($this->_get('callback'));
		
		if(empty($scope)){
			$scope = $this->_get('scope');
		}
		else{
			$this->_set('scope',$scope);
		}
		
		if(!empty($scope)){
			$url .= '&scope='.$scope;
		}
		
		return $url;
	}
	
	public function login($scope = NULL){
		$this->logout();
		
		if(!$this->_get('callback')){
			$this->_set('callback',current_url());
		}
		
		$url = $this->login_url($scope);
		
		return redirect($url);
	}
	
	public function get(){
	
		$token = $this->_find_token();
		
		if(empty($token)){
			return NULL;
		}
		
		try{
			$user = $this->_conn->get($this->_user_url.'?'.$this->_token_string());				
		}
		catch(wlException $e){
			$this->logout();
			return NULL;
		}
		
		return $user;
	}
	
	private function _find_token(){                
		$token = $this->_get('token');								
		
		if(!empty($token)){
		
			$token_data = unserialize($token);
			
			if(!empty($token_data->expires) && intval($token_data->expires) >= time()){
				return $this->logout();
			}
			
			$this->_set('token',$token);
			return $this->_token_string();
		}
		
		if(!isset($_GET['code'])){
			return $this->logout();
		}
		
		if(!$this->_get('callback')){
			$this->_set('callback',current_url());
		}
		
		$token_url = $this->_oauth_url.$this->_token_url.'?client_id='.$this->_api_key."&client_secret=".$this->_api_secret."&code=".$_GET['code'].'&redirect_uri='.urlencode($this->_get('callback')).'&grant_type=authorization_code';

		try{
			$token = $this->_conn->get($token_url);
		}
		catch(wlException $e){
			$this->logout();
			redirect($this->_strip_query());
			return NULL;
		}
		
		$this->_unset('callback');
		
		if($token->access_token){
		
			if(!empty($token->expires)){
				$token->expires = strval(time() + intval($token->expires));
			}
			
			$this->_set('token', serialize($token));
			redirect($this->_strip_query());
		}
		
		return $this->_token_string();
	}
	
	private function _get($key){			
		return $this->_obj->session->userdata('_wl_'.$key);
	}
	
	private function _set($key,$data){
		$this->_obj->session->set_userdata('_wl_'.$key,$data);
	}
	
	private function _unset($key){
		$this->_obj->session->unset_userdata('_wl_'.$key);
	}
	
	public function set_callback($url = NULL){
		$this->_set('callback',$this->_strip_query($url));
	}
	
	private function _token_string(){
	
		$token = $this->_get('token');
		$token_data = unserialize($token);
	
		return 'access_token='.$token_data->access_token;			
	}

	public function append_token($url){
	
		if($this->_get('token')){
			$url .= '?'.$this->_token_string();
		}
		
		return $url;
	
	}
	
	private function _strip_query($url = NULL){
	
		if($url === NULL){
			$url = (empty($_SERVER['HTTPS'])) ? 'http' : 'https';
			$url .= '://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];					
		}
		
		$parts = explode('?',$url);
		
		return $parts[0];				
	}
} 