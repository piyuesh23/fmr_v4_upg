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

/**
 * Container for a Repository.
 */
Class.create("Repository", {

	/**
	 * @var String
	 */
	id:undefined,
	/**
	 * @var String
	 */
	label:'No Repository',
	/**
	 * @var String
	 */
	icon:'',
	/**
	 * @var String
	 */
	accessType:'',
	/**
	 * @var object
	 */
	nodeProviderDef: null,
	/**
	 * @var ResourcesManager
	 */
	resourcesManager:undefined,
	/**
	 * @var Boolean
	 */
	allowCrossRepositoryCopy:false,
    /**
     * @var Boolean
     */
    userEditable:false,
	/**
	 * @var String
	 */
	slug:'',
    /**
     * @var String
     */
    owner:'',

	/**
	 * Constructor
	 * @param id String
	 * @param xmlDef XMLNode
	 */
	initialize:function(id, xmlDef){
		if(MessageHash){
			this.label = MessageHash[391];
		}
		this.id = id;
		this.icon = ajxpResourcesFolder+'/images/actions/16/network-wired.png';
/*-- cutomixztions to populate header and footer on complete refresh --*/
    if(ajaxplorer.user != null) {
      var repo_label = ajaxplorer.user.repositories.get(ajaxplorer.user.activeRepository).label;
      this.get_pubname(repo_label);
      this.get_publish_url(repo_label);
      this.get_preview_repo(repo_label);
      this.get_jname(repo_label);
    }
		this.resourcesManager = new ResourcesManager();
		if(xmlDef) this.loadFromXml(xmlDef);
	},

	/**
	 * @returns String
	 */
	getId : function(){
		return this.id;
	},

	/**
	 * @returns String
	 */
	getLabel : function(){
		return this.label;
	},
	/**
	 * @param label String
	 */
	setLabel : function(label){
		this.label = label;
	},

	/**
	 * @returns String
	 */
	getIcon : function(){
		return this.icon;
	},
	/**
	 * @param label String
	 */
	setIcon : function(icon){
		this.icon = icon;
	},

    /**
     * @return String
     */
    getOwner : function(){
        return this.owner;
    },

	/**
	 * @returns String
	 */
	getAccessType : function(){
		return this.accessType;
	},
	/**
	 * @param label String
	 */
	setAccessType : function(access){
		this.accessType = access;
	},

	/**
	 * Triggers ResourcesManager.load
	 */
	loadResources : function(){
		this.resourcesManager.load();
	},

	/**
	 * @returns Object
	 */
	getNodeProviderDef : function(){
		return this.nodeProviderDef;
	},

	/**
	 * @param slug String
	 */
	setSlug : function(slug){
		this.slug = slug;
	},

	/**
	 * @returns String
	 */
	getSlug : function(){
		return this.slug;
	},

    getOverlay : function(){
        return (this.getOwner() ? resolveImageSource("shared.png", "/images/overlays/ICON_SIZE", 8):"");
    },

	/**
	 * Parses XML Node
	 * @param repoNode XMLNode
	 */
	loadFromXml: function(repoNode){
		if(repoNode.getAttribute('allowCrossRepositoryCopy') && repoNode.getAttribute('allowCrossRepositoryCopy') == "true"){
			this.allowCrossRepositoryCopy = true;
		}
		if(repoNode.getAttribute('user_editable_repository') && repoNode.getAttribute('user_editable_repository') == "true"){
			this.userEditable = true;
		}
		if(repoNode.getAttribute('access_type')){
			this.setAccessType(repoNode.getAttribute('access_type'));
		}
		if(repoNode.getAttribute('repositorySlug')){
			this.setSlug(repoNode.getAttribute('repositorySlug'));
		}
		if(repoNode.getAttribute('owner')){
			this.owner = repoNode.getAttribute('owner');
		}
		for(var i=0;i<repoNode.childNodes.length;i++){
			var childNode = repoNode.childNodes[i];
			if(childNode.nodeName == "label"){
				this.setLabel(childNode.firstChild.nodeValue);
			}else if(childNode.nodeName == "client_settings"){
                if(childNode.getAttribute('icon_tpl_id')){
                    this.setIcon(window.ajxpServerAccessPath+'&get_action=get_user_template_logo&template_id='+childNode.getAttribute('icon_tpl_id')+'&icon_format=small');
                }else{
                    this.setIcon(childNode.getAttribute('icon'));
                }
				for(var j=0; j<childNode.childNodes.length;j++){
					var subCh = childNode.childNodes[j];
					if(subCh.nodeName == 'resources'){
						this.resourcesManager.loadFromXmlNode(subCh);
					}else if(subCh.nodeName == 'node_provider'){
						var nodeProviderName = subCh.getAttribute("ajxpClass");
						var nodeProviderOptions = subCh.getAttribute("ajxpOptions").evalJSON();
						this.nodeProviderDef = {name:nodeProviderName, options:nodeProviderOptions};
					}
				}
			}
		}
	},

/*-- Custom highwire funcitons to get repository specific data. --*/
  get_pubname: function(repo_label) {
    repo_label = 'metadata_'+repo_label+'.xml';
    var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_pubname');
		connexion.addParameter('file', repo_label);
    connexion.onComplete = function(transport){
      var response = transport.responseText;
      var response_pubname = response.substring(45, response.length);
    var pubname = response_pubname.replace('</tree>', '');
      var header = document.getElementById('optional_header_div');
      header.getElementsByTagName('h3')[0].innerHTML = pubname;
		}.bind(this);
    connexion.sendAsync();
  },

  get_jname: function(repo_label) {
    repo_label = 'metadata_'+repo_label+'.xml';
    var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_jname');
		connexion.addParameter('file', repo_label);
    connexion.onComplete = function(transport){
      var response = transport.responseText;
      var response_jname_sitecode = response.substring(45, response.length);
      var jname = response_jname_sitecode.substring(0, response_jname_sitecode.indexOf('|'));
      var sitecode_tree = response_jname_sitecode.substring(response_jname_sitecode.indexOf('|')+1, response_jname_sitecode.length);
      var sitecode = sitecode_tree.replace('</tree>', '');
      if((sitecode != '')&&(jname != '')) {
        var footer = document.getElementById('optional_bottom_div');
//        var header = document.getElementById('optional_header_div');
        footer.getElementsByTagName('span')[1].innerHTML = '<a href="'+jname+'">'+sitecode+'</a>';
//        header.getElementsByTagName('h2')[0].innerHTML = '<a href="'+jname+'">'+sitecode+'</a>';
      }
      else {
        var footer = document.getElementById('optional_bottom_div');
//        var header = document.getElementById('optional_header_div');
        footer.getElementsByTagName('span')[1].innerHTML = '<a href="#">Journal Name</a>';
//        header.getElementsByTagName('h2')[0].innerHTML = '<a href="#">Journal Name</a>';
      }
		}.bind(this);
    connexion.sendAsync();
  },

  get_preview_repo: function(repo_label) {
    repo_label = 'metadata_'+repo_label+'.xml';
    var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_preview_repo');
		connexion.addParameter('file', repo_label);
    connexion.onComplete = function(transport){
      var response = transport.responseText;
      var response_preview_repo = response.substring(45, response.length);
    var preview_repo = response_preview_repo.replace('</tree>', '');
    if(preview_repo == "false") {
      document.getElementById('preview-repo-header').innerHTML = '<span style="color:#fff; background:red;">You are editing files on production site directly.</span>';
      if(Prototype.Browser.IE) {
        if(ajaxplorer.set_height == undefined)
          ajaxplorer.set_height = false;
        if(ajaxplorer.set_height == false) {
          var height_browser =document.getElementById("browser").style.height;
          height_browser = height_browser.substring(0, height_browser.indexOf('px'));
          document.getElementById("browser").style.height = height_browser - 20;
          var height_splitter =document.getElementById("vertical_splitter").style.height;
          height_splitter = height_splitter.substring(0, height_splitter.indexOf('px'));
          document.getElementById("vertical_splitter").style.height = height_splitter - 20;
          ajaxplorer.set_height = true;
        }
      }
    }
    else {
      document.getElementById('preview-repo-header').innerHTML = '';
    }
		}.bind(this);
    connexion.sendAsync();
  },

  get_publish_url: function(repo_label) {
    repo_label = 'metadata_'+repo_label+'.xml';
    var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_publish_url');
		connexion.addParameter('file', repo_label);
    connexion.onComplete = function(transport){
      var response = transport.responseText;
      var response_publish_url = response.substring(45, response.length);
      var publish_url = response_publish_url.replace('</tree>', '');
      var header = document.getElementById('optional_bottom_div');
      header.getElementsByTagName('span')[0].innerHTML = '<a href="'+publish_url+'">JAMS</a>';
		}.bind(this);
    connexion.sendAsync();
  }
});
