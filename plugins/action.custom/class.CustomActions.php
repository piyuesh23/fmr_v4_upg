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
 * Simple non-fonctionnal plugin for demoing pre/post processes hooks
 */
class CustomActions extends AJXP_Plugin {

    /**
     * @param DOMNode $contribNode
     * @return void
     */
  public function switchAction($action, $httpVars, $fileVars) {
    $this->repository = ConfService::getRepository();
		$this->urlBase = "ajxp.fs"."://".$this->repository->getId();
    $this->repo_path = $this->repository->options['PATH'];
    $selection = new UserSelection();
    $selection->initFromHttpVars($httpVars);
		$mess = ConfService::getMessages();
    switch($action) {
//------------------------------------
//     CUSTOM actions for highwire
//------------------------------------
      case "check_site":
        $reponame = $this->repository->display;
        if(strpos($reponame, 'site') == 0)
          print "true";
        else
          print "false";
      break;

      case "check_symlink":
        $filepath = $this->repo_path.$httpVars['file'];
        $flag = fsAccessDriver::check_symlink($filepath);
        if(!$flag) {
          print "false";
        }
        else print "true";
      break;

      case "get_repoid":
        print $this->repository->getId();
      break;

      case "get_metadata" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        print $metadata['preview_url'][0];

      break;

      case "get_topUrl":
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        $top_url = $metadata['top_url'][0];
        return $top_url;
      break;

      case "get_pubname" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        return $metadata['abbr'][0];
      break;

      case "get_jname" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        return $metadata['home_url'][0].'|'.$metadata['sitecode'][0];
      break;

      case "get_preview_repo" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        return $metadata['preview_repo'][0];
      break;

      case "get_publish_url" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        return $metadata['publish_url'][0];
      break;

//create a file outside AJXP -- for creating log files if they don't exist.
      case "mkfile_external";
        $date = fsAccessDriver::getTime();

        $filename_log = 'filemgr'.$date['wday'].'.log';
        if(!empty($httpVars['repoid'])) {
               $repoObject = ConfService::getRepositoryById($httpVars['repoid']);
          $reponame = $repoObject->display;
        }
        else {
          $reponame = $this->repository->display;
        }
//check if the file doesn't belong to any repo
        if($reponame != "Default Files"){
          $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$reponame.'.xml';

          $metadata = fsAccessDriver::parse_xml($filepath_xml);
          $sitecode = $metadata['sitecode'][0];
          $dest = '/logs/'.$sitecode.'/'.$filename_log;
        }
        else {
          $dest = '/logs/'.$filename_log;
        }
//Create sitecode specific logs directory if it doesn't exist
        if(!is_dir('/logs/'.$sitecode)){
          mkdir('/logs/'.$sitecode, 777);
        }
//create logfile if it doesn't exist
        if(!file_exists($dest)) {
          $fp=fopen($dest,"w");
          fclose($fp);
        }
      break;

//create backup when file is being saved
      case "copy_backup" :
        $src = $httpVars['src'];
        $dest = $httpVars['dir'];
        $source_check = $this->urlBase.$src; //absolute filepath of file being moved.

//check if an image has been added from a differnt repo while using wysiwyg
        if(!empty($httpVars['repoid']))
          $source_check = 'ajxp.fs://'.$httpVars['repoid'].$src;

        $this->hw_backup_file($this->initPath($source_check), $action="save");
        break;
      break;

/*--write data to logfile. --*/
      case "put_content_external" :
// Load "code" variable directly from POST array, do not "securePath" or "sanitize"...
        $date = fsAccessDriver::getTime();
        $filename_log = 'filemgr'.$date['wday'].'.log';
        $filename_orig = $httpVars['original_filename'];
        if(!empty($httpVars['repoid'])) {
               $repoObject = ConfService::getRepositoryById($httpVars['repoid']);
          $reponame = $repoObject->display;
          $repo_path = $repoObject->options['PATH'];
        }
        else {
          $reponame = $this->repository->display;
          $repo_path = $this->repository->options['PATH'];
        }
        if($reponame != "Default Files"){
          $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$reponame.'.xml';
          $metadata = fsAccessDriver::parse_xml($filepath_xml);
          $sitecode = $metadata['sitecode'][0];
          $dest = '/logs/'.$sitecode.'/'.$filename_log;
        }
        else {
          $dest = '/logs/'.$filename_log;
        }

        if(!is_file($dest) || !fsAccessDriver::isWriteable($dest, "file")){
          header("Content-Type:text/plain");
          print((!fsAccessDriver::isWriteable($dest, "file")?"1001":"1002"));
          return ;
        }

        $date_human = time();
        $code = $date_human." update ". $repo_path.$filename_orig.PHP_EOL;
        $fp=fopen($dest,"a");
        fputs ($fp,$code);
        fclose($fp);
        header("Content-Type:text/plain");
        print($mess[115]);
      break;

//Create a backup dir if it doesn't exist
      case "mkdir_backup";
        $messtmp="";
        $dirname=AJXP_Utils::decodeSecureMagic($httpVars["dirname"], AJXP_SANITIZE_HTML_STRICT);
        $dirname = substr($dirname, 0, ConfService::getConf("MAX_CHAR"));
        if(!empty($httpVars['repoid'])) {
          if(!is_dir('ajxp.fs://'.$httpVars['repoid'].$httpVars['dir'].'/'.$httpVars['dirname'])) {
            mkdir('ajxp.fs://'.$httpVars['repoid'].$httpVars['dir'].'/'.$httpVars['dirname']);
          }
        }
        else {
          if(!is_dir($this->urlBase.$dir.'/'.$httpVars['dirname'])) {
            $error = fsAccessDriver::mkDir($dir, $httpVars['dirname']);
            if(isSet($error)){
              throw new AJXP_Exception($error);
            }

            $messtmp.="$mess[38] ".SystemTextEncoding::toUTF8($dirname)." $mess[39] ";
            if($dir=="") {$messtmp.="/";} else {$messtmp.= SystemTextEncoding::toUTF8($dir);}
              $logMessage = $messtmp;
              $pendingSelection = $dirname;
              $reloadContextNode = true;
              AJXP_Logger::logAction("Create Dir", array("dir"=>$dir."/".$dirname));
          }
        }
      break;

      case "put_content_ck":
        if(!isset($httpVars["content"])) break;
// Load "code" variable directly from POST array, do not "securePath" or "sanitize"...
        $code = $httpVars["content"];
        $file = $selection->getUniqueFile($httpVars["file"]);
        AJXP_Logger::logAction("Online Edition", array("file"=>$file));
        if(isSet($httpVars["encode"]) && $httpVars["encode"] == "base64"){
            $code = base64_decode($code);
        }else{
          $code = SystemTextEncoding::magicDequote($code);
          $code=str_replace("&lt;","<",$code);
        }
        if(!empty($httpVars['repoid'])) {
          $fileName = 'ajxp.fs://'.$httpVars['repoid'].$file;
        }
        else {
          $fileName = $this->urlBase.$file;
        }
        if(!is_file($fileName) || !fsAccessDriver::isWriteable($fileName, "file")){
          header("Content-Type:text/plain");
          print((!fsAccessDriver::isWriteable($fileName, "file")?"1001":"1002"));
          return ;
        }
        $fp=fopen($fileName,"w");
        fputs ($fp,$code);
        fclose($fp);
        header("Content-Type:text/plain");
        print($mess[115]);
      break;
//------------------------------------
//     CUSTOM actions for highwire
//------------------------------------
    }
  }

//-------------------------------------------------
//	CUSTOM helper functions used in custom actions
//-------------------------------------------------

/**
 * @description -- creates a backup of file on save, copy, move and delete actions
 * @params
 * $source_fullpath -- absolute path of the file whose backup is being created
 * @return
 * success/fail
 */
  function hw_backup_file($source_fullpath, $action=NULL) {
    if(!(file_exists($source_fullpath))) {
      throw new AJXP_Exception("File has been deleted. Please refresh and try again");
    }
    else {
      $filepath_backup = $this->hw_backup_fpath_create($source_fullpath);
      $dirpath_backup = substr($filepath_backup, 0, strrpos($filepath_backup, '/'));
      fsAccessDriver::delete_old_files($dirpath_backup);
      if(!file_exists($filepath_backup)) {
        $handle_rd = fopen($filepath_backup, 'w');
        fclose($handle_rd);
      }

      if(copy($source_fullpath, $filepath_backup)) {
        return "success";
      }
      else {
        return "fail";
      }
    }
  }

/**
 * @description -- create the backup filepath.
 * @params
 * $source_fullpath -- absolute path for the file.
 * @return
 * $filename_backup -- backup filepath with timestamp added to source filename.
 */

  function hw_backup_fpath_create($source_fullpath) {
    $source_parts = explode('/', $source_fullpath);
    $count_source_parts = count($source_parts);
    $filename = $source_parts[$count_source_parts-1];

    //check if filename has extension and accordingly assign extension to backup filename.
    if(strpos($filename, '.')) {
      $filename_without_extension = substr($filename, 0, strrpos($filename, '.'));
      $extension = substr($filename, strrpos($filename, '.'));
    }
    else {
      $filename_without_extension = $filename;
      $extension = '';
    }

    $date = $this->getTime();

    $filename_backup = $filename_without_extension.'_'.$date['year'].$date['mon'].$date['mday'].'-'.$date['hours'].$date['minutes'].$date['seconds'].$extension;

    $source_parts[$count_source_parts-1] = 'backup';
    $this->createdir_backup($source_parts, $count_source_parts);
    $source_parts[$count_source_parts] = $filename_backup;
    $filepath_backup = implode('/', $source_parts);

    return $filepath_backup;
  }


/**
 * @description -- format timestamp to human readable format --used for backup names
 */

  function getTime() {
    $timestamp = time();
    $date = getdate($timestamp);

    if(strlen($date['mday']) == 1){
      $date['mday'] = '0'.$date['mday'];
    }
    if(strlen($date['mon']) == 1){
      $date['mon'] = '0'.$date['mon'];
    }
    if(strlen($date['hours']) == 1){
      $date['hours'] = '0'.$date['hours'];
    }
    if(strlen($date['minutes']) == 1){
      $date['minutes'] = '0'.$date['minutes'];
    }
    if(strlen($date['seconds']) == 1){
      $date['seconds'] = '0'.$date['seconds'];
    }

    return $date;
  }

/**
 * @description -- returns real path from the url
 * @params
 * $url_path -- url path that needs to be converted into real path
 * @returns
 * $path_real -- real path for a file
 */

 function initPath($url_path) {
   $path_parts = parse_url($url_path);
   $repoId = $path_parts['host'];
   $repoObject = ConfService::getRepositoryById($repoId);
   $path_real = realpath($repoObject->getOption("PATH")).$path_parts["path"];
   return $path_real;
 }

/**
 * @description -- checks if backup directory exists or no and accordingly creates it
 * @params
 * $source_parts -- array having parts of source path
 */

 function createdir_backup($source_parts, $count) {
   $backup_dir = implode('/', $source_parts);

   if(!is_dir($backup_dir)) {
     mkdir($backup_dir);
   }
   else {
     //do something
   }
 }
}
?>
