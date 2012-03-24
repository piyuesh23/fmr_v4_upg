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
 *
 */
defined('AJXP_EXEC') or die( 'Access not allowed');


// This is used to catch exception while downloading
if(!function_exists('download_exception_handler')){
	function download_exception_handler($exception){}
}

/**
 * @package info.ajaxplorer.plugins
 * AJXP_Plugin to access a filesystem. Most "FS" like driver (even remote ones)
 * extend this one.
 */
class fsAccessDriver extends AbstractAccessDriver implements AjxpWebdavProvider
{
	/**
	* @var Repository
	*/
	public $repository;
	public $driverConf;
	protected $wrapperClassName;
	protected $urlBase;
  public $repo_path;
  private static $loadedUserBookmarks;

	function initRepository(){
		if(is_array($this->pluginConf)){
			$this->driverConf = $this->pluginConf;
		}else{
			$this->driverConf = array();
		}
		if(isset($this->pluginConf["PROBE_REAL_SIZE"])){
			// PASS IT TO THE WRAPPER 
			ConfService::setConf("PROBE_REAL_SIZE", $this->pluginConf["PROBE_REAL_SIZE"]);
		}
		$create = $this->repository->getOption("CREATE");
		$path = $this->repository->getOption("PATH");
		$recycle = $this->repository->getOption("RECYCLE_BIN");
		if($create == true){
			if(!is_dir($path)) @mkdir($path, 0755, true);
			if(!is_dir($path)){
				throw new AJXP_Exception("Cannot create root path for repository (".$this->repository->getDisplay()."). Please check repository configuration or that your folder is writeable!");
			}
			if($recycle!= "" && !is_dir($path."/".$recycle)){
				@mkdir($path."/".$recycle);
				if(!is_dir($path."/".$recycle)){
					throw new AJXP_Exception("Cannot create recycle bin folder. Please check repository configuration or that your folder is writeable!");
				}
			}
		}else{
			if(!is_dir($path)){
				throw new AJXP_Exception("Cannot find base path for your repository! Please check the configuration!");
			}
		}
		$wrapperData = $this->detectStreamWrapper(true);
		$this->wrapperClassName = $wrapperData["classname"];
		$this->urlBase = $wrapperData["protocol"]."://".$this->repository->getId();
    $this->repo_path = $this->repository->options['PATH'];
		if($recycle != ""){
			RecycleBinManager::init($this->urlBase, "/".$recycle);
		}
	}
	
	public function getRessourceUrl($path){
		return $this->urlBase.$path;
	}
	
	public function getWrapperClassName(){
		return $this->wrapperClassName;
	}

    function redirectActionsToMethod(&$contribNode, $arrayActions, $targetMethod){
        $actionXpath=new DOMXPath($contribNode->ownerDocument);
        foreach($arrayActions as $index => $value){
            $arrayActions[$index] = 'action[@name="'.$value.'"]/processing/serverCallback';
        }
        $procList = $actionXpath->query(implode(" | ", $arrayActions), $contribNode);
        foreach($procList as $node){
            $node->setAttribute("methodName", $targetMethod);
        }
    }

	function disableArchiveBrowsingContributions(&$contribNode){
		// Cannot use zip features on FTP !
		// Remove "compress" action
		$actionXpath=new DOMXPath($contribNode->ownerDocument);
		$compressNodeList = $actionXpath->query('action[@name="compress"]', $contribNode);
		if(!$compressNodeList->length) return ;
		unset($this->actions["compress"]);
		$compressNode = $compressNodeList->item(0);
		$contribNode->removeChild($compressNode);		
		// Disable "download" if selection is multiple
		$nodeList = $actionXpath->query('action[@name="download"]/gui/selectionContext', $contribNode);
		$selectionNode = $nodeList->item(0);
		$values = array("dir" => "false", "unique" => "true");
		foreach ($selectionNode->attributes as $attribute){
			if(isSet($values[$attribute->name])){
				$attribute->value = $values[$attribute->name];
			}
		}
		$nodeList = $actionXpath->query('action[@name="download"]/processing/clientListener[@name="selectionChange"]', $contribNode);
		$listener = $nodeList->item(0);
		$listener->parentNode->removeChild($listener);
		// Disable "Explore" action on files
		$nodeList = $actionXpath->query('action[@name="ls"]/gui/selectionContext', $contribNode);
		$selectionNode = $nodeList->item(0);
		$values = array("file" => "false", "allowedMimes" => "");
		foreach ($selectionNode->attributes as $attribute){
			if(isSet($values[$attribute->name])){
				$attribute->value = $values[$attribute->name];
			}
		}		
	}
	
	function switchAction($action, $httpVars, $fileVars){
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);
		$selection = new UserSelection();
		$dir = $httpVars["dir"] OR "";
        if($this->wrapperClassName == "fsAccessWrapper"){
            $dir = fsAccessWrapper::patchPathForBaseDir($dir);
        }
		$dir = AJXP_Utils::securePath($dir);
		if($action != "upload"){
			$dir = AJXP_Utils::decodeSecureMagic($dir);
		}
		$selection->initFromHttpVars($httpVars);
		if(!$selection->isEmpty()){
			$this->filterUserSelectionToHidden($selection->getFiles());			
		}
		$mess = ConfService::getMessages();
		
		$newArgs = RecycleBinManager::filterActions($action, $selection, $dir, $httpVars);
		if(isSet($newArgs["action"])) $action = $newArgs["action"];
		if(isSet($newArgs["dest"])) $httpVars["dest"] = SystemTextEncoding::toUTF8($newArgs["dest"]);//Re-encode!
 		// FILTER DIR PAGINATION ANCHOR
		$page = null;
		if(isSet($dir) && strstr($dir, "%23")!==false){
			$parts = explode("%23", $dir);
			$dir = $parts[0];
			$page = $parts[1];
		}
		
		$pendingSelection = "";
		$logMessage = null;
		$reloadContextNode = false;
		
		switch($action)
		{
//------------------------------------
//	CUSTOM actions for highwire
//------------------------------------
/*
      case "check_site":
        $reponame = $this->repository->display;
        if(strpos($reponame, 'site') == 0)
          print "true";
        else
          print "false";
      break;
      case "check_symlink":
        $filepath = $this->repo_path.$httpVars['file'];
        $flag = $this->check_symlink($filepath);
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
        $metadata = $this->parse_xml($filepath_xml);
        print $metadata['preview_url'][0];

      break;

      case "get_topUrl":
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
        $metadata = $this->parse_xml($filepath_xml);
        $top_url = $metadata['top_url'][0];
        return $top_url;
      break;

      case "get_pubname" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = $this->parse_xml($filepath_xml);
        return $metadata['abbr'][0];
      break;

      case "get_jname" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = $this->parse_xml($filepath_xml);
        return $metadata['home_url'][0].'|'.$metadata['sitecode'][0];
      break;

      case "get_preview_repo" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = $this->parse_xml($filepath_xml);
        return $metadata['preview_repo'][0];
      break;

      case "get_publish_url" :
        $filename_metadata = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/'.$filename_metadata;
        $metadata = $this->parse_xml($filepath_xml);
        return $metadata['publish_url'][0];
      break;

//create a file outside AJXP -- for creating log files if they don't exist.
      case "mkfile_external";
        $date = $this->getTime();

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

          $metadata = $this->parse_xml($filepath_xml);
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

//create backup when file is being moved
      case "copy_backup" :
        $src = $httpVars['src'];
        $dest = $httpVars['dir'];
        $source_check = $this->urlBase.$src; //absolute filepath of file being moved.

//check if the file is being moved to a different repo
        if(!empty($httpVars['repoid']))
          $source_check = 'ajxp.fs://'.$httpVars['repoid'].$src;

//concurrency check--if file has been deleted under while the move action is being initiated
        if(!file_exists($source_check)) {
          throw new AJXP_Exception("File has been deleted. Please refresh and try again");
        }
        else {
          $fname = substr($src, strrpos($src, '/'));

//check if file has an extension
          if(strpos($fname, '.')) {
            $filename = substr($fname, 0, strrpos($fname, '.'));

            $extension = substr($fname, strrpos($fname, '.'));
          }
          else {
            $filename = $fname;

            $extension = '';
          }

          $date = $this->getTime();
          $filename_timestamp = $filename.'_'.$date['year'].$date['mon'].$date['mday'].'-'.$date['hours'].$date['minutes'].$date['seconds'].$extension;

//backup file name.
          $dest = $dest.$filename_timestamp;


          if(!empty($httpVars['repoid'])) {
            if(!file_exists($dest)) {
              $handle_rd = fopen('ajxp.fs://'.$httpVars['repoid'].$dest, 'w');
              fclose($handle_rd);
            }
            if(copy('ajxp.fs://'.$httpVars['repoid'].$src,'ajxp.fs://'.$httpVars['repoid'].$dest)) {
              return "success";
            }
            else {
              return "fail";
            }
          }
          else {
            if(!file_exists($dest)) {
              $handle_rd = fopen($this->urlBase.$dest, 'w');
              fclose($handle_rd);
            }
            if(copy($this->urlBase.$src,$this->urlBase.$dest)) {
              return "success";
            }
            else {
              return "fail";
            }
          }
        }
      break;
*/
/*--write data to logfile. --*/
/*
      case "put_content_external" :
				// Load "code" variable directly from POST array, do not "securePath" or "sanitize"...
        $date = $this->getTime();
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
          $metadata = $this->parse_xml($filepath_xml);
          $sitecode = $metadata['sitecode'][0];
          $dest = '/logs/'.$sitecode.'/'.$filename_log;
        }
        else {
          $dest = '/logs/'.$filename_log;
        }

				if(!is_file($dest) || !$this->isWriteable($dest, "file")){
					header("Content-Type:text/plain");
					print((!$this->isWriteable($dest, "file")?"1001":"1002"));
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
				$this->filterUserSelectionToHidden(array($dirname));
        if(!empty($httpVars['repoid'])) {
          if(!is_dir('ajxp.fs://'.$httpVars['repoid'].$httpVars['dir'].'/'.$httpVars['dirname'])) {
			      mkdir('ajxp.fs://'.$httpVars['repoid'].$httpVars['dir'].'/'.$httpVars['dirname']);
          }
        }
        else {
          if(!is_dir($this->urlBase.$dir.'/'.$httpVars['dirname'])) {
			      $error = $this->mkDir($dir, $httpVars['dirname']);
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
				if(!is_file($fileName) || !$this->isWriteable($fileName, "file")){
					header("Content-Type:text/plain");
					print((!$this->isWriteable($fileName, "file")?"1001":"1002"));
					return ;
				}
				$fp=fopen($fileName,"w");
				fputs ($fp,$code);
				fclose($fp);
				header("Content-Type:text/plain");
				print($mess[115]);

			break;
*/
//------------------------------------
//	CUSTOM actions for highwire
//------------------------------------
			//------------------------------------
			//	DOWNLOAD
			//------------------------------------
			case "download":
				AJXP_Logger::logAction("Download", array("files"=>$selection));
				@set_error_handler(array("HTMLWriter", "javascriptErrorHandler"), E_ALL & ~ E_NOTICE);
				@register_shutdown_function("restore_error_handler");
				$zip = false;
				if($selection->isUnique()){
					if(is_dir($this->urlBase.$selection->getUniqueFile())) {
						$zip = true;
						$base = basename($selection->getUniqueFile());
						$dir .= "/".dirname($selection->getUniqueFile());
					}else{
						if(!file_exists($this->urlBase.$selection->getUniqueFile())){
							throw new Exception("Cannot find file!");
						}
					}
				}else{
					$zip = true;
				}
				if($zip){
					// Make a temp zip and send it as download
					$loggedUser = AuthService::getLoggedUser();
					$file = AJXP_Utils::getAjxpTmpDir()."/".($loggedUser?$loggedUser->getId():"shared")."_".time()."tmpDownload.zip";
					$zipFile = $this->makeZip($selection->getFiles(), $file, $dir);
					if(!$zipFile) throw new AJXP_Exception("Error while compressing");
					register_shutdown_function("unlink", $file);
					$localName = ($base==""?"Files":$base).".zip";
					$this->readFile($file, "force-download", $localName, false, false, true);
				}else{
					$localName = "";
					AJXP_Controller::applyHook("dl.localname", array($this->urlBase.$selection->getUniqueFile(), &$localName, $this->wrapperClassName));
					$this->readFile($this->urlBase.$selection->getUniqueFile(), "force-download", $localName);
				}
				
			break;

			case "prepare_chunk_dl" : 

				$chunkCount = intval($httpVars["chunk_count"]);
				$fileId = $this->urlBase.$selection->getUniqueFile();
				$sessionKey = "chunk_file_".md5($fileId.time());
				$totalSize = $this->filesystemFileSize($fileId);
				$chunkSize = intval ( $totalSize / $chunkCount ); 
				$realFile  = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $fileId, true);
				$chunkData = array(
					"localname"	  => basename($fileId),
					"chunk_count" => $chunkCount,
					"chunk_size"  => $chunkSize,
					"total_size"  => $totalSize, 
					"file_id"	  => $sessionKey
				);
				
				$_SESSION[$sessionKey] = array_merge($chunkData, array("file"=>$realFile));
				HTMLWriter::charsetHeader("application/json");
				print(json_encode($chunkData));

			break;
			
			case "download_chunk" :
				
				$chunkIndex = intval($httpVars["chunk_index"]);
				$chunkKey = $httpVars["file_id"];
				$sessData = $_SESSION[$chunkKey];
				$realFile = $sessData["file"];
				$chunkSize = $sessData["chunk_size"];
				$offset = $chunkSize * $chunkIndex;
				if($chunkIndex == $sessData["chunk_count"]-1){
					// Compute the last chunk real length
					$chunkSize = $sessData["total_size"] - ($chunkSize * ($sessData["chunk_count"]-1));
					if(call_user_func(array($this->wrapperClassName, "isRemote"))){
						register_shutdown_function("unlink", $realFile);
					}
				}
				$this->readFile($realFile, "force-download", $sessData["localname"].".".sprintf("%03d", $chunkIndex+1), false, false, true, $offset, $chunkSize);				
				
				
			break;			
		
			case "compress" : 					
					// Make a temp zip and send it as download					
					$loggedUser = AuthService::getLoggedUser();
					if(isSet($httpVars["archive_name"])){						
						$localName = AJXP_Utils::decodeSecureMagic($httpVars["archive_name"]);
						$this->filterUserSelectionToHidden(array($localName));
					}else{
						$localName = (basename($dir)==""?"Files":basename($dir)).".zip";
					}
					$file = AJXP_Utils::getAjxpTmpDir()."/".($loggedUser?$loggedUser->getId():"shared")."_".time()."tmpCompression.zip";
					$zipFile = $this->makeZip($selection->getFiles(), $file, $dir);
					if(!$zipFile) throw new AJXP_Exception("Error while compressing file $localName");
					register_shutdown_function("unlink", $file);					
					copy($file, $this->urlBase.$dir."/".str_replace(".zip", ".tmp", $localName));
					@rename($this->urlBase.$dir."/".str_replace(".zip", ".tmp", $localName), $this->urlBase.$dir."/".$localName);
					$reloadContextNode = true;
					$pendingSelection = $localName;					
			break;
			
			case "stat" :
				
				clearstatcache();
				$stat = @stat($this->urlBase.$selection->getUniqueFile());
				header("Content-type:application/json");
				if(!$stat){
					print '{}';
				}else{
					print json_encode($stat);
				}
				
			break;
			
			
			//------------------------------------
			//	ONLINE EDIT
			//------------------------------------
			case "get_content":
					
				$dlFile = $this->urlBase.$selection->getUniqueFile();
                AJXP_Logger::logAction("Get_content", array("files"=>$selection));
				if(AJXP_Utils::getStreamingMimeType(basename($dlFile))!==false){
					$this->readFile($this->urlBase.$selection->getUniqueFile(), "stream_content");					
				}else{
					$this->readFile($this->urlBase.$selection->getUniqueFile(), "plain");
				}
				
			break;
			
			case "put_content":	
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
				$fileName = $this->urlBase.$file;
                $currentNode = new AJXP_Node($fileName);
                try{
                    AJXP_Controller::applyHook("node.before_change", array(&$currentNode));
                }catch(Exception $e){
                    header("Content-Type:text/plain");
                    $e->getMessage();
                    return;
                }
				if(!is_file($fileName) || !$this->isWriteable($fileName, "file")){
					header("Content-Type:text/plain");
					print((!$this->isWriteable($fileName, "file")?"1001":"1002"));
					return ;
				}
				$fp=fopen($fileName,"w");
				fputs ($fp,$code);
				fclose($fp);
                AJXP_Controller::applyHook("node.change", array($currentNode, new AJXP_Node($fileName), false));
				header("Content-Type:text/plain");
				print($mess[115]);
				
			break;
		
			//------------------------------------
			//	COPY / MOVE
			//------------------------------------
			case "copy";
			case "move";
				
			//throw new AJXP_Exception("", 113);
        $flag = $this->checkFileExists($selection);
        $file = substr($httpVars['file'], strrpos($httpVars['file'], '/')+1);
        if($httpVars['dest_repository_id']) {
          $dest_folder = "ajxp.fs://".$httpVars['dest_repository_id'].$httpVars['dest_node'];
          $dest_repo_id = $httpVars['dest_repository_id'];
        }
        else {
          $dest_folder = $this->urlBase.$httpVars['dest_node'];
        }
#        $files = $this->getFiles($dest_folder);
#        if(!empty($files)){
#          if(is_dir($dest_folder.'/'.$file)){
#            if(in_array($file, $files)){
#              throw new AJXP_Exception("Another file/folder with same name already exists in the destination folder.");
#              break;
#            }
#          }
#        }

        if($flag == FALSE) {
          throw new AJXP_Exception("File is already deleted. Please refresh your display.");
        }

				if($selection->isEmpty())
				{
					throw new AJXP_Exception("", 113);
				}
				$success = $error = array();
				$dest = AJXP_Utils::decodeSecureMagic($httpVars["dest"]);
				$this->filterUserSelectionToHidden(array($httpVars["dest"]));
				if($selection->inZip()){
					// Set action to copy anycase (cannot move from the zip).
					$action = "copy";
					$this->extractArchive($dest, $selection, $error, $success);
				}else{
          $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
          $metadata = $this->parse_xml($filepath_xml);
          $sitecode = $metadata['sitecode'][0];
          $status_log = true;
          if($action == "move") {
            $selectedfiles = $selection->getFiles();
            foreach($selectedfiles as $file) {
              $source_path = $this->initPath($this->urlBase.$file);
              if(!is_dir($source_path)) {
                $status_backup_src = $this->hw_backup_file($source_path, "move", $file);

                $filename = substr($file, strrpos($file, '/'));

                $status_backup_dest = true;
                if(file_exists($this->initPath($dest_folder).$filename)) {
                  $status_backup_dest = $this->hw_backup_file($this->initPath($dest_folder).$filename, "move");
                }

                $status_backup = $status_backup_src && $status_backup_dest;
                $status_log = true;
                if($status_backup == "success") {
                  $status_log = $this->logFile($file, $sitecode, $this->repository->options['PATH'], $action)&&$status_log;
                }
              }
            }
          }
          else {
            $selectedfiles = $selection->getFiles();
            foreach($selectedfiles as $file) {
              $filename = substr($file, strrpos($file, '/'));
              if(file_exists($this->initPath($dest_folder).$filename)) {
                $status_backup = $this->hw_backup_file($this->initPath($dest_folder).$filename, "copy");
              }
              $status_log = true;
              if($status_backup) {
                $status_log = $this->logFile($filename, $sitecode, $this->repository->options['PATH'], $action)&&$status_log;
              }
            }
          }
          error_log("log status".$status_log);
					$this->copyOrMove($dest, $selection->getFiles(), $error, $success, $dest_repo_id, ($action=="move"?true:false), $status_log);
				}
				
				if(count($error)){					
					throw new AJXP_Exception(SystemTextEncoding::toUTF8(join("\n", $error)));
				}else {
					$logMessage = join("</br>", $success);
					AJXP_Logger::logAction(($action=="move"?"Move":"Copy"), array("files"=>$selection, "destination"=>$dest));
				}
				$reloadContextNode = true;
                if(!(RecycleBinManager::getRelativeRecycle() ==$dest && $this->driverConf["HIDE_RECYCLE"] == true)){
                    $reloadDataNode = $dest;
                }

			break;
			
			//------------------------------------
			//	DELETE
			//------------------------------------
			case "delete";
			
				if($selection->isEmpty())
				{
					throw new AJXP_Exception("", 113);
				}
				$logMessages = array();
				$selectedfiles = $selection->getFiles();
				foreach($selectedfiles as $file) {
				  $source_fullpath = $this->initPath($this->urlBase.$file);
          $this->hw_backup_file($source_fullpath, 'delete');
          $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
          $metadata = $this->parse_xml($filepath_xml);
          $sitecode = $metadata['sitecode'][0];
          $this->logFile($file, $sitecode, $this->repository->options['PATH'], 'delete');
        }
				$errorMessage = $this->delete($selection->getFiles(), $logMessages);
				if(count($logMessages))
				{
					$logMessage = join("\n", $logMessages);
				}
				if($errorMessage) throw new AJXP_Exception(SystemTextEncoding::toUTF8($errorMessage));
				AJXP_Logger::logAction("Delete", array("files"=>$selection));
				$reloadContextNode = true;
				
			break;


      case "purge" :
          $pTime = intval($this->repository->getOption("PURGE_AFTER"));
          if($pTime > 0){
              $purgeTime = intval($pTime)*3600*24;
              $this->recursivePurge($this->urlBase, $purgeTime);
          }

                
                $pTime = intval($this->repository->getOption("PURGE_AFTER"));
                if($pTime > 0){
                    $purgeTime = intval($pTime)*3600*24;
                    $this->recursivePurge($this->urlBase, $purgeTime);
                }

            break;
		
			//------------------------------------
			//	RENAME
			//------------------------------------
			case "rename";
			
				$file = AJXP_Utils::decodeSecureMagic($httpVars["file"]);
				$filename_new = AJXP_Utils::decodeSecureMagic($httpVars["filename_new"]);
				$this->filterUserSelectionToHidden(array($filename_new));
				$filepath_ajxp = "ajxp.fs://".$this->repository->getId().$file;
				$filepath_absolute = $this->initPath($filepath_ajxp);
				error_log($filepath_absolute);
				if(!(is_dir($filepath_absolute))) {
          if(!strrpos($file, '.' ) === false)
            $extension_old = substr($file, strrpos($file,'.')+1);
          if($extension_old == '')
            $extension_old = 'xhtml';
          if(strpos($filename_new, '.') === false) {
            $filename_new .= '.'.$extension_old;
          }
          elseif(substr($filename_new, strrpos($filename_new,'.')) == '.') {
            $filename_new .= $extension_old;
          }
        }
				$this->rename($file, $filename_new);
				$logMessage= SystemTextEncoding::toUTF8($file)." $mess[41] ".SystemTextEncoding::toUTF8($filename_new);
				$reloadContextNode = true;
				$pendingSelection = $filename_new;
				AJXP_Logger::logAction("Rename", array("original"=>$file, "new"=>$filename_new));
				
			break;
		
			//------------------------------------
			//	CREER UN REPERTOIRE / CREATE DIR
			//------------------------------------
			case "mkdir";
			        
				$messtmp="";
				$dirname=AJXP_Utils::decodeSecureMagic($httpVars["dirname"], AJXP_SANITIZE_HTML_STRICT);
				$dirname = substr($dirname, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
				$this->filterUserSelectionToHidden(array($dirname));
				$error = $this->mkDir($dir, $dirname);
				if(isSet($error)){
					throw new AJXP_Exception($error);
				}
                $currentNodeDir = new AJXP_Node($this->urlBase.$dir);
                AJXP_Controller::applyHook("node.before_change", array(&$currentNodeDir));
				$messtmp.="$mess[38] ".SystemTextEncoding::toUTF8($dirname)." $mess[39] ";
				if($dir=="") {$messtmp.="/";} else {$messtmp.= SystemTextEncoding::toUTF8($dir);}
				$logMessage = $messtmp;
				$pendingSelection = $dirname;
				$reloadContextNode = true;
                $newNode = new AJXP_Node($this->urlBase.$dir."/".$dirname);
                AJXP_Controller::applyHook("node.change", array(null, $newNode, false));
                AJXP_Logger::logAction("Create Dir", array("dir"=>$dir."/".$dirname));

			break;
		
			//------------------------------------
			//	CREER UN FICHIER / CREATE FILE
			//------------------------------------
			case "mkfile";
			
				$messtmp="";
				$filename=AJXP_Utils::decodeSecureMagic($httpVars["filename"], AJXP_SANITIZE_HTML_STRICT);
				$filename = substr($filename, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
				$this->filterUserSelectionToHidden(array($filename));
				$content = "";
                AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($this->urlBase.$dir)));
				if(isSet($httpVars["content"])){
					$content = $httpVars["content"];
				}
        if(strpos($filename, '.') === false) {
          $filename .= '.xhtml';
        }
        elseif(substr($filename, strpos($filename, '.')) == '.') {
          $filename .= 'xhtml';
        }
				$error = $this->createEmptyFile($dir, $filename, $content);
				if(isSet($error)){
					throw new AJXP_Exception($error);
				}
				$messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
				if($dir=="") {$messtmp.="/";} else {$messtmp.=SystemTextEncoding::toUTF8($dir);}
				$logMessage = $messtmp;
				$reloadContextNode = true;
				$pendingSelection = $dir."/".$filename;
				AJXP_Logger::logAction("Create File", array("file"=>$dir."/".$filename));
				$newNode = new AJXP_Node($this->urlBase.$dir."/".$filename);
				AJXP_Controller::applyHook("node.change", array(null, $newNode, false));
        if(!strstr($dir, '/backup')) {
          $date = getDate();
          $fname = 'filemgr'.$date['wday'].'.log';
          $fpath = '/logs/'.$sitecode.'/'.$fname;

          $code =  time().' create '. $this->repository->options['PATH'].$dir.'/'.$filename;

				  $fp=fopen($fpath,"a");
				  fputs ($fp,$code.PHP_EOL);
				  fclose($fp);
				  header("Content-Type:text/plain");
        }

			break;
			
			//------------------------------------
			//	CHANGE FILE PERMISSION
			//------------------------------------
			case "chmod";
			
				$messtmp="";
				$files = $selection->getFiles();
				$changedFiles = array();
				$chmod_value = $httpVars["chmod_value"];
				$recursive = $httpVars["recursive"];
				$recur_apply_to = $httpVars["recur_apply_to"];
				foreach ($files as $fileName){
					$error = $this->chmod($fileName, $chmod_value, ($recursive=="on"), ($recursive=="on"?$recur_apply_to:"both"), $changedFiles);
				}
				if(isSet($error)){
					throw new AJXP_Exception($error);
				}
				//$messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
				$logMessage="Successfully changed permission to ".$chmod_value." for ".count($changedFiles)." files or folders";
				$reloadContextNode = true;
				AJXP_Logger::logAction("Chmod", array("dir"=>$dir, "filesCount"=>count($changedFiles)));
		
			break;
			
			//------------------------------------
			//	UPLOAD
			//------------------------------------	
			case "upload":

				AJXP_Logger::debug("Upload Files Data", $fileVars);
				$destination=$this->urlBase.AJXP_Utils::decodeSecureMagic($dir);
				AJXP_Logger::debug("Upload inside", array("destination"=>$destination));
				if(!$this->isWriteable($destination))
				{
					$errorCode = 412;
					$errorMessage = "$mess[38] ".SystemTextEncoding::toUTF8($dir)." $mess[99].";
					AJXP_Logger::debug("Upload error 412", array("destination"=>$destination));
					return array("ERROR" => array("CODE" => $errorCode, "MESSAGE" => $errorMessage));
				}	
				foreach ($fileVars as $boxName => $boxData)
				{
          $fname = $boxData['name'];
          if(!($httpVars["auto_rename"])) {
            if(file_exists($this->initPath($destination.'/'.$fname)))
              $this->hw_backup_file($this->initPath($destination.'/'.$fname), 'upload');
          }
					if(substr($boxName, 0, 9) != "userfile_") continue;
					$err = AJXP_Utils::parseFileDataErrors($boxData);
					if($err != null)
					{
						$errorCode = $err[0];
						$errorMessage = $err[1];
						break;
					}
					$userfile_name = $boxData["name"];
					try{
						$this->filterUserSelectionToHidden(array($userfile_name));					
					}catch (Exception $e){
						return array("ERROR" => array("CODE" => 411, "MESSAGE" => "Forbidden"));
					}

					$userfile_name=AJXP_Utils::sanitize(SystemTextEncoding::fromPostedFileName($userfile_name), AJXP_SANITIZE_HTML_STRICT);
					$userfile_name = substr($userfile_name, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
					if(isSet($httpVars["auto_rename"])){
						$userfile_name = self::autoRenameForDest($destination, $userfile_name);
					}
					if(isSet($boxData["input_upload"])){
						try{
							AJXP_Logger::debug("Begining reading INPUT stream");
                            if(file_exists($destination."/".$userfile_name)){
                                AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($destination."/".$userfile_name)));
                            }
                            AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($destination)));
							$input = fopen("php://input", "r");
							$output = fopen("$destination/".$userfile_name, "w");
							$sizeRead = 0;
							while($sizeRead < intval($boxData["size"])){
								$chunk = fread($input, 4096);
								$sizeRead += strlen($chunk);
								fwrite($output, $chunk, strlen($chunk));
							}
							fclose($input);
							fclose($output);
							AJXP_Logger::debug("End reading INPUT stream");
						}catch (Exception $e){
							$errorCode=411;
							$errorMessage = $e->getMessage();
							break;
						}
					}else{
                        $result = @move_uploaded_file($boxData["tmp_name"], "$destination/".$userfile_name);
                        if(!$result){
                            $realPath = call_user_func(array($this->wrapperClassName, "getRealFSReference"),"$destination/".$userfile_name);
                            $result = move_uploaded_file($boxData["tmp_name"], $realPath);
                        }
						if (!$result)
						{
							$errorCode=411;
							$errorMessage="$mess[33] ".$userfile_name;
							break;
						}
					}
					$this->changeMode($destination."/".$userfile_name);
                    AJXP_Controller::applyHook("node.change", array(null, new AJXP_Node($destination."/".$userfile_name), false));
					$logMessage.="$mess[34] ".SystemTextEncoding::toUTF8($userfile_name)." $mess[35] $dir";
					AJXP_Logger::logAction("Upload File", array("file"=>SystemTextEncoding::fromUTF8($dir)."/".$userfile_name));
				}
				
				if(isSet($errorMessage)){
					AJXP_Logger::debug("Return error $errorCode $errorMessage");
					return array("ERROR" => array("CODE" => $errorCode, "MESSAGE" => $errorMessage));
				}else{
					AJXP_Logger::debug("Return success");
					return array("SUCCESS" => true);
				}
				return ;
				
			break;
            
			//------------------------------------
			//	XML LISTING
			//------------------------------------
			case "ls":
			
				if(!isSet($dir) || $dir == "/") $dir = "";
				$lsOptions = $this->parseLsOptions((isSet($httpVars["options"])?$httpVars["options"]:"a"));
								
				$startTime = microtime();
				
				$dir = AJXP_Utils::securePath(SystemTextEncoding::magicDequote($dir));
				$path = $this->urlBase.($dir!= ""?($dir[0]=="/"?"":"/").$dir:"");
                $nonPatchedPath = $path;
                if($this->wrapperClassName == "fsAccessWrapper") {
                    $nonPatchedPath = fsAccessWrapper::unPatchPathForBaseDir($path);
                }
				$threshold = $this->repository->getOption("PAGINATION_THRESHOLD");
				if(!isSet($threshold) || intval($threshold) == 0) $threshold = 500;
				$limitPerPage = $this->repository->getOption("PAGINATION_NUMBER");
				if(!isset($limitPerPage) || intval($limitPerPage) == 0) $limitPerPage = 200;
								
				$countFiles = $this->countFiles($path, !$lsOptions["f"]);
				if($countFiles > $threshold){
					$offset = 0;
					$crtPage = 1;
					if(isSet($page)){
						$offset = (intval($page)-1)*$limitPerPage; 
						$crtPage = $page;
					}
					$totalPages = floor($countFiles / $limitPerPage) + 1;
				}else{
					$offset = $limitPerPage = 0;
				}					
												
				$metaData = array();
				if(RecycleBinManager::recycleEnabled() && $dir == ""){
                    $metaData["repo_has_recycle"] = "true";
				}
				$parentAjxpNode = new AJXP_Node($nonPatchedPath, $metaData);
                $parentAjxpNode->loadNodeInfo(false, true);
				AJXP_XMLWriter::renderAjxpHeaderNode($parentAjxpNode);
				if(isSet($totalPages) && isSet($crtPage)){
					AJXP_XMLWriter::renderPaginationData(
						$countFiles, 
						$crtPage, 
						$totalPages, 
						$this->countFiles($path, TRUE)
					);
					if(!$lsOptions["f"]){
						AJXP_XMLWriter::close();
						exit(1);
					}
				}
				
				$cursor = 0;
				$handle = opendir($path);
				if(!$handle) {
					throw new AJXP_Exception("Cannot open dir ".$nonPatchedPath);
				}
				closedir($handle);				
				$fullList = array("d" => array(), "z" => array(), "f" => array());				
				$nodes = scandir($path);
				if(!empty($this->driverConf["SCANDIR_RESULT_SORTFONC"])){
					usort($nodes, $this->driverConf["SCANDIR_RESULT_SORTFONC"]);
				}
				//while(strlen($nodeName = readdir($handle)) > 0){
				foreach ($nodes as $nodeName){
					if($nodeName == "." || $nodeName == "..") continue;
					
					$isLeaf = "";
					if(!$this->filterNodeName($path, $nodeName, $isLeaf, $lsOptions)){
						continue;
					}
					if(RecycleBinManager::recycleEnabled() && $dir == "" && "/".$nodeName == RecycleBinManager::getRecyclePath()){
						continue;
					}
					
					if($offset > 0 && $cursor < $offset){
						$cursor ++;
						continue;
					}
					if($limitPerPage > 0 && ($cursor - $offset) >= $limitPerPage) {				
						break;
					}					
					
					$currentFile = $nonPatchedPath."/".$nodeName;
                    $meta = array();
                    if($isLeaf != "") $meta = array("is_file" => ($isLeaf?"1":"0"));
                    $node = new AJXP_Node($currentFile, $meta);
                    $node->setLabel($nodeName);
                    $node->loadNodeInfo();
					if(!empty($metaData["nodeName"]) && $metaData["nodeName"] != $nodeName){
                        $node->setUrl($nonPatchedPath."/".$metaData["nodeName"]);
					}

                    $nodeType = "d";
                    if($node->isLeaf()){
                        if(AJXP_Utils::isBrowsableArchive($nodeName)) {
                            if($lsOptions["f"] && $lsOptions["z"]){
                                $nodeType = "f";
                            }else{
                                $nodeType = "z";
                            }
                        }
                        else $nodeType = "f";
                    }

					$fullList[$nodeType][$nodeName] = $node;
					$cursor ++;
				}				
				array_map(array("AJXP_XMLWriter", "renderAjxpNode"), $fullList["d"]);
				array_map(array("AJXP_XMLWriter", "renderAjxpNode"), $fullList["z"]);
				array_map(array("AJXP_XMLWriter", "renderAjxpNode"), $fullList["f"]);
				
				// ADD RECYCLE BIN TO THE LIST
				if($dir == "" && RecycleBinManager::recycleEnabled() && $this->driverConf["HIDE_RECYCLE"] !== true)
				{
					$recycleBinOption = RecycleBinManager::getRelativeRecycle();										
					if(file_exists($this->urlBase.$recycleBinOption)){
						$recycleIcon = ($this->countFiles($this->urlBase.$recycleBinOption, false, true)>0?"trashcan_full.png":"trashcan.png");
						$recycleNode = new AJXP_Node($this->urlBase.$recycleBinOption);
                        $recycleNode->loadNodeInfo();
                        AJXP_XMLWriter::renderAjxpNode($recycleNode);
					}
				}
				
				AJXP_Logger::debug("LS Time : ".intval((microtime()-$startTime)*1000)."ms");
				
				AJXP_XMLWriter::close();
				return ;
				
			break;		
		}

		
		$xmlBuffer = "";
		if(isset($logMessage) || isset($errorMessage))
		{
			$xmlBuffer .= AJXP_XMLWriter::sendMessage((isSet($logMessage)?$logMessage:null), (isSet($errorMessage)?$errorMessage:null), false);			
		}				
		if($reloadContextNode){
			if(!isSet($pendingSelection)) $pendingSelection = "";
			$xmlBuffer .= AJXP_XMLWriter::reloadDataNode("", $pendingSelection, false);
		}
		if(isSet($reloadDataNode)){
			$xmlBuffer .= AJXP_XMLWriter::reloadDataNode($reloadDataNode, "", false);
		}
					
		return $xmlBuffer;
	}
			
	function parseLsOptions($optionString){
		// LS OPTIONS : dz , a, d, z, all of these with or without l
		// d : directories
		// z : archives
		// f : files
		// => a : all, alias to dzf
		// l : list metadata
		$allowed = array("a", "d", "z", "f", "l");
		$lsOptions = array();
		foreach ($allowed as $key){
			if(strchr($optionString, $key)!==false){
				$lsOptions[$key] = true;
			}else{
				$lsOptions[$key] = false;
			}
		}
		if($lsOptions["a"]){
			$lsOptions["d"] = $lsOptions["z"] = $lsOptions["f"] = true;
		}
		return $lsOptions;
	}

    /**
     * @param AJXP_Node $ajxpNode
     * @return void
     */
    function loadNodeInfo(&$ajxpNode){

        $nodeName = basename($ajxpNode->getPath());
        $metaData = $ajxpNode->metadata;
        $basepath = $ajxpNode->getPath();
        $filepath_ajxp = "ajxp.fs://".$this->repository->getId().$basepath;
        $filepath_absolute = $this->initPath($filepath_ajxp);

        if(!($this->check_symlink($filepath_absolute))){
          $metaData["symlink"] = "true";
        }

        if(!isSet($metaData["is_file"])){
            $isLeaf = is_file($ajxpNode->getUrl()) || AJXP_Utils::isBrowsableArchive($nodeName);
            $metaData["is_file"] = ($isLeaf?"1":"0");
        }else{
            $isLeaf = $metaData["is_file"] == "1" ? true : false;
        }
        $metaData["filename"] = $ajxpNode->getPath();

        if(RecycleBinManager::recycleEnabled() && $ajxpNode->getPath() == RecycleBinManager::getRelativeRecycle()){
            $mess = ConfService::getMessages();
            $recycleIcon = ($this->countFiles($ajxpNode->getUrl(), false, true)>0?"trashcan_full.png":"trashcan.png");
            $metaData["icon"] = $recycleIcon;
            $metaData["mimestring"] = $mess[122];
            $ajxpNode->setLabel($mess[122]);
            $metaData["ajxp_mime"] = "ajxp_recycle";
        }else{
            $metaData["mimestring"] = AJXP_Utils::mimetype($ajxpNode->getUrl(), "type", !$isLeaf);
            if(!($this->check_symlink($filepath_absolute))){
              $metaData["icon"] = "symlink_folder.png";
            }
            else
              $metaData["icon"] = AJXP_Utils::mimetype($nodeName, "image", !$isLeaf);
            if($metaData["icon"] == "folder.png"){
                $metaData["openicon"] = "folder_open.png";
            }
        }
        //if($lsOptions["l"]){

        $metaData["file_group"] = @filegroup($ajxpNode->getUrl()) || "unknown";
        $metaData["file_owner"] = @fileowner($ajxpNode->getUrl()) || "unknown";
        $fPerms = @fileperms($ajxpNode->getUrl());
        if($fPerms !== false){
            $fPerms = substr(decoct( $fPerms ), ($isLeaf?2:1));
        }else{
            $fPerms = '0000';
        }
        $metaData["file_perms"] = $fPerms;
        $datemodif = $this->date_modif($ajxpNode->getUrl());
        $metaData["ajxp_modiftime"] = ($datemodif ? $datemodif : "0");
        $metaData["bytesize"] = 0;
        if($isLeaf){
            $metaData["bytesize"] = $this->filesystemFileSize($ajxpNode->getUrl());
        }
        $metaData["filesize"] = AJXP_Utils::roundSize($metaData["bytesize"]);
        if(AJXP_Utils::isBrowsableArchive($nodeName)){
            $metaData["ajxp_mime"] = "ajxp_browsable_archive";
        }

        //}

        /*
        if(!isSet(self::$loadedUserBookmarks)){
            $user = AuthService::getLoggedUser();
            if($user == null){
                self::$loadedUserBookmarks = false;
            }else{
                self::$loadedUserBookmarks = $user->getBookmarks();
            }
        }
        if(self::$loadedUserBookmarks !== false){
            foreach(self::$loadedUserBookmarks as $bookmark){
                if($bookmark["PATH"] == $ajxpNode->getPath()) {
                    $ajxpNode->mergeMetadata(array(
                        "ajxp_bookmarked"=>"true",
                        "ajxp_overlay_icon" =>"bookmark"));
                }
            }
        }
        */
        $ajxpNode->mergeMetadata($metaData);

    }

	/**
	 * Test if userSelection is containing a hidden file, which should not be the case!
	 * @param UserSelection $files
	 */
	function filterUserSelectionToHidden($files){
		foreach ($files as $file){
			$file = basename($file);
			if(AJXP_Utils::isHidden($file) && !$this->driverConf["SHOW_HIDDEN_FILES"]){
				throw new Exception("Forbidden");
			}
			if($this->filterFile($file) || $this->filterFolder($file)){
				throw new Exception("Forbidden");
			}
		}
	}
	
	function filterNodeName($nodePath, $nodeName, &$isLeaf, $lsOptions){
		$isLeaf = (is_file($nodePath."/".$nodeName) || AJXP_Utils::isBrowsableArchive($nodeName));
		if(AJXP_Utils::isHidden($nodeName) && !$this->driverConf["SHOW_HIDDEN_FILES"]){
			return false;
		}
		$nodeType = "d";
		if($isLeaf){
			if(AJXP_Utils::isBrowsableArchive($nodeName)) $nodeType = "z";
			else $nodeType = "f";
		}		
		if(!$lsOptions[$nodeType]) return false;
		if($nodeType == "d"){			
			if(RecycleBinManager::recycleEnabled() 
				&& $nodePath."/".$nodeName == RecycleBinManager::getRecyclePath()){
					return false;
				}
			return !$this->filterFolder($nodeName);
		}else{
			if($nodeName == "." || $nodeName == "..") return false;
			if(RecycleBinManager::recycleEnabled() 
				&& $nodePath == RecycleBinManager::getRecyclePath() 
				&& $nodeName == RecycleBinManager::getCacheFileName()){
				return false;
			}
			return !$this->filterFile($nodeName);
		}
	}
	
    function filterFile($fileName){
        $pathParts = pathinfo($fileName);
        if(array_key_exists("HIDE_FILENAMES", $this->driverConf) && !empty($this->driverConf["HIDE_FILENAMES"])){
            if(!is_array($this->driverConf["HIDE_FILENAMES"])) {
                $this->driverConf["HIDE_FILENAMES"] = explode(",",$this->driverConf["HIDE_FILENAMES"]);
            }
            foreach ($this->driverConf["HIDE_FILENAMES"] as $search){
                if(strcasecmp($search, $pathParts["basename"]) == 0) return true;
            }
        }
        if(array_key_exists("HIDE_EXTENSIONS", $this->driverConf) && !empty($this->driverConf["HIDE_EXTENSIONS"])){
            if(!is_array($this->driverConf["HIDE_EXTENSIONS"])) {
                $this->driverConf["HIDE_EXTENSIONS"] = explode(",",$this->driverConf["HIDE_EXTENSIONS"]);
            }
            foreach ($this->driverConf["HIDE_EXTENSIONS"] as $search){
                if(strcasecmp($search, $pathParts["extension"]) == 0) return true;
            }
        }
        return false;
    }

    function filterFolder($folderName){
        if(array_key_exists("HIDE_FOLDERS", $this->driverConf) && !empty($this->driverConf["HIDE_FOLDERS"])){
            if(!is_array($this->driverConf["HIDE_FOLDERS"])) {
                $this->driverConf["HIDE_FOLDERS"] = explode(",",$this->driverConf["HIDE_FOLDERS"]);
            }
            foreach ($this->driverConf["HIDE_FOLDERS"] as $search){
                if(strcasecmp($search, $folderName) == 0) return true;
            }
        }
        return false;
    }
	
	function readFile($filePathOrData, $headerType="plain", $localName="", $data=false, $gzip=null, $realfileSystem=false, $byteOffset=-1, $byteLength=-1)
	{
		if($gzip === null){
			$gzip = ConfService::getCoreConf("GZIP_COMPRESSION");
		}
        if($this->wrapperClassName == "fsAccessWrapper"){
            $originalFilePath = $filePathOrData;
            $filePathOrData = fsAccessWrapper::patchPathForBaseDir($filePathOrData);
        }
		session_write_close();

		restore_error_handler();
		restore_exception_handler();

        set_exception_handler('download_exception_handler');
        set_error_handler('download_exception_handler');
        // required for IE, otherwise Content-disposition is ignored
        if(ini_get('zlib.output_compression')) { 
         AJXP_Utils::safeIniSet('zlib.output_compression', 'Off'); 
        }

		$isFile = !$data && !$gzip; 		
		if($byteLength == -1){
            if($data){
                $size = strlen($filePathOrData);
            }else if ($realfileSystem){
                $size = sprintf("%u", filesize($filePathOrData));
            }else{
                $size = $this->filesystemFileSize($filePathOrData);
            }
		}else{
			$size = $byteLength;
		}
		if($gzip && ($size > ConfService::getCoreConf("GZIP_LIMIT") || !function_exists("gzencode") || @strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') === FALSE)){
			$gzip = false; // disable gzip
		}
		
		$localName = ($localName=="" ? basename((isSet($originalFilePath)?$originalFilePath:$filePathOrData)) : $localName);
		if($headerType == "plain")
		{
			header("Content-type:text/plain");
		}
		else if($headerType == "image")
		{
			header("Content-Type: ".AJXP_Utils::getImageMimeType(basename($filePathOrData))."; name=\"".$localName."\"");
			header("Content-Length: ".$size);
			header('Cache-Control: public');
		}
		else
		{
			if(preg_match('/ MSIE /',$_SERVER['HTTP_USER_AGENT']) || preg_match('/ WebKit /',$_SERVER['HTTP_USER_AGENT'])){
				$localName = str_replace("+", " ", urlencode(SystemTextEncoding::toUTF8($localName)));
			}
			if ($isFile) {
				header("Accept-Ranges: 0-$size");
				AJXP_Logger::debug("Sending accept range 0-$size");
			}
			
			// Check if we have a range header (we are resuming a transfer)
			if ( isset($_SERVER['HTTP_RANGE']) && $isFile && $size != 0 )
			{
				if($headerType == "stream_content"){
					if(extension_loaded('fileinfo')  && $this->wrapperClassName == "fsAccessWrapper"){
            			$fInfo = new fInfo( FILEINFO_MIME );
            			$realfile = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $filePathOrData);
            			$mimeType = $fInfo->file( $realfile);
            			$splitChar = explode(";", $mimeType);
            			$mimeType = trim($splitChar[0]);
            			AJXP_Logger::debug("Detected mime $mimeType for $realfile");
					}else{
						$mimeType = AJXP_Utils::getStreamingMimeType(basename($filePathOrData));
					}					
					header('Content-type: '.$mimeType);
				}
				// multiple ranges, which can become pretty complex, so ignore it for now
				$ranges = explode('=', $_SERVER['HTTP_RANGE']);
				$offsets = explode('-', $ranges[1]);
				$offset = floatval($offsets[0]);
				
				$length = floatval($offsets[1]) - $offset;
				if (!$length) $length = $size - $offset;
				if ($length + $offset > $size || $length < 0) $length = $size - $offset;
				AJXP_Logger::debug('Content-Range: bytes ' . $offset . '-' . $length . '/' . $size);
				header('HTTP/1.1 206 Partial Content');
				header('Content-Range: bytes ' . $offset . '-' . ($offset + $length) . '/' . $size);
				
				header("Content-Length: ". $length);
				$file = fopen($filePathOrData, 'rb');
				fseek($file, 0);
				$relOffset = $offset;
				while ($relOffset > 2.0E9)
				{
					// seek to the requested offset, this is 0 if it's not a partial content request
					fseek($file, 2000000000, SEEK_CUR);
					$relOffset -= 2000000000;
					// This works because we never overcome the PHP 32 bit limit
				}
				fseek($file, $relOffset, SEEK_CUR);

                while(ob_get_level()) ob_end_flush();
				$readSize = 0.0;
				$bufferSize = 1024 * 8;
				while (!feof($file) && $readSize < $length && connection_status() == 0)
				{
					AJXP_Logger::debug("dl reading $readSize to $length", $_SERVER["HTTP_RANGE"]);					
					echo fread($file, $bufferSize);
					$readSize += $bufferSize;
					flush();
				}
				
				fclose($file);
				return;
			} else
			{
				header("Content-Type: application/force-download; name=\"".$localName."\"");
				header("Content-Transfer-Encoding: binary");
				if($gzip){
					header("Content-Encoding: gzip");
					// If gzip, recompute data size!
					$gzippedData = ($data?gzencode($filePathOrData,9):gzencode(file_get_contents($filePathOrData), 9));
					$size = strlen($gzippedData);
				}
				header("Content-Length: ".$size);
				if ($isFile && ($size != 0)) header("Content-Range: bytes 0-" . ($size - 1) . "/" . $size . ";");
				header("Content-Disposition: attachment; filename=\"".$localName."\"");
				header("Expires: 0");
				header("Cache-Control: no-cache, must-revalidate");
				header("Pragma: no-cache");
				if (preg_match('/ MSIE /',$_SERVER['HTTP_USER_AGENT'])){
					header("Cache-Control: max_age=0");
					header("Pragma: public");
				}

                // IE8 is dumb
				if (preg_match('/ MSIE /',$_SERVER['HTTP_USER_AGENT']))
                {
                    header("Pragma: public");
                    header("Expires: 0");
                    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                    header("Cache-Control: private",false);
//                    header("Content-Type: application/octet-stream");
                }

				// For SSL websites there is a bug with IE see article KB 323308
				// therefore we must reset the Cache-Control and Pragma Header
				if (ConfService::getConf("USE_HTTPS")==1 && preg_match('/ MSIE /',$_SERVER['HTTP_USER_AGENT']))
				{
					header("Cache-Control:");
					header("Pragma:");
				}
				if($gzip){
					print $gzippedData;
					return;
				}
			}
		}

		if($data){
			print($filePathOrData);
		}else{
            if($this->pluginConf["USE_XSENDFILE"] && $this->wrapperClassName == "fsAccessWrapper"){
                if(!$realfileSystem) $filePathOrData = fsAccessWrapper::getRealFSReference($filePathOrData);
                $filePathOrData = str_replace("\\", "/", $filePathOrData);
                header("X-Sendfile: ".SystemTextEncoding::toUTF8($filePathOrData));
                return;
            }
			$stream = fopen("php://output", "a");
			if($realfileSystem){
				AJXP_Logger::debug("realFS!", array("file"=>$filePathOrData));
		    	$fp = fopen($filePathOrData, "rb");
		    	if($byteOffset != -1){
		    		fseek($fp, $byteOffset);
		    	}	
		    	$sentSize = 0;			
		    	$readChunk = 4096;
		    	while (!feof($fp)) {
		    		if( $byteLength != -1 &&  ($sentSize + $readChunk) >= $byteLength){
		    			// compute last chunk and break after
		    			$readChunk = $byteLength - $sentSize;
		    			$break = true;
		    		}
		 			$data = fread($fp, $readChunk);
		 			$dataSize = strlen($data);
		 			fwrite($stream, $data, $dataSize);
		 			$sentSize += $dataSize;
		 			if(isSet($break)){
		 				break;
		 			}
		    	}
		    	fclose($fp);
			}else{
				call_user_func(array($this->wrapperClassName, "copyFileInStream"), $filePathOrData, $stream);
			}
			fflush($stream);
			fclose($stream);
		}
	}

	function countFiles($dirName, $foldersOnly = false, $nonEmptyCheckOnly = false){
		$handle=opendir($dirName);
		$count = 0;
		while (strlen($file = readdir($handle)) > 0)
		{
			if($file != "." && $file !=".." 
				&& !(AJXP_Utils::isHidden($file) && !$this->driverConf["SHOW_HIDDEN_FILES"])
				&& !($foldersOnly && is_file($dirName."/".$file)) ){
				$count++;
				if($nonEmptyCheckOnly) break;
			}			
		}
		closedir($handle);
		return $count;
	}
			
	function date_modif($file)
	{
		$tmp = @filemtime($file) or 0;
		return $tmp;// date("d,m L Y H:i:s",$tmp);
	}
	
	function changeMode($filePath)
	{
		$chmodValue = $this->repository->getOption("CHMOD_VALUE");
		if(isSet($chmodValue) && $chmodValue != "")
		{
			$chmodValue = octdec(ltrim($chmodValue, "0"));
			call_user_func(array($this->wrapperClassName, "changeMode"), $filePath, $chmodValue);
		}		
	}

    function filesystemFileSize($filePath){
        $bytesize = filesize($filePath);
        if(method_exists($this->wrapperClassName, "getLastRealSize")){
            $last = call_user_func(array($this->wrapperClassName, "getLastRealSize"));
            if($last !== false){
                $bytesize = $last;
            }
        }
        if($bytesize < 0){
            $bytesize = sprintf("%u", $bytesize);
        }

        return $bytesize;
    }

	/**
	 * Extract an archive directly inside the dest directory.
	 *
	 * @param string $destDir
	 * @param UserSelection $selection
	 * @param array $error
	 * @param array $success
	 */
	function extractArchive($destDir, $selection, &$error, &$success){
		require_once(AJXP_BIN_FOLDER."/pclzip.lib.php");
		$zipPath = $selection->getZipPath(true);
		$zipLocalPath = $selection->getZipLocalPath(true);
		if(strlen($zipLocalPath)>1 && $zipLocalPath[0] == "/") $zipLocalPath = substr($zipLocalPath, 1)."/";
		$files = $selection->getFiles();

		$realZipFile = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $this->urlBase.$zipPath);
		$archive = new PclZip($realZipFile);
		$content = $archive->listContent();		
		foreach ($files as $key => $item){// Remove path
			$item = substr($item, strlen($zipPath));
			if($item[0] == "/") $item = substr($item, 1);			
			foreach ($content as $zipItem){
				if($zipItem["stored_filename"] == $item || $zipItem["stored_filename"] == $item."/"){
					$files[$key] = $zipItem["stored_filename"];
					break;
				}else{
					unset($files[$key]);
				}
			}
		}
		AJXP_Logger::debug("Archive", $files);
		$realDestination = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $this->urlBase.$destDir);
		AJXP_Logger::debug("Extract", array($realDestination, $realZipFile, $files, $zipLocalPath));
		$result = $archive->extract(PCLZIP_OPT_BY_NAME, $files, 
									PCLZIP_OPT_PATH, $realDestination, 
									PCLZIP_OPT_REMOVE_PATH, $zipLocalPath);
		if($result <= 0){
			$error[] = $archive->errorInfo(true);
		}else{
			$mess = ConfService::getMessages();
			$success[] = sprintf($mess[368], basename($zipPath), $destDir);
		}
	}
	
	function copyOrMove($destDir, $selectedFiles, &$error, &$success, $move = false)
	{
		AJXP_Logger::debug("CopyMove", array("dest"=>$destDir));
		$mess = ConfService::getMessages();
		if(!$this->isWriteable($this->urlBase.$destDir))
		{
			$error[] = $mess[38]." ".$destDir." ".$mess[99];
			return ;
		}
				
		foreach ($selectedFiles as $selectedFile)
		{
			if($move && !$this->isWriteable(dirname($this->urlBase.$selectedFile)))
			{
				$error[] = "\n".$mess[38]." ".dirname($selectedFile)." ".$mess[99];
				continue;
			}
			$this->copyOrMoveFile($destDir, $selectedFile, $error, $success, $move);
		}
	}
	
	function renameAction($actionName, $httpVars)
	{
		$filePath = SystemTextEncoding::fromUTF8($httpVars["file"]);
		$newFilename = SystemTextEncoding::fromUTF8($httpVars["filename_new"]);
		return $this->rename($filePath, $newFilename);
	}
	
	function rename($filePath, $filename_new)
	{
		$nom_fic=basename($filePath);
		$mess = ConfService::getMessages();
		$filename_new=AJXP_Utils::sanitize(SystemTextEncoding::magicDequote($filename_new), AJXP_SANITIZE_HTML_STRICT);
		$filename_new = substr($filename_new, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
		$old=$this->urlBase."/$filePath";
		if(!$this->isWriteable($old))
		{
			throw new AJXP_Exception($mess[34]." ".$nom_fic." ".$mess[99]);
		}
		$new=dirname($old)."/".$filename_new;
		if($filename_new=="")
		{
			throw new AJXP_Exception("$mess[37]");
		}
		if(file_exists($new))
		{
			throw new AJXP_Exception("$filename_new $mess[43]"); 
		}
		if(!file_exists($old))
		{
			throw new AJXP_Exception($mess[100]." $nom_fic");
		}
        $oldNode = new AJXP_Node($old);
        AJXP_Controller::applyHook("node.before_change", array(&$oldNode));
		rename($old,$new);
        AJXP_Controller::applyHook("node.change", array($oldNode, new AJXP_Node($new), false));
	}
	
	function autoRenameForDest($destination, $fileName){
		if(!is_file($destination."/".$fileName)) return $fileName;
		$i = 1;
		$ext = "";
		$name = "";
		$split = explode(".", $fileName);
		if(count($split) > 1){
			$ext = ".".$split[count($split)-1];
			array_pop($split);
			$name = join("\.", $split);
		}else{
			$name = $fileName;
		}
		while (is_file($destination."/".$name."-$i".$ext)) {
			$i++; // increment i until finding a non existing file.
		}
		return $name."-$i".$ext;
	}
	
	function mkDir($crtDir, $newDirName)
	{
		$mess = ConfService::getMessages();
		if($newDirName=="")
		{
			return "$mess[37]";
		}
		if(file_exists($this->urlBase."$crtDir/$newDirName"))
		{
			return "$mess[40]"; 
		}
		if(!$this->isWriteable($this->urlBase."$crtDir"))
		{
			return $mess[38]." $crtDir ".$mess[99];
		}

        $dirMode = 0775;
		$chmodValue = $this->repository->getOption("CHMOD_VALUE");
		if(isSet($chmodValue) && $chmodValue != "")
		{
			$dirMode = octdec(ltrim($chmodValue, "0"));
			if ($dirMode & 0400) $dirMode |= 0100; // User is allowed to read, allow to list the directory
			if ($dirMode & 0040) $dirMode |= 0010; // Group is allowed to read, allow to list the directory
			if ($dirMode & 0004) $dirMode |= 0001; // Other are allowed to read, allow to list the directory
		}
		$old = umask(0);
		mkdir($this->urlBase."$crtDir/$newDirName", $dirMode);
		umask($old);
		return null;		
	}
	
	function createEmptyFile($crtDir, $newFileName, $content = "")
	{
		$mess = ConfService::getMessages();
		if($newFileName=="")
		{
			return "$mess[37]";
		}
		if(file_exists($this->urlBase."$crtDir/$newFileName"))
		{
			return "$mess[71]";
		}
		if(!$this->isWriteable($this->urlBase."$crtDir"))
		{
			return "$mess[38] $crtDir $mess[99]";
		}
		$fp=fopen($this->urlBase."$crtDir/$newFileName","w");
		if($fp)
		{
			if($content != ""){
				fputs($fp, $content);
			}
			if(preg_match("/\.html$/",$newFileName)||preg_match("/\.htm$/",$newFileName))
			{
				fputs($fp,"<html>\n<head>\n<title>New Document - Created By AjaXplorer</title>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=iso-8859-1\">\n</head>\n<body bgcolor=\"#FFFFFF\" text=\"#000000\">\n\n</body>\n</html>\n");
			}
			$this->changeMode($this->urlBase."$crtDir/$newFileName");
			fclose($fp);
			return null;
		}
		else
		{
			return "$mess[102] $crtDir/$newFileName (".$fp.")";
		}		
	}
	
	
	function delete($selectedFiles, &$logMessages)
	{
		$mess = ConfService::getMessages();
		foreach ($selectedFiles as $selectedFile)
		{	
			if($selectedFile == "" || $selectedFile == DIRECTORY_SEPARATOR)
			{
				return $mess[120];
			}
			$fileToDelete=$this->urlBase.$selectedFile;
			if(!file_exists($fileToDelete))
			{
				$logMessages[]=$mess[100]." ".SystemTextEncoding::toUTF8($selectedFile);
				continue;
			}
			$this->deldir($fileToDelete);
			if(is_dir($fileToDelete))
			{
				$logMessages[]="$mess[38] ".SystemTextEncoding::toUTF8($selectedFile)." $mess[44].";
			}
			else 
			{
				$logMessages[]="$mess[34] ".SystemTextEncoding::toUTF8($selectedFile)." $mess[44].";
			}
			AJXP_Controller::applyHook("node.change", array(new AJXP_Node($fileToDelete)));
		}
		return null;
	}
	
	
	
	function copyOrMoveFile($destDir, $srcFile, &$error, &$success, $move = false)
	{
		$mess = ConfService::getMessages();		
		$destFile = $this->urlBase.$destDir."/".basename($srcFile);
		if($dest_repo_id) {
		  $destFile = 'ajxp.fs://'.$dest_repo_id.'/'.$destDir.'/'.basename($srcFile);
		}
		$realSrcFile = $this->urlBase.$srcFile;
		if(!file_exists($realSrcFile))
		{
			$error[] = $mess[100].$srcFile;
			return ;
		}		
		if(dirname($realSrcFile)==dirname($destFile))
		{
			if($move){
				$error[] = $mess[101];
				return ;
			}else{
				$base = basename($srcFile);
				$i = 1;
				if(is_file($realSrcFile)){
					$dotPos = strrpos($base, ".");
					if($dotPos>-1){
						$radic = substr($base, 0, $dotPos);
						$ext = substr($base, $dotPos);
					}
				}
				// auto rename file
				$i = 1;
				$newName = $base;
				while (file_exists($this->urlBase.$destDir."/".$newName)) {
					$suffix = "-$i";
					if(isSet($radic)) $newName = $radic . $suffix . $ext;
					else $newName = $base.$suffix;
					$i++;
				}
				$destFile = $this->urlBase.$destDir."/".$newName;
			}
		}
		if(!is_file($realSrcFile))
		{			
			$errors = array();
			$succFiles = array();
			if($move){
                AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($realSrcFile)));
				if(file_exists($destFile)) $this->deldir($destFile);
				$res = rename($realSrcFile, $destFile);
			}else{				
				$dirRes = $this->dircopy($realSrcFile, $destFile, $errors, $succFiles);
			}			
			if(count($errors) || (isSet($res) && $res!==true))
			{
				$error[] = $mess[114];
				return ;
			}else{
                AJXP_Controller::applyHook("node.change", array(new AJXP_Node($realSrcFile), new AJXP_Node($destFile), !$move));
            }
		}
		else 
		{			
			if($move){
                AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($realSrcFile)));
				if(file_exists($destFile)) unlink($destFile);				
				$res = rename($realSrcFile, $destFile);
				AJXP_Controller::applyHook("node.change", array(new AJXP_Node($realSrcFile), new AJXP_Node($destFile), false));
			}else{
				try{
          if(filesize($realSrcFile) == 0) {
            copy($realSrcFile, $destFile);
          }
          else {
					  $src = fopen($realSrcFile, "r");
					  $dest = fopen($destFile, "w");
					  if($dest !== false){
						  while (!feof($src)) {
							  stream_copy_to_stream($src, $dest, 4096);
						  }
						  fclose($dest);
					  }
					  fclose($src);
          }
					AJXP_Controller::applyHook("node.change", array(new AJXP_Node($realSrcFile), new AJXP_Node($destFile), true));
				}catch (Exception $e){
					$error[] = $e->getMessage();
					return ;					
				}
			}
		}
		
		if($move)
		{
			// Now delete original
			// $this->deldir($realSrcFile); // both file and dir
			$messagePart = $mess[74]." ".SystemTextEncoding::toUTF8($destDir);
			if($dest_repo_id) {
			  $destpath_absolute_message = $this->initPath(SystemTextEncoding::toUTF8('ajxp.fs://'.$dest_repo_id.'/'.$destDir));
        $repoobj = ConfService::getRepositoryById($dest_repo_id);
        $repopath = $repoobj->options["PATH"];
        $repo_root_dirname = substr($repopath, strrpos($repopath, '/'));
			  $destpath_relative_message = substr($destpath_absolute_message, strpos($destpath_absolute_message, $repo_root_dirname));
			  $destpath_message = str_replace($repo_root_dirname.'/', '', $destpath_relative_message);
			  $messagePart = $mess[74]." ".$destpath_message;
			}
			if(RecycleBinManager::recycleEnabled() && $destDir == RecycleBinManager::getRelativeRecycle())
			{
				RecycleBinManager::fileToRecycle($srcFile);
				$messagePart = $mess[123]." ".$mess[122];
			}
			if(isset($dirRes))
			{
				$success[] = $mess[117]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$messagePart." (".SystemTextEncoding::toUTF8($dirRes)." ".$mess[116].") ";
			}
			else 
			{
        if($status == false) {
  				$success[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$messagePart.'. Logs directory doesn\'t exist, so this action was not logged.';
        }
        else
  				$success[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$messagePart;
			}
		}
		else
		{			
			if(RecycleBinManager::recycleEnabled() && $destDir == "/".$this->repository->getOption("RECYCLE_BIN"))
			{
				RecycleBinManager::fileToRecycle($srcFile);
			}
			if(isSet($dirRes))
			{
				$success[] = $mess[117]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$mess[73]." ".SystemTextEncoding::toUTF8($destDir)." (".SystemTextEncoding::toUTF8($dirRes)." ".$mess[116].")";	
			}
			else 
			{
        if($status == false) {
  				$success[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$mess[73]." ".SystemTextEncoding::toUTF8($destDir).'. Logs directory doesn\'t exist, so this action was not logged.';
        }
        else
  				$success[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$mess[73]." ".SystemTextEncoding::toUTF8($destDir);
			}
		}
		
	}

	// A function to copy files from one directory to another one, including subdirectories and
	// nonexisting or newer files. Function returns number of files copied.
	// This function is PHP implementation of Windows xcopy  A:\dir1\* B:\dir2 /D /E /F /H /R /Y
	// Syntaxis: [$number =] dircopy($sourcedirectory, $destinationdirectory [, $verbose]);
	// Example: $num = dircopy('A:\dir1', 'B:\dir2', 1);

	function dircopy($srcdir, $dstdir, &$errors, &$success, $verbose = false) 
	{
		$num = 0;
		//$verbose = true;
		if(!is_dir($dstdir)) mkdir($dstdir);
		if($curdir = opendir($srcdir)) 
		{
			while($file = readdir($curdir)) 
			{
				if($file != '.' && $file != '..') 
				{
					$srcfile = $srcdir . "/" . $file;
					$dstfile = $dstdir . "/" . $file;
					if(is_file($srcfile)) 
					{
						if(is_file($dstfile)) $ow = filemtime($srcfile) - filemtime($dstfile); else $ow = 1;
						if($ow > 0) 
						{
							try { 
								$tmpPath = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $srcfile);
								if($verbose) echo "Copying '$tmpPath' to '$dstfile'...";
								copy($tmpPath, $dstfile);
								$success[] = $srcfile;
								$num ++;
							}catch (Exception $e){
								$errors[] = $srcfile;
							}
						}
					}
					else
					{
						if($verbose) echo "Dircopy $srcfile";
						$num += $this->dircopy($srcfile, $dstfile, $errors, $success, $verbose);
					}
				}
			}
			closedir($curdir);
		}
		return $num;
	}
	
	function simpleCopy($origFile, $destFile)
	{
		return copy($origFile, $destFile);
	}
	
	public function isWriteable($dir, $type="dir")
	{
		return is_writable($dir);
	}
	
	function deldir($location)
	{
		if(is_dir($location))
		{
            AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($location)));
			$all=opendir($location);
			while ($file=readdir($all))
			{
				if (is_dir("$location/$file") && $file !=".." && $file!=".")
				{
					$this->deldir("$location/$file");
					if(file_exists("$location/$file")){
						rmdir("$location/$file"); 
					}
					unset($file);
				}
				elseif (!is_dir("$location/$file"))
				{
					if(file_exists("$location/$file")){
						unlink("$location/$file"); 
					}
					unset($file);
				}
			}
			closedir($all);
			rmdir($location);
		}
		else
		{
			if(file_exists("$location")) {
                AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($location)));
				$test = @unlink("$location");
				if(!$test) throw new Exception("Cannot delete file ".$location);
			}
		}
		if(basename(dirname($location)) == $this->repository->getOption("RECYCLE_BIN"))
		{
			// DELETING FROM RECYCLE
			RecycleBinManager::deleteFromRecycle($location);
		}
	}
	
	/**
	 * Change file permissions 
	 *
	 * @param String $path
	 * @param String $chmodValue
	 * @param Boolean $recursive
	 * @param String $nodeType "both", "file", "dir"
	 */
	function chmod($path, $chmodValue, $recursive=false, $nodeType="both", &$changedFiles)
	{
	    $realValue = octdec(ltrim($chmodValue, "0"));
		if(is_file($this->urlBase.$path)){
			if($nodeType=="both" || $nodeType=="file"){
				call_user_func(array($this->wrapperClassName, "changeMode"), $this->urlBase.$path, $realValue);
				$changedFiles[] = $path;
			}
		}else{
			if($nodeType=="both" || $nodeType=="dir"){
				call_user_func(array($this->wrapperClassName, "changeMode"), $this->urlBase.$path, $realValue);				
				$changedFiles[] = $path;
			}
			if($recursive){
				$handler = opendir($this->urlBase.$path);
				while ($child=readdir($handler)) {
					if($child == "." || $child == "..") continue;
					// do not pass realValue or it will be re-decoded.
					$this->chmod($path."/".$child, $chmodValue, $recursive, $nodeType, $changedFiles);
				}
				closedir($handler);
			}
		}
	}
	
	/**
	 * @return zipfile
	 */ 
    function makeZip ($src, $dest, $basedir)
    {
    	@set_time_limit(0);
    	require_once(AJXP_BIN_FOLDER."/pclzip.lib.php");
    	$filePaths = array();
    	foreach ($src as $item){
    		$realFile = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $this->urlBase."/".$item);    		
    		$realFile = AJXP_Utils::securePath($realFile);
    		$basedir = trim(dirname($realFile));
    		$filePaths[] = array(PCLZIP_ATT_FILE_NAME => $realFile, 
    							 PCLZIP_ATT_FILE_NEW_SHORT_NAME => basename($item));    				
    	}
    	AJXP_Logger::debug("Pathes", $filePaths);
    	AJXP_Logger::debug("Basedir", array($basedir));
    	$archive = new PclZip($dest);
    	$vList = $archive->create($filePaths, PCLZIP_OPT_REMOVE_PATH, $basedir, PCLZIP_OPT_NO_COMPRESSION, PCLZIP_OPT_ADD_TEMP_FILE_ON);
    	if(!$vList){
    		throw new Exception("Zip creation error : ($dest) ".$archive->errorInfo(true));
    	}
    	return $vList;
    }

    function recursivePurge($dirName, $purgeTime){

        $handle=opendir($dirName);
        $count = 0;
        while (strlen($file = readdir($handle)) > 0)
        {
            if($file == "" || $file == ".."  || AJXP_Utils::isHidden($file) ){
                continue;
            }
            if(is_file($dirName."/".$file)){
                $time = filemtime($dirName."/".$file);
                $docAge = time() - $time;
                if( $docAge > $purgeTime){
                    $node = new AJXP_Node($dirName."/".$file);
                    AJXP_Controller::applyHook("node.before_change", array($node));
                    unlink($dirName."/".$file);
                    AJXP_Controller::applyHook("node.change", array($node));
                    AJXP_Logger::logAction("Purge", array("file" => $dirName."/".$file));
                    print(" - Purging document : ".$dirName."/".$file."\n");
                }
            }else{
                $this->recursivePurge($dirName."/".$file, $purgeTime);
            }
        }
        closedir($handle);


    }
    
    
    /** The publiclet URL making */
    function makePublicletOptions($filePath, $password, $expire, $repository)
    {
    	$data = array(
            "DRIVER"=>$repository->getAccessType(),
            "OPTIONS"=>NULL,
            "FILE_PATH"=>$filePath,
            "ACTION"=>"download",
            "EXPIRE_TIME"=>$expire ? (time() + $expire * 86400) : 0,
            "PASSWORD"=>$password
        );
        return $data;
    }

    function makeSharedRepositoryOptions($httpVars, $repository){
		$newOptions = array(
			"PATH" => $repository->getOption("PATH").AJXP_Utils::decodeSecureMagic($httpVars["file"]),
			"CREATE" => false, 
			"RECYCLE_BIN" => "", 
			"DEFAULT_RIGHTS" => "");
    	return $newOptions;			
    }
//-------------------------------------------------
//	CUSTOM helper functions used in custom actions
//-------------------------------------------------

/*-- format timestamp to human readable format --used for backup names --*/

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

/*-- Parse metadata files for repo to get the values
  @params:
  $filepath_xml => full path for the metadata file
--*/

  function parse_xml($filepath_xml) {
    if(!file_exists($filepath_xml)) {
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

/*-- Get files inside a dir -- @params
  $dir => full path of the dir1
--*/
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

/*-- Write into /logs directory for copy/move actions
  @params:
  $file => name of file whose action needs to be logged
  $sitecode => directory inside /logs (specific to repo)
  $path => path to the directory under which the file is present
  $action => action which needs to be logged
--*/
  function logFile($file, $sitecode, $path, $action) {
    $logfile = '/logs/'.$sitecode.'/';
    if(!is_dir('/logs')) {
//      throw new AJXP_Exception("Logs directory doesn't exist. Continuing without writing to logs.")
      return false;
    }
    if(!is_dir($logfile)) {
      mkdir($logfile, 0777);
    }
    $date = getdate(time());

    $fname = 'filemgr'.$date['wday'].'.log';
    $code = time().' '.$action.' '.$path.$file;
    $fp = fopen($logfile.$fname, 'a');
		fputs ($fp,$code.PHP_EOL);
		fclose($fp);
		header("Content-Type:text/plain");
    return true;
  }

/*-- Check if the file being operated exists or no --Concurrency test @params:
  $selection => selection object
--*/
  function checkFileExists($selection) {
    $flag = TRUE;
    $selectedFiles = $selection->files;
    foreach($selectedFiles as $file) {
      if(!(file_exists($this->repo_path.$file))) {
        $flag = $flag&&FALSE;
      }
    }
    return $flag;
  }


  function check_symlink($filename) {
    $flag = true;
    $filepath_parts = explode('/', $filename);

    foreach($filepath_parts as $part) {
      if($part == '') continue;
      if('/'.$fpath == $this->repo_path.'/') {
        $flag = true;
      }
      $fpath = $fpath.$part.'/';
      $fpath_trimmed = rtrim($fpath, '/');
      if(is_link('/'.$fpath_trimmed)) {
        $flag = $flag && false;
      }
    }
    return $flag;
  }

/**
 * @description -- create the backup filename.
 * @params
 * $source_fullpath -- absolute path for the file.
 * @return
 * $filename_backup -- backup filepath with timestamp added to source filename.
 */

 function hw_backup_fname_create($source_fullpath) {
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
   $source_parts[$count_source_parts] = $filename_backup;
   $filepath_backup = implode('/', $source_parts);

   return $filepath_backup;
 }

/**
 * @description -- creates a backup of file on save, copy, move and delete actions
 * @params
 * $source_fullpath -- absolute path of the file whose backup is being created
 * @return
 * success/fail
 */
 function hw_backup_file($source_fullpath, $action=NULL) {
   if(!(file_exists($source_fullpath))&&($action == "save")) {
     throw new AJXP_Exception("File has been deleted. Please refresh and try again");
   }
   else {
     $filepath_backup = $this->hw_backup_fpath_create($source_fullpath);
     $dirpath_backup = substr($filepath_backup, 0, strrpos($filepath_backup, '/'));
     $this->delete_old_files($dirpath_backup);
     if(!file_exists($filepath_backup)) {
       $handle_rd = fopen($filepath_backup, 'w');
       fclose($handle_rd);
     }
     if(file_exists($source_fullpath)) {
       if(copy($source_fullpath, $filepath_backup)) {
         return "success";
       }
       else {
         return "fail";
       }
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

/**
 * @description -- delete old backup files
 * @params
 * $backup_repo -- backup directory which needs to be cleared
 */
 function delete_old_files($backup_repo) {
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
//-------------------------------------------------
//	CUSTOM helper functions used in custom actions
//-------------------------------------------------
}

?>