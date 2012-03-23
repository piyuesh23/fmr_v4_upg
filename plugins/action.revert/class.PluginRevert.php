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
class PluginRevert extends AJXP_Plugin {

    /**
     * @param DOMNode $contribNode
     * @return void
     */
  public function switchAction($action, $httpVars, $fileVars) {
    $this->repository = ConfService::getRepository();
		$this->urlBase = $wrapperData["protocol"]."://".$this->repository->getId();
    $this->repo_path = $this->repository->options['PATH'];
    switch($action) {
      case "revisions":
        $filepath = $httpVars['file'];
        $curr_dir = $httpVars['dir'];
        $filename = substr($filepath, strrpos($filepath, '/')+1);

      if(strrpos($filename, '.') === false) {
        $fname_orig = $filename;
      }
      else {
        $fname_orig = substr($filename, 0, strrpos($filename, '.'));
        $extension = substr($filename, strrpos($filename, '.')+1);
      }

      if(!is_dir($this->repository->options['PATH'].$curr_dir.'/backup')) {
        print("No revisions found for this file!!");
        break;
      }
        $handle = opendir($this->repository->options['PATH'].$curr_dir.'/backup');
        $i = 0;
        while (false !== ($file = readdir($handle))) {
          if ($file != "." && $file != "..") {
            if(strpos($file, '.') === false) {
              $fname = substr($file, 0, strrpos($file, '_'));
            }
            else {
              $fname = substr($file, 0, strrpos($file, '_'));
              $fname_extension = substr($file, strrpos($file, '.')+1);
            }
            if(($fname == $fname_orig)&&($extension == $fname_extension)) {
              $files[$i] = $file;
              $i++;
            }
          }
        }
        if(is_array($files)) {
          sort($files, SORT_REGULAR);
        }

        if(is_array($files)){
          $html = "<select id = 'revisions'>";
          foreach($files as $file){
            $filename_disp = $this->human_readable($file);
            $html .= "<option name='".$filename_disp."' value='".$file."'>".$filename_disp."</option>";
          }
          $html .= "</select>";
          header("Content-type:text/html");
          print($html);
        }
        else
          print("No revisions found for this file!!");
      break;

      case "revert" :
        $backup_file = $httpVars['backup_revert'];
        $backup_path = $this->repo_path.$httpVars['dir'].'/backup/'.$backup_file;
        $filepath_orig = $this->repo_path.$httpVars['file'];
        $filepath_dir = substr($filepath_orig, 0, strrpos($filepath_orig, '/'));
        $filename = substr($filepath_orig, strrpos($filepath_orig, '/')+1);
        $files = $this->getFiles($filepath_dir);

        if(!empty($files)){
          if(in_array($file, $files)){
            throw new AJXP_Exception("Another file/folder with same name already exists in the destination folder.");
            break;
          }
        }
        $this->copyFile($backup_path, $filepath_orig);
      break;
    }
  }

  function copyFile($src, $dest) {
    return copy($src, $dest);
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
}
?>
