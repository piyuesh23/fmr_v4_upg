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
class PluginPreview extends AJXP_Plugin {

    /**
     * @param DOMNode $contribNode
     * @return void
     */
  public function switchAction($action, $httpVars, $fileVars) {
    $this->repository = ConfService::getRepository();
		$this->urlBase = $wrapperData["protocol"]."://".$this->repository->getId();
    $this->repo_path = $this->repository->options['PATH'];
    switch($action) {
      case "preview":
        $file = $httpVars['file'];
        $filepath_xml = AJXP_INSTALL_PATH.'/data/files/metadata_'.$this->repository->display.'.xml';
        $metadata = fsAccessDriver::parse_xml($filepath_xml);
        $preview_url = $metadata['preview_url'][0];
        $publish_filepath = $preview_url.$file;
        print $publish_filepath;
      break;
    }
  }
}
?>
