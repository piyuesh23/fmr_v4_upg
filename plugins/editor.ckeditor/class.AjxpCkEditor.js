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
Class.create("AjxpCkEditor", TextEditor, {

	initialize: function($super, oFormObject)
	{
		$super(oFormObject);
    var repoId = ajaxplorer.repositoryId;
    var filename = ajaxplorer.getUserSelection().getUniqueFileName();
    var dir = ajaxplorer.getUserSelection().getCurrentRep();
    var relative_path = ajaxplorer._contextHolder._currentRep;
    var repoLabel = ajaxplorer.user.repositories.get(repoId).getLabel();
    var filepath_metadata = "metadata_"+repoLabel+".xml";
//    var hw_fmr_url = document.location.href;
//    if(hw_fmr_url.indexOf('ac-url') != -1) {
//      var ac_url = hw_fmr_url.substring(hw_fmr_url.indexOf('ac-url'), hw_fmr_url.indexOf('#'));
//      var browse_url = 'index.php?external_selector_type=ckeditor&'+ac_url;
//    }
//    else {
//      var browse_url = 'index.php?external_selector_type=ckeditor&'+ac_url;
//    }
    var url;
    this.getMetadata(filepath_metadata);
    var response_url = this.url+relative_path+'/';
		this.editorConfig = {
			resize_enabled:false,
			toolbar : "hw",
			filebrowserBrowseUrl : 'index.php?external_selector_type=ckeditor',
			// IF YOU KNOW THE RELATIVE PATH OF THE IMAGES (BETWEEN REPOSITORY ROOT AND REAL FILE)
			// YOU CAN PASS IT WITH THE relative_path PARAMETER. FOR EXAMPLE :
			//filebrowserBrowseUrl : 'index.php?external_selector_type=ckeditor&relative_path=files',
      curr_dir : dir,
      curr_file : filename,
      repoId : repoId,
      baseHref : response_url,
			filebrowserImageBrowseUrl : 'index.php?external_selector_type=ckeditor',
			filebrowserFlashBrowseUrl : 'index.php?external_selector_type=ckeditor',
			language : ajaxplorer.currentLanguage,
			fullPage : true,
      autoParagraph : false,
      toolbarCanCollapse : false,
      useComputedState : false,
      extraPlugins : 'docprops',
      toolbar_hw :
      [
      { name: 'clipboard',   items : [ 'Paste','PasteText','PasteFromWord','-','Undo','Redo' ] },
      { name: 'paragraph',   items : [ 'NumberedList','BulletedList','-','Outdent','Indent','-','Blockquote','CreateDiv','-','JustifyLeft','JustifyCenter','JustifyRight','JustifyBlock','-','BidiLtr','BidiRtl' ] },
      { name: 'links',       items : [ 'Link','Unlink','Anchor' ] },
      { name: 'insert',      items : [ 'Image','Table','HorizontalRule' ] },
      { name: 'document',    items : [ 'Source','-','DocProps','Templates' ] },
      '/',
      { name: 'styles',      items : [ 'Styles','Format','Font','FontSize' ] },
      { name: 'basicstyles', items : [ 'Bold','Italic','Underline','Strike','Subscript','Superscript','-','RemoveFormat' ] },
      { name: 'colors',      items : [ 'TextColor','BGColor' ] },
      { name: 'editing',     items : [ 'Find','Replace','-','SelectAll','-', 'Scayt' ] },

      { name: 'tools',       items : [ 'ShowBlocks','-','About' ] },
      ],

			toolbar_Ajxp : [
				['Source','Preview','Templates'],
			    ['Undo','Redo','-', 'Cut','Copy','Paste','PasteText','PasteFromWord','-','Print', 'SpellChecker', 'Scayt'],
			    ['Find','Replace','-','SelectAll','RemoveFormat'],
			    ['Form', 'Checkbox', 'Radio', 'TextField', 'Textarea', 'Select', 'Button', 'ImageButton', 'HiddenField'],
			    '/',
			    ['Bold','Italic','Underline','Strike','-','Subscript','Superscript'],
			    ['NumberedList','BulletedList','-','Outdent','Indent','Blockquote'],
			    ['JustifyLeft','JustifyCenter','JustifyRight','JustifyBlock'],
			    ['Link','Unlink','Anchor'],
			    ['Image','Flash','Table','HorizontalRule','Smiley','SpecialChar','PageBreak'],
			    '/',
			    ['Styles','Format','Font','FontSize'],
			    ['TextColor','BGColor'],
			    ['Maximize', 'ShowBlocks','-','About']
			]

		};

		if(window.ajxpMobile){
			this.editorConfig = {
				resize_enabled:false,
				toolbar : "Ajxp",
				filebrowserBrowseUrl : 'index.php?external_selector_type=ckeditor',
				// IF YOU KNOW THE RELATIVE PATH OF THE IMAGES (BETWEEN REPOSITORY ROOT AND REAL FILE)
				// YOU CAN PASS IT WITH THE relative_path PARAMETER. FOR EXAMPLE :
				//filebrowserBrowseUrl : 'index.php?external_selector_type=ckeditor&relative_path=files',
				filebrowserImageBrowseUrl : 'index.php?external_selector_type=ckeditor',
				filebrowserFlashBrowseUrl : 'index.php?external_selector_type=ckeditor',
				language : ajaxplorer.currentLanguage,
				fullPage : true,
        autoParagraph : false,
        toolbarCanCollapse : false,
        useComputedState : false,
        extraPlugins : 'docprops',

				toolbar_Ajxp : [
				    ['Bold','Italic','Underline', '-', 'NumberedList','BulletedList'],
				    ['JustifyLeft','JustifyCenter','JustifyRight','JustifyBlock']
				]

			};
		}
	},


	open : function($super, userSelection){
		this.userSelection = userSelection;
		var fileName = userSelection.getUniqueFileName();
		var textarea;
		this.textareaContainer = new Element('div');
		this.textarea = new Element('textarea');
		this.textarea.name =  this.textarea.id = 'content';
		this.contentMainContainer = this.textareaContainer;
		this.textarea.setStyle({width:'100%'});
		this.textarea.setAttribute('wrap', 'off');
		this.element.insert(this.textareaContainer);
		this.textareaContainer.appendChild(this.textarea);
		fitHeightToBottom(this.textareaContainer, $(modal.elementName));
		this.reloadEditor('content');
		this.element.observe("editor:close", function(){
			CKEDITOR.instances.content.destroy();
		});
		this.element.observe("editor:resize", function(event){
			this.resizeEditor();
		}.bind(this));
		var destroy = function(){
			if(CKEDITOR.instances.content){
				this.textarea.value = CKEDITOR.instances.content.getData();
				CKEDITOR.instances.content.destroy();
			}
		};
		var reInit  = function(){
			CKEDITOR.replace('content', this.editorConfig);
			window.setTimeout(function(){
				this.resizeEditor();
				this.bindCkEditorEvents();
			}.bind(this), 100);
		}
		this.element.observe("editor:enterFS", destroy.bind(this));
		this.element.observe("editor:enterFSend", reInit.bind(this));
		this.element.observe("editor:exitFS", destroy.bind(this));
		this.element.observe("editor:exitFSend", reInit.bind(this));
		// LOAD FILE NOW
		window.setTimeout(this.resizeEditor.bind(this), 400);
		this.loadFileContent(fileName);
		this.bindCkEditorEvents();
		if(window.ajxpMobile){
			this.setFullScreen();
		}
		return;

	},

	bindCkEditorEvents : function(){
		if(this.isModified) return;// useless

		window.setTimeout(function(){
			var editor = CKEDITOR.instances.content;
			if(!editor) {
				return;
			}
			var setModified = function(){this.setModified(true)}.bind(this);
			var keyDown = function(event){
	 			if ( !event.data.$.ctrlKey && !event.data.$.metaKey )
	 					this.setModified(true);
	 		}.bind(this);
			// We'll save snapshots before and after executing a command.
	 		editor.on( 'afterCommandExec', setModified );
	 		// Save snapshots before doing custom changes.
	 		editor.on( 'saveSnapshot', setModified );
	 		// Registering keydown on every document recreation.(#3844)
	 		editor.on( 'contentDom', function(e)
	 		{
	 			if(!e.editor.document) return;
	 			e.editor.document.on( 'keydown', keyDown);
	 		});
	 		if(editor.document){
	 			editor.document.on('keydown', keyDown);
	 		}
	 		// FIX FOR CKEDITORS > 3.4.3, THEY INSERT DOUBLE OVERLAY
	 		editor.on( 'dialogShow' , function(e) {
	 			var covers = $$("div.cke_dialog_background_cover");
	 			if(covers.length > 1){
	 				covers[0].remove();
	 			}
	 		} );
		}.bind(this), 0);
	},

	reloadEditor : function(instanceId){
		if(!instanceId) instanceId = "code";
		if(CKEDITOR.instances[instanceId]){
			this.textarea.value = CKEDITOR.instances[instanceId].getData();
			CKEDITOR.instances[instanceId].destroy();
		}
		CKEDITOR.replace(instanceId, this.editorConfig);
	},

	resizeEditor : function(){
		var width = this.contentMainContainer.getWidth()-(Prototype.Browser.IE?0:12);
		var height = this.contentMainContainer.getHeight();
		if(CKEDITOR.instances.content){
			CKEDITOR.instances.content.resize(width,height);
		}
	},
/*-- Overridden savefile action if the image is added from a different repo --*/
	saveFile : function(){
		var connexion = this.prepareSaveConnexion();
		var value = CKEDITOR.instances.content.getData();
    connexion.addParameter('file', CKEDITOR.instances.content.config.curr_file);
    connexion.addParameter('dir', CKEDITOR.instances.content.config.curr_dir);
    connexion.addParameter('repoid', CKEDITOR.instances.content.config.repoId);
		this.textarea.value = value;
		connexion.addParameter('content', value);
		connexion.sendAsync();
	},


	parseTxt : function(transport){
		this.textarea.value = transport.responseText;
		CKEDITOR.instances.content.setData(transport.responseText);
		this.removeOnLoad(this.textareaContainer);
		this.setModified(false);
	},

/*-- custom code to get preview_url value from metadata file --*/
  preparegetMetadataConnexion : function(fileName){
		this.currentFile = fileName;
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_metadata');
		connexion.addParameter('file', fileName);
		connexion.onComplete = function(transp){
      this.url = transp.responseText;
		}.bind(this);
		this.setModified(false);
    return connexion;
	},

  getMetadata : function(fileName){
    var connexion = this.preparegetMetadataConnexion(fileName);
		connexion.sendSync();
	},

	makeDir_backup : function(){
		var connexion = this.preparemakeDir_backupConnexion();
    connexion.addParameter('file', CKEDITOR.instances.content.config.curr_file);
    connexion.addParameter('dir', CKEDITOR.instances.content.config.curr_dir);
    connexion.addParameter('repoid', CKEDITOR.instances.content.config.repoId);
    fname = 'backup';
  	connexion.addParameter('dirname', fname);
		connexion.sendAsync();
	},

	copyFile : function(){
		var connexion = this.prepareCopyConnexion();
    connexion.addParameter('dir', CKEDITOR.instances.content.config.curr_dir+'/backup');
    connexion.addParameter('src', CKEDITOR.instances.content.config.curr_file);
    connexion.addParameter('repoid', CKEDITOR.instances.content.config.repoId);
		connexion.sendAsync();
	},

  makeFileExternal : function() {
    var connexion = this.prepareMakeFileExternalConnexion();
    connexion.addParameter('repoid', CKEDITOR.instances.content.config.repoId);
		connexion.sendAsync();
  },

  logFile : function() {
		var connexion = this.preparelogFileConnexion();
    var currtime = new Date();
    var day = currtime.getDay();
    var log_filename = 'filemgr'+day+'.log';

	  connexion.addParameter('filename_log', log_filename);
    connexion.addParameter('repoid', CKEDITOR.instances.content.config.repoId);
    connexion.addParameter('original_filename', CKEDITOR.instances.content.config.curr_file);
		connexion.sendAsync();
  }
});
