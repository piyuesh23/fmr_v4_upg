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
class PluginPublish extends AJXP_Plugin {

    /**
     * @param DOMNode $contribNode
     * @return void
     */
  public function switchAction($action, $httpVars, $fileVars) {
    $this->repository = ConfService::getRepository();
		$this->urlBase = $wrapperData["protocol"]."://".$this->repository->getId();
    $this->repo_path = $this->repository->options['PATH'];
    switch($action) {
      case "publish":
    		$selection = new UserSelection();
        $selection->initFromHttpVars($httpVars);
        $selectedFiles = $selection->getFiles();
        error_log(print_r($selectedFiles, TRUE));
        $this->publish_check($selectedFiles);
      break;
    }
  }

  function human_readable($file) {
    if(strrpos($file, '.') === false) {
      $time_file = substr($file, strrpos($file, '_')+1);
    }
    else {
      $time_file = substr($file, strrpos($file, '_')+1);
      $time_file = substr($time_file, 0, strrpos($time_file, '.'));
    }

    $year = substr($time_file, 0, 4);
    $mon = substr($time_file, 4, 2);
    $day = substr($time_file, 6, 2);

    $hr = substr($time_file, 9, 2);
    $min = substr($time_file, 11, 2);
    $sec = substr($time_file, 13, 2);

    $filename_time = $year.'-'.$mon.'-'.$day.' '.$hr.':'.$min.':'.$sec;
    return $filename_time;
  }

  function getFiles($dir) {
    $handle = opendir($dir);
    $i = 0;
    while (false !== ($file_dir = readdir($handle))) {
      if(($file_dir != 'backup')&&($file_dir != '.')&&($file_dir != '..')) {
        $files[$i] = $file_dir;
        $i++;
      }
    }
    return $files;
  }

  function publish_check($selectedfiles) {
    $this->insert('',$selectedfiles, &$a);
    $flag_status = 0;
    while($this->check_status($a) == 0) {
      for($i = 0; $i < count($a); $i++) {
        if(($this->check_dir($a[$i][0]) == 1)&&($a[$i][1] == 0)) {
          $files = $this->getFiles($a[$i][0]);
          if(empty($files)) {
            error_log($this->repo_path);
            $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
            $metadata = $this->parse_xml($filepath_xml);
            $prod_dir = $metadata['prod_dir'][0];
            error_log('prod_dir'.$prod_dir);
            $dest = str_replace($this->repo_path, $prod_dir, $a[$i][0]);
            if(!(is_dir($dest))) {
              $dest_dir = substr($dest, 0, strrpos($dest, '/'));
              $dest_fname = substr($dest, strrpos($dest, '/')+1);
                error_log("mkdir_publish_check".$dest);
                $this->makedir($dest, 0777);
            }
          }
          $this->insert($a[$i][0],$files, &$a);
          $a[$i][1] = 1;
        }
        else {
          if($a[$i][1] == 0) {
            $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
            error_log($filepath_xml);
            $metadata = $this->parse_xml($filepath_xml);
            $prod_dir = $metadata['prod_dir'][0];
            $dest = str_replace($this->repo_path, $prod_dir, $a[$i][0]);
            error_log($dest);
            $dest_dir = substr($dest, 0, strrpos($dest, '/'));
            error_log($dest_dir);
            $dest_fname = substr($dest, strrpos($dest, '/')+1);
            error_log($dest_fname);
            if(is_dir($dest_dir)) {
              $files = $this->getFiles($dest_dir);
              if((is_array($files))&&(in_array($dest_fname, $files))) {
                if(is_dir($dest_dir.'/'.$dest_fname)) {
                  throw new AJXP_Exception("A folder with name " . $dest_fname . " already exists on production--cannot have folder and file with same name in a directory.");
                }
                else {
                  error_log("publishinf file".$a[$i][0]);
                  $this->publish($a[$i][0]);
                }
              }
              else {
                error_log("publishinf file".$a[$i][0]);
                $this->publish($a[$i][0]);
              }
            }
            else {
              error_log("publishinf file".$a[$i][0]);
              $this->publish($a[$i][0]);
            }
            $a[$i][1] = 1;
          }
        }
      }
    }
  }

  function insert($dir, $files, &$a){
    $len = count($a);
    for($i = 0; $i<=count($files); $i++) {
      if(!($this->value_array_exists($files[$i], $a))) {
        if($dir == '') {
          $a[$len][0] = $this->repo_path.$files[$i];
          $a[$len][1] = 0;
        }
        else {
          $a[$len][0] = $dir.'/'.$files[$i];
          $a[$len][1] = 0;
        }
        $len++;
      }
    }
    return $a;
  }

  function check_status($a) {
    $flag = 1;
    for($i = 0; $i<count($a); $i++) {
      $flag = (($flag)&&($a[$i][1]));
    }
    return $flag;
  }

  function check_dir($filename) {
    if(is_dir($filename)) {
      return 1;
    }
    else
      return 0;
  }

  function parse_xml($filepath_xml) {
    error_log($filepath_xml);
    if(!file_exists($filepath_xml)) {
      error_log("xml file doesn't exist");
      return;
    }

    if(strpos($filepath_xml, 'Default files')) {
      return;
    }
    $xml = simplexml_load_file($filepath_xml);

    foreach($xml->children() as $child) {
      $index = $child->getName();
      $metadata[$index] = $child;
    }
    return $metadata;
  }

  function publish($file) {
    $file = str_replace($this->repo_path, '', $file);
    if(!file_exists($this->repo_path.$file)) {
      throw  new AJXP_Exception("File has already been deleted.Please refresh your display.");
      break;
    }
    $file_parts = explode('/', $file);
    error_log(print_r($file_parts, TRUE));
    $repo_fullpath = $this->repo_path;

    $reponame = $this->repository->display;

    $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.'metadata_'.$reponame.'.xml';

    $metadata = $this->parse_xml($filepath_xml);
    $document_root = $metadata['prod_dir'][0];
    $sitecode = $metadata['sitecode'][0];
    $len = count($file_parts);
    $dest_path = $document_root.$file;

    $dest_dir = $document_root.substr($file, 0, strrpos($file, '/'));
    if(!(is_dir($dest_dir))) {
      if(is_file($dest_dir)) {
        throw new AJXP_Exception("A file with name " . $dest_fname . " already exists on production--cannot have folder and file with same name in a directory.");
      }
      else {
        $this->makedir($dest_dir, 0777);
      }
    }

    if(!file_exists($dest_path)){
      $fp = fopen($dest_path, 'w') or die("can't open file");
        fclose($fp);
      error_log('file doesnot exist '.$dest_path.". Created new file".$dest_path.".");
    }
    if($this->check_perms($dest_path) == 0) {
      error_log("file not Writable ".$dest_path);
      throw new AJXP_Exception("file not Writable ".$dest_path);
      break;
    }

    if($this->check_dir($dest_path) == 1) {
      error_log($dest_path." is a directory and not a file");
      throw new AJXP_Exception($dest_path." is a directory and not a file");
      break;
    }

    $check_preview = $this->check_preview($dest_path);

    /*if(!$check_preview) {

      break;

    }*/

    $backup_repo = $this->repo_path.'/'.substr($file, 0, strrpos($file, '/')).'/backup';
    if(is_dir($backup_repo)) {
      $this->delete_old_files($backup_repo);
    }
    $backup_path_prod = $document_root.substr($file, 0, strrpos($file, '/')).'/backup';
    if(is_dir($backup_path_prod)) {
      $this->delete_old_files($backup_path_prod);
    }
    $backup_path = $this->repo_path.'/'.substr($file, 0, strrpos($file, '/')).'/backup';

    $filename = $file_parts[$len - 1];

//    $extension = substr($filename, strrpos($filename, '.') + 1);

//    $filename = substr($filename, 0, strrpos($filename, '.'));

    if(strpos($filename, '.')) {
      $fname = substr($filename, 0, strrpos($filename, '.'));

      $extension = substr($filename, strrpos($filename, '.'));
    }
    else {
      $fname = $filename;

      $extension = '';
    }

    $date = $this->getTime();

    $filename_timestamp = $fname.'_'.$date['year'].$date['mon'].$date['mday'].'-'.$date['hours'].$date['minutes'].$date['seconds'].$extension;

    $backup_path_prod = $document_root.substr($file, 0, strrpos($file, '/')).'/backup';

    if(is_dir($backup_path_prod)){
      if(!is_dir($document_root.$file)) {
        if($this->copyFile($document_root.$file, $backup_path_prod.'/'.$filename_timestamp)){
          error_log("file backed-up successfully");
        }
      }
    }
    else{
      error_log("mkdir_publish".$backup_path_prod);
      if(mkdir($backup_path_prod)) {
        if(!is_dir($document_root.$file)) {
          if($this->copyFile($document_root.$file, $backup_path_prod.'/'.$filename_timestamp)){
            error_log("file backed-up successfully--after creating backup folder");
          }
        }
      }
    }
    if(!(is_dir($document_root.$file))) {
      $this->copyFile($this->repo_path.$file, $document_root.$file);
      $this->replace($document_root.$file, '/preview_', '/');
      $status = $this->logFile($file, $sitecode, $document_root, 'update');
      if($status == false) {
        print "error";
      }
      $webapp_name = $metadata['webapp'][0];
//      exec( "ssh lions.stanford.edu /highwire/local/journalsys/publishingbin/clear-cache.pl ".$webapp_name);
    }
  }

  function value_array_exists($file, $a) {
    error_log("exists".$file);
    error_log(count($a));
    for($i = 0; $i<=count($a); $i++) {
      if($a[$i][0] == $file) {
        return 1;
      }
    }
    return 0;
  }

  function makedir($dir, $perms) {
    $reponame = $this->repository->display;
    $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.'metadata_'.$reponame.'.xml';
    $metadata = $this->parse_xml($filepath_xml);
    $document_root = $metadata['prod_dir'][0];
    $rel_path = str_replace($document_root, '', $dir);
    $path_array = explode('/', $rel_path);
    $temp = $document_root;
    for ($i = 0; $i<count($path_array); $i++) {
      $dirs[$i] = $temp.'/'.$path_array[$i];
      $temp = $dirs[$i];
    }

    foreach($dirs as $dir) {
      if(!is_dir($dir)) {
        error_log('dir-makedir'.$dir);
        mkdir($dir, 0777);
      }
    }
  }

  function check_perms($filename) {
    if(is_writable($filename))
      return 1;
    else
      return 0;
  }

  function check_preview($filename) {
    $pos = strpos($filename, 'preview_');
    return $pos;
  }

  function delete_old_files($backup_repo) {
//    $filename = '/var/www/filemanager/files/test/test.txt';
   $handle = opendir($backup_repo);
   $day = time();
   $curr_day = date("z", $day);
   $curr_year = date("Y", $day);
   while($file = readdir($handle)){
     if(($file!='.')&&($file!='..')) {
       $nums = date("z", filemtime($backup_repo.'/'.$file));
       $nums_year = date("Y", filemtime($backup_repo.'/'.$file));
       if($curr_year != $nums_year) {
         $diff_days = ((($curr_year-$nums_year)-1)*365) + ((365-$nums)+$curr_day);
       }
       else {
         $diff_days = $curr_day - $nums;
       }
       if($diff_days > 120) {
         error_log($backup_repo.'/'.$file);
         unlink($backup_repo.'/'.$file);
       }
     }
   }
  }

  function copyFile($src, $dest) {
    return copy($src, $dest);
  }

  function logFile($file, $sitecode, $path, $action) {
    $logfile = '/logs/'.$sitecode.'/';
    if(!is_dir('/logs')) {
      error_log($action);
      return false;
    }
    if(!is_dir($logfile)) {
      error_log('mkdir_logfile'.$logFile);
      mkdir($logfile, 0777);
    }
    $date = getdate(time());

    $fname = 'filemgr'.$date['wday'].'.log';

    $code = time().' '.$action.' '.$path.$file;
    error_log($logfile.$fname);
    $fp=fopen($logfile.$fname,"a");
		fputs ($fp,$code.PHP_EOL);
		fclose($fp);
		header("Content-Type:text/plain");
    return true;
  }

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

  function replace($filename, $exp, $replace) {
    error_log('replace');
    $handle_rd = fopen($filename, 'r+');
    if($handle_rd) {
      $i = 0;
      while (($buffer = fgets($handle_rd, 4096)) !== false) {
        $data[$i] = $buffer;
        $replacement[$i] = str_replace($exp, $replace, $buffer);
        $i++;
      }
    }
    fclose($handle_rd);
        error_log(print_r($data, TRUE));
    error_log(print_r($replacement, TRUE));
    $handle_fw = fopen($filename, 'w');
    $i = 0;
    if($handle_fw) {
      for($i = 0; $i<count($replacement); $i++){
        fwrite($handle_fw, $replacement[$i]);
      }
    }

    fclose($handle_fw);
  }

}
?>
