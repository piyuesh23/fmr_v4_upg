/*
Copyright (c) 2003-2011, CKSource - Frederico Knabben. All rights reserved.
For licensing, see LICENSE.html or http://ckeditor.com/license
*/

CKEDITOR.editorConfig = function( config )
{
	// Define changes to default configuration here. For example:
	// config.language = 'fr';
	// config.uiColor = '#AADC6E';
    config.autoParagraph = false;
    config.toolbarCanCollapse = false;
    config.useComputedState = false;
    config.extraPlugins = 'docprops';

    config.toolbar =
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
    { name: 'tools',       items : [ 'Maximize', 'ShowBlocks','-','About' ] },
    ];

    // FIXME: have to set baseHref to the production site for images to work
};
