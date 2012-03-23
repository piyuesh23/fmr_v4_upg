<?php
/*
 * Copyright 2007-2011 Charles du Jeu <contact (at) cdujeu.me>
 * This file is part of AjaXplorer.
 *
 * AjaXplorer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AjaXplorer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with AjaXplorer.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://www.ajaxplorer.info/>.
 */
defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * @package info.ajaxplorer.plugins
 * Standard auth implementation, stores the data in serialized files
 */
class hwAuthDriver extends AbstractAuthDriver {

	var $usersSerFile;
	var $driverName = "serial";
  var $context;
  var $ac_url;

	function init($options){
    $this->context = $_GET['context'];
    $this->ac_url = $_GET['ac-url'];
    $referer = $_SERVER['HTTP_REFERER'];

    if(($this->context == '')||($this->ac_url == '')) {
      $url_parts = parse_url($referer);
      $query = $url_parts['query'];
      $query_array = explode('&', $query);
      foreach($query_array as $params) {
        $index = substr($params, 0, strpos($params, '='));
        $value = substr($params, strpos($params, '=')+1);
        $query_param[$index] = $value;
      }
      $this->context = $query_param['context'];
      $this->ac_url = $query_param['ac-url'];
    }

		parent::init($options);
		$this->usersSerFile = AJXP_VarsFilter::filter($this->getOption("USERS_FILEPATH"));
	}

	function performChecks(){
        if(!isset($this->options)) return;
		$usersDir = dirname($this->usersSerFile);
		if(!is_dir($usersDir) || !is_writable($usersDir)){
			throw new Exception("Parent folder for users file is either inexistent or not writeable.");
		}
		if(is_file($this->usersSerFile) && !is_writable($this->usersSerFile)){
			throw new Exception("Users file exists but is not writeable!");
		}
	}

	function listUsers(){
		return AJXP_Utils::loadSerialFile(AJXP_VarsFilter::filter(AJXP_DATA_PATH.'/plugins/auth.serial/users.ser'));
	}

	function userExists($login){
    $users = hwAuthDriver::listUsers();
		if(!is_array($users) || !array_key_exists($login, $users))  return false;
		return true;
	}

	function checkPassword($login, $pass, $seed){
		if($login=='admin') {
		  $userStoredPass = $this->getUserPass($login);
		  if(!$userStoredPass) return false;
		  if($seed == "-1"){ // Seed = -1 means that password is not encoded.
			  $result = $userStoredPass == md5($pass);
		  }else{
			  $result = md5($userStoredPass.$seed) == $pass;
		  }
      return $result;
    }
    if($login!='admin') {
      $suff =  preg_match('/(\w)*-(\w)*-(\w)*/', 	$this->ac_url, $matches);
//      $login_hw = $login;
//      $login = $login.'cshljnls-ac-dev';
      $login_hw = str_replace('_'.$matches[0], '', $login);
      $response = $this->auth_response_hw($login_hw, $pass, $this->context,$this->ac_url);
      if($response == "success")
        return TRUE;
      elseif($response == "fail")
        return FALSE;
    }
	}

	function usersEditable(){
		return true;
	}
	function passwordsEditable(){
		return true;
	}

	function createUser($login, $passwd){
		$users = $this->listUsers();
		if(!is_array($users)) $users = array();
		if(array_key_exists($login, $users)) return "exists";
		if($this->getOption("TRANSMIT_CLEAR_PASS") === true){
			$users[$login] = md5($passwd);
		}else{
			$users[$login] = $passwd;
		}
		AJXP_Utils::saveSerialFile($this->usersSerFile, $users);
	}
	function changePassword($login, $newPass){
		$users = $this->listUsers();
		if(!is_array($users) || !array_key_exists($login, $users)) return ;
		if($this->getOption("TRANSMIT_CLEAR_PASS") === true){
			$users[$login] = md5($newPass);
		}else{
			$users[$login] = $newPass;
		}
		AJXP_Utils::saveSerialFile($this->usersSerFile, $users);
	}
	function deleteUser($login){
		$users = $this->listUsers();
		if(is_array($users) && array_key_exists($login, $users))
		{
			unset($users[$login]);
			AJXP_Utils::saveSerialFile($this->usersSerFile, $users);
		}
	}

	function getUserPass($login){
		if(!$this->userExists($login)) return false;
		$users = $this->listUsers();
		return $users[$login];
	}

/*-- custom code to authenticate users against hw_auth service --*/
  function auth_response_hw($uname, $pass, $context,$ac_url) {
    $post_string = '<?xml version="1.0" encoding="UTF-8"?>
  <ac:runtime-request xmlns:ac="http://schema.highwire.org/Access" xmlns:gen="http://schema.highwire.org/Site/Generator">
  <ac:authenticate-request client-host="171.67.125.228" path="/" protocol="http" server-host="filemanager-dev.highwire.org" server-port="80" method="GET" xml:base="*http://filemanager-dev.highwire.org/*">
  <ac:header name="referer">http://filemanager.highwire.org/</ac:header>
  <ac:cookie name="acceptsCookies">true</ac:cookie>
  <ac:parameter name="username">'.$uname.'</ac:parameter>
  <ac:parameter name="code">'.$pass.'</ac:parameter>
  </ac:authenticate-request>
  <ac:authorize context="'.$context.'" target="roles-task" id="File Manager"/>
  </ac:runtime-request>';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $ac_url);

    // For xml, change the content-type.
    curl_setopt ($ch, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $data = curl_exec($ch);
    $p = xml_parser_create();
    $data = preg_replace('/\\r\\n/', '', $data);
    xml_parse_into_struct($p, $data, $vals, $index);
    xml_parser_free($p);

    $output = "";

    foreach($vals as $val) {
      if($val['tag'] == 'AC:MESSAGE') {
        $name = $val['attributes']['NAME'];
        $module = $val['attributes']['MODULE'];

        if(($name == 'logged-in')&&($module == 'username-password')) {
          return "success";
        }
      }
      elseif ($val['tag'] == 'AC:ERROR') {
        $name = $val['attributes']['NAME'];
        $module = $val['attributes']['MODULE'];

        if(($name == 'login-error')&&($module == 'username-password')) {
          return "fail";
        }
      }
    }
  }

  function logUser($cookie)
	{
	  $context = $_GET['context'];
    $ac_url = $_GET['ac-url'];
    $referer = $_SERVER['HTTP_REFERER'];

    if(($context == '')||($ac_url == '')) {
      $url_parts = parse_url($referer);
      $query = $url_parts['query'];
      $query_array = explode('&', $query);
      foreach($query_array as $params) {
        $index = substr($params, 0, strpos($params, '='));
        $value = substr($params, strpos($params, '=')+1);
        $query_param[$index] = $value;
      }
      $context = $query_param['context'];
      $ac_url = $query_param['ac-url'];
    }
		$confDriver = ConfService::getConfStorageImpl();
		$authDriver = ConfService::getAuthDriverImpl();
    if(!empty($cookie)) {
      $user_id = hwAuthDriver::hw_auth_cookie($cookie, $ac_url);
    }
    if($user_id != "admin") {
      $ac_name = substr($ac_url, strpos($ac_url, "http://")+7, strpos($ac_url, '.')-(strpos($ac_url, "http://")+7));
      $user_id .= "_".$ac_name;
    }
    error_log('uid'.$user_id);
    if (hwAuthDriver::userExists($user_id)) {
      $user = $confDriver->createUserObject($user_id);
    }
    else {
      return 0;
    }

		if($authDriver->isAjxpAdmin($user_id)){
			$user->setAdmin(true);
		}
		if($user->isAdmin())
		{
			$user = AuthService::updateAdminRights($user);
		}
		else{
			if(!$user->hasParent() && $user_id != "guest"){
				//$user->setRight("ajxp_shared", "rw");
			}
		}
		$_SESSION["AJXP_USER"] = $user;
		if($authDriver->autoCreateUser() && !$user->storageExists()){
			$user->save();
		}
		AJXP_Logger::logAction("Log In");
		return 1;
	}

  function hw_auth_cookie($cookie, $ac_url) {
    error_log('cookie'.$cookie);
    error_log("ac_url".$ac_url);
    $post_string = '<?xml version="1.0" encoding="UTF-8"?>
<ac:runtime-request xmlns:ac="http://schema.highwire.org/Access" xmlns:gen="http://schema.highwire.org/Site/Generator">
<ac:authenticate-request client-host="171.67.125.228" path="/" protocol="http" server-host="filemanager-dev.cshlp.org" server-port="80" method="GET" xml:base="*http://filemanager-dev.cshlp.org/*"><ac:header name="referer">http://filemanager-dev.cshlp.org/</ac:header><ac:cookie name="acceptsCookies">true</ac:cookie><ac:cookie name="login">'.$cookie.'</ac:cookie></ac:authenticate-request><ac:authorize context="protocols" target="roles-task" id="File Manager"/></ac:runtime-request>';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $ac_url);

    // For xml, change the content-type.
    curl_setopt ($ch, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $data = curl_exec($ch);
        error_log("response".$data);
    $p = xml_parser_create();
    $data = preg_replace('/\\r\\n/', '', $data);
    xml_parse_into_struct($p, $data, $vals, $index);
    xml_parser_free($p);

    $output = "";
    foreach($vals as $val) {
      if(($val['tag'] == 'AC:CREDENTIALS')&&($val['attributes']['METHOD'] == 'username')) {
        $name = $val['value'];
      }
    }
    if(!empty($name)) {
      return $name;
    }
    else {
      return "fail";
    }
  }
}
?>
