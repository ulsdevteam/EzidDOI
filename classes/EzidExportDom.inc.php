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
	 * EZID only allows one DOI; this will indicate which one will be output in the Crossref XML
	 * @var string
	 */
	var $_allowed_doi = NULL;

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

	//
	// Public methods
	//
	/**
	 * @see DOIExportDom::generate()
	 * Except that EZID requires only one object be processed at a time
	 */
	function &generate(&$objects) {
		if (count($objects) > 1) {
			assert($false);
		}
		$object = $objects[0];
		$pubObjects =& $this->retrievePublicationObjects($object);
		extract($pubObjects);
		if (is_a($object, 'Issue')) {
			$pubObj =& $pubObjects['issue'];
		} else {
			$pubObj =& $pubObjects['article'];
		}
		if ($pubObj->getPubId('doi')) {
			$this->_allowed_doi = $pubObj->getPubId('doi');
		}

		$journal =& $this->getJournal();

		// Create the XML document and its root element.
		$doc =& $this->getDoc();
		$rootElement =& $this->rootElement();
		XMLCustomWriter::appendChild($doc, $rootElement);

		// Create Head Node and all parts inside it
		$head =& $this->_generateHeadDom($doc, $journal);
		// attach it to the root node
		XMLCustomWriter::appendChild($rootElement, $head);

		// the body node contains everything
		$bodyNode =& XMLCustomWriter::createElement($doc, 'body');
		XMLCustomWriter::appendChild($rootElement, $bodyNode);

		foreach($objects as $object) {
			// Retrieve required publication objects.
			$pubObjects =& $this->retrievePublicationObjects($object);
			extract($pubObjects);
			$issue =& $pubObjects['issue'];
			if (is_a($object, 'Issue')) {
				if ($issue->getPubId('doi') || $this->getPluginSetting('shoulder')) {
					$this->_appendIssueXML($doc, $journal, $issue, $bodyNode);
				}
			} else {
				$article =& $pubObjects['article'];
				if ($article->getPubId('doi') || $this->getPluginSetting('shoulder')) {
					$this->_appendArticleXML($doc, $journal, $issue, $article, $bodyNode);
				}
			}
		}

		return $doc;
	}

	/**
	 * Generate and append the XML per issue
	 * @param $doc XMLNode
	 * @param $journal Journal
	 * @param $issue Issue
	 * @param $bodyNode XMLNode
	 */
	function _appendIssueXML(&$doc, &$journal, &$issue, &$bodyNode) {
		$section = null;

		// Create the journal node
		$journalNode =& XMLCustomWriter::createElement($doc, 'journal');
		$journalMetadataNode =& $this->_generateJournalMetadataDom($doc, $journal);
		XMLCustomWriter::appendChild($journalNode, $journalMetadataNode);

		// Create the journal_issue node
		$journalIssueNode =& $this->_generateJournalIssueDom($doc, $journal, $issue, $section, $article);
		XMLCustomWriter::appendChild($journalNode, $journalIssueNode);

		XMLCustomWriter::appendChild($bodyNode, $journalNode);
	}

	/**
	 * Generate doi_data element - this is what assigns the DOI
	 * EZID allows only one doi_data element per submission
	 * @param $doc XMLNode
	 * @param $DOI string
	 * @param $url string
	 * @param $galleys array
	 */
	function &_generateDOIdataDom(&$doc, $DOI, $url, $galleys = null) {
		if ($this->_allowed_doi === $DOI || ($this->_allowed_doi === NULL && $this->getPluginSetting('shoulder'))) {
			// Not only is this the only allowed doi_data element, it can only occur once.
			$this->_allowed_doi = '';
			// Disallow galleys to prevent creation of the collection element
			return parent::_generateDOIdataDom($doc, $DOI, $url, null);
		}
		return XMLCustomWriter::createComment($doc, '');
	}

	/**
	 * Generate journal issue tag to accompany every article
	 * @param $doc XMLNode
	 * @param $journal Journal
	 * @param $issue Issue
	 * @param $section Section
	 * @param $article Article
	 * @return XMLNode
	 * @todo Can a change be pushed upstream to PKP in CrossRefExportDom so this override is not needed?
	 */
	function &_generateJournalIssueDom(&$doc, &$journal, &$issue, &$section, &$article) {
		$journalIssueNode =& XMLCustomWriter::createElement($doc, 'journal_issue');

		if ($issue->getDatePublished()) {
			$publicationDateNode =& $this->_generatePublisherDateDom($doc, $issue->getDatePublished());
			XMLCustomWriter::appendChild($journalIssueNode, $publicationDateNode);
		}

		if ($issue->getVolume()){
			$journalVolumeNode =& XMLCustomWriter::createElement($doc, 'journal_volume');
			XMLCustomWriter::appendChild($journalIssueNode, $journalVolumeNode);
			XMLCustomWriter::createChildWithText($doc, $journalVolumeNode, 'volume', $issue->getVolume());
		}
		if ($issue->getNumber()) {
			XMLCustomWriter::createChildWithText($doc, $journalIssueNode, 'issue', $issue->getNumber());
		}

		// Contra CrossRefExportDom::_generateJournalIssueDom, we do not need a stored DOI
		if ($issue->getDatePublished()) {
			$issueDoiNode =& $this->_generateDOIdataDom($doc, $issue->getPubId('doi'), Request::url($journal->getPath(), 'issue', 'view', $issue->getBestIssueId($journal)));
			XMLCustomWriter::appendChild($journalIssueNode, $issueDoiNode);
		}

		return $journalIssueNode;
	}
}

?>
