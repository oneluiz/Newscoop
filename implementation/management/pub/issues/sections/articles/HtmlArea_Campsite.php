<?php

function HtmlArea_Campsite($dbColumns) {
	?>	
<script type="text/javascript">
	//<![CDATA[
      _editor_url = "/javascript/htmlarea/";
      _editor_lang = "en";
	//]]>
</script>    

<!-- Load the HTMLArea file -->
<script type="text/javascript" src="/javascript/htmlarea/htmlarea.js"></script>

<!-- Special Campsite functionality -->
<script type="text/javascript">
function CampsiteSubhead(editor, objectName, object) {
	parent = editor.getParentElement();
	if ((parent.tagName.toLowerCase() == "span") && 
		(parent.className.toLowerCase()=="campsite_subhead")) {
		editor.selectNodeContents(parent);
		//editor._doc.execCommand("unlink", false, null);
		editor.updateToolbar();
		return false;
	}
	else {
		editor.surroundHTML('<span class="campsite_subhead">', '</span>');
	}
} // fn CampsiteSubhead

/** 
 * Handler for creating an internal campsite link.
 * This is a copy of the _createlink function, except that it calls 
 * a different popup window.
 */
function CampsiteInternalLink(editor, objectName, object, link) {
	var outparam = null;
	if (typeof link == "undefined") {
		link = editor.getParentElement();
		if (link && !/^a$/i.test(link.tagName))
			link = null;
	}
	popupWindowTarget = "campsite_internal_link.php";
	if (link) {
		outparam = {
		f_href   : HTMLArea.is_ie ? editor.stripBaseURL(link.href) : link.getAttribute("href"),
		f_title  : link.title,
		f_target : link.target
		};
		// Pass the arguments to the popup window so that it
		// can populate the dropdown controls.
		popupWindowTarget += "?" + outparam["f_href"].replace("campsite_internal_link?", "");
	}
	editor._popupDialog(popupWindowTarget, 
		function(param) {
			// This function is called when the OK button
			// is clicked in the popup window.
			if (!param)
				return false;
			var a = link;
			if (!a) try {
				// Create a link normally in the editor
				editor._doc.execCommand("createlink", false, param.f_href);
				a = editor.getParentElement();
				var sel = editor._getSelection();
				var range = editor._createRange(sel);
				if (!HTMLArea.is_ie) {
					a = range.startContainer;
					if (!/^a$/i.test(a.tagName)) {
						a = a.nextSibling;
						if (a == null)
							a = range.startContainer.parentNode;
					}
				}
			} catch(e) {}
			else {
				// Unlink the text if it is linked already
				var href = param.f_href.trim();
				editor.selectNodeContents(a);
				if (href == "") {
					editor._doc.execCommand("unlink", false, null);
					editor.updateToolbar();
					return false;
				}
				else {
					a.href = href;
				}
			}
			if (!(a && /^a$/i.test(a.tagName)))
				return false;
			a.target = param.f_target.trim();
			a.title = param.f_title.trim();
			editor.selectNodeContents(a);
			editor.updateToolbar();
		}, 
		outparam);
};

//<![CDATA[
HTMLArea.loadPlugin("ImageManager");
HTMLArea.loadPlugin("TableOperations");
HTMLArea.loadPlugin("ListType");

initdocument = function () {
	<?php
	foreach ($dbColumns as $dbColumn) {	
		if (stristr($dbColumn->getType(), "blob")) {
			?>
			var editor = new HTMLArea("<?php print $dbColumn->getName(); ?>");
 			var config = editor.config;
 			// Import our custom CSS - watch out for newlines though!
 			// They will break the editor.
			config.pageStyle = "<?php echo str_replace("\n", "", file_get_contents("article_stylesheet.css")); ?>";
	 		config.registerButton({
	 			// The ID of the button.
				id        : "campsite-subhead", 
				// The tooltip.
				tooltip   : "Subhead",
				// Image to be displayed in the toolbar.
				image     : "/javascript/htmlarea/images/campsite_subhead.gif",
				// TRUE = enabled in text mode
				// FALSE = disabled in text mode
				textMode  : false,
				// Called when the button is clicked.
				action    : CampsiteSubhead,
				// The button will be disabled if outside 
				// the specified element.
				context   : ''
				});
				
	 		config.registerButton({
	 			// The ID of the button.
				id        : "campsite-internal-link", 
				// The tooltip.
				tooltip   : "Internal Link",
				// Image to be displayed in the toolbar.
				image     : "/javascript/htmlarea/images/campsite_internal_link.gif",
				// TRUE = enabled in text mode
				// FALSE = disabled in text mode
				textMode  : false,
				// Called when the button is clicked.
				action    : CampsiteInternalLink,
				// The button will be disabled if outside 
				// the specified element.
				context   : ''
				});

			config.toolbar = [
				[ "fontname", "space",
				  "fontsize", "space",
				  "formatblock", "space",
				  "bold", "italic", "underline", "strikethrough", "separator",
				  "forecolor", "hilitecolor", "separator",
				  "subscript", "superscript"
				  ],
		
				[ "justifyleft", "justifycenter", "justifyright", "justifyfull", "separator",
				  "outdent", "indent", "separator",
				  "unorderedlist", "orderedlist", "separator", 
				  "inserthorizontalrule", "campsite-subhead", "campsite-internal-link", "createlink", "insertimage" ],
				  ["copy", "cut", "paste", "space", "separator", "undo", "redo", "separator", "lefttoright", "righttoleft", "separator", "htmlmode", "popupeditor"]
			];
			editor.registerPlugin(ListType);
		  	editor.registerPlugin(TableOperations);
			editor.generate();
			<?php
		}
	}
	?>
} // fn initDocument

function addEvent(obj, evType, fn) { 
	if (obj.addEventListener) { 
		obj.addEventListener(evType, fn, true); 
		return true; 
	} 
    else if (obj.attachEvent) {  
    	var r = obj.attachEvent("on"+evType, fn);  
    	return r;  
    } 
    else {  
    	return false; 
    } 
}  // fn addEvent

addEvent(window, 'load', initdocument);
//]]>
</script>
<?php
} // fn HtmlArea_Campsite
?>