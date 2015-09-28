<?php

/**
 * @file plugins/importexport/ezid/classes/EzidExportDom.inc.php
 *
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class EzidExportDom
 * @ingroup plugins_importexport_ezid_classes
 *
 * @brief CrossRef XML export format implementation, modified for EZID to truncate the doi_data element.
 */


if (!class_exists('CrossRefExportDom')) { // Bug #7848
	import('plugins.importexport.crossref.classes.CrossRefExportDom');
}

class EzidExportDom extends CrossRefExportDom {

	/**
	 * Constructor
	 * @param $request Request
	 * @param $plugin DOIExportPlugin
	 * @param $journal Journal
	 * @param $objectCache PubObjectCache
	 */
	function EzidExportDom(&$request, &$plugin, &$journal, &$objectCache) {
		// Configure the DOM.
		parent::CrossRefExportDom($request, $plugin, $journal, $objectCache);
	}

	/**
	 * Generate doi_data element - this is what assigns the DOI
	 * @param $doc XMLNode
	 * @param $DOI string
	 * @param $url string
	 * @param $galleys array
	 */
	function &_generateDOIdataDom(&$doc, $DOI, $url, $galleys = null) {
		$request = Application::getRequest();
		$journal = $request->getJournal();
		$DOIdataNode =& XMLCustomWriter::createElement($doc, 'doi_data');
		XMLCustomWriter::createChildWithText($doc, $DOIdataNode, 'doi', $DOI);
		XMLCustomWriter::createChildWithText($doc, $DOIdataNode, 'resource', $url);
		return $DOIdataNode;
	}
}

?>
