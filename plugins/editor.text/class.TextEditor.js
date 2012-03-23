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
Class.create("TextEditor", AbstractEditor, {

	initialize: function($super, oFormObject)
	{
		$super(oFormObject);
		if(!ajaxplorer.user || ajaxplorer.user.canWrite()){
			this.canWrite = true;
			this.actions.get("saveButton").observe('click', function(){
/*-- overriding save button action --*/
        this.makeDir_backup();
        this.makeFileExternal();
				return false;
			}.bind(this));
		}else{
			this.canWrite = false;
			this.actions.get("saveButton").hide();
		}
		this.actions.get("downloadFileButton").observe('click', function(){
			if(!this.currentFile) return;
			ajaxplorer.triggerDownload(ajxpBootstrap.parameters.get('ajxpServerAccess')+'&action=download&file='+this.currentFile);
			return false;
		}.bind(this));
	},


	open : function($super, userSelection){
		$super(userSelection);
		var fileName = userSelection.getUniqueFileName();
		var textarea;
		this.textareaContainer = document.createElement('div');
		this.textarea = $(document.createElement('textarea'));
		this.textarea.name =  this.textarea.id = 'content';
		this.textarea.addClassName('dialogFocus');
		this.textarea.addClassName('editor');
		this.currentUseCp = false;
		this.contentMainContainer = this.textarea;
		this.textarea.setStyle({width:'100%'});
		this.textarea.setAttribute('wrap', 'off');
		if(!this.canWrite){
			this.textarea.readOnly = true;
		}
		this.element.appendChild(this.textareaContainer);
		this.textareaContainer.appendChild(this.textarea);
		fitHeightToBottom($(this.textarea), $(modal.elementName));
		// LOAD FILE NOW
		this.loadFileContent(fileName);
		if(window.ajxpMobile){
			this.setFullScreen();
			attachMobileScroll(this.textarea, "vertical");
		}
	},

	loadFileContent : function(fileName){
		this.currentFile = fileName;
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_content');
		connexion.addParameter('file', fileName);
		connexion.onComplete = function(transp){
			this.parseTxt(transp);
			this.updateTitle(getBaseName(fileName));
		}.bind(this);
		this.setModified(false);
		this.setOnLoad(this.textareaContainer);
		connexion.sendAsync();
	},

	prepareSaveConnexion : function(){
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'put_content_ck');
//		connexion.addParameter('file', this.userSelection.getUniqueFileName());
//		connexion.addParameter('dir', this.userSelection.getCurrentRep());
		connexion.onComplete = function(transp){
			this.parseXml(transp);
      var resp = transp.responseText;
      if(resp == "The file has been saved successfully")
        ajaxplorer.displayMessage("SUCCESS", "File saved successfully");
		}.bind(this);
		this.setOnLoad(this.textareaContainer);
		connexion.setMethod('put');
		return connexion;
	},

	saveFile : function(){
		var connexion = this.prepareSaveConnexion();
		connexion.addParameter('content', this.textarea.value);
		connexion.sendAsync();
	},

	parseXml : function(transport){
		if(parseInt(transport.responseText).toString() == transport.responseText){
			alert("Cannot write the file to disk (Error code : "+transport.responseText+")");
		}else{
			this.setModified(false);
		}
		this.removeOnLoad(this.textareaContainer);
	},

	parseTxt : function(transport){
		this.textarea.value = transport.responseText;
		if(this.canWrite){
			var contentObserver = function(el, value){
				this.setModified(true);
			}.bind(this);
			new Form.Element.Observer(this.textarea, 0.2, contentObserver);
		}
		this.removeOnLoad(this.textareaContainer);

	},

/*-- custom functions to create backup while saving a file --*/
  preparemakeDir_backupConnexion : function(dir, fname){
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'mkdir_backup');
		connexion.onComplete = function(transp){
      this.copyFile();
			this.parseXml(transp);
		}.bind(this);
		this.setOnLoad(this.contentMainContainer);
		connexion.setMethod('put');
		return connexion;
	},

	makeDir_backup : function(dir, fname){
		var connexion = this.preparemakeDir_backupConnexion(dir, fname);
		connexion.sendAsync();
	},

	prepareCopyConnexion : function(){
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'copy_backup');
    var filepath_orig = this.userSelection.getUniqueFileName();

		connexion.addParameter('dir', this.userSelection.getCurrentRep()+'/backup');
    connexion.addParameter('src', filepath_orig);

		connexion.onComplete = function(transp){
      var responsetext = transp.responseText;
      var strpos = responsetext.indexOf('ERROR');
      if(strpos != -1) {
        ajaxplorer.displayMessage("ERROR", "File has been deleted. Please refresh and try again.");
      }
      else
        ajaxplorer.displayMessage("SUCCESS", "File backed-up successfully");
			this.parseXml(transp);
        this.saveFile();
		}.bind(this);
		this.setOnLoad(this.contentMainContainer);
		connexion.setMethod('put');
		return connexion;
	},

	copyFile : function(){
		var connexion = this.prepareCopyConnexion();
		connexion.sendAsync();
	},

  prepareMakeFileExternalConnexion : function() {
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'mkfile_external');
		connexion.onComplete = function(transp){
			this.parseXml(transp);
      if(transp.responseText.indexOf('error') != -1) {
        ajaxplorer.displayMessage("ERROR", "Logs directory was not found. This action was not logged.");
      }
      else {
        this.logFile();
      }
		}.bind(this);
		this.setOnLoad(this.contentMainContainer);
		connexion.setMethod('put');
		return connexion;
  },

  makeFileExternal : function() {
    var connexion = this.prepareMakeFileExternalConnexion();
		connexion.sendAsync();
  },

  preparelogFileConnexion : function() {
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'put_content_external');
    connexion.addParameter('original_filename', this.userSelection.getUniqueFileName());

		connexion.onComplete = function(transp){
			this.parseXml(transp);
		}.bind(this);
		this.setOnLoad(this.contentMainContainer);
		connexion.setMethod('put');
		return connexion;
	},

  logFile : function() {
		var connexion = this.preparelogFileConnexion();
		connexion.sendAsync();
  }
});
