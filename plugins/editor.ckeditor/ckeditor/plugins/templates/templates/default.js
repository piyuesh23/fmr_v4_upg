/*
Copyright (c) 2003-2011, CKSource - Frederico Knabben. All rights reserved.
For licensing, see LICENSE.html or http://ckeditor.com/license
*/

CKEDITOR.addTemplates('default',{imagesPath:CKEDITOR.getUrl(CKEDITOR.plugins.getPath('templates')+'templates/images/'),templates:[ {title:'HighWire XHTML',image:'template1.gif',description:'Default XHTML starter file with content markers.',html:'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:hwui="http://schema.highwire.org/Site/UI"> <head> <title></title><!-- Insert title above! --></head> <body> <div id="begin-content-marker"><!-- start of content marker div --></div> <!-- * Begin editable area * --> <p>Your text here.</p> <!-- * End editable area * --> <div id="end-content-marker"><!-- start of content marker div --></div> </body> </html>'}]});
