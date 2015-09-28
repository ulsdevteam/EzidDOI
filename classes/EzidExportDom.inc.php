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

	//
	// Public methods
	//
	/**
	 * @see DOIExportDom::generate()
	 */
	function &generate(&$objects) {
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
				$this->_appendIssueXML($doc, $journal, $issue, $pubObjects['articlesByIssue'], $bodyNode);
			} else {
				$article =& $pubObjects['article'];
				if ($article->getPubId('doi')) {
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
	 * @param $articlesByIssue Array
	 * @param $bodyNode XMLNode
	 */
	function _appendIssueXML(&$doc, &$journal, &$issue, $articlesByIssue, &$bodyNode) {

		reset($articlesByIssue);
		$firstArticleKey = key($articlesByIssue);

		foreach ($articlesByIssue as $key => $article) {
			$sectionId = $article->getSectionId();
			$sectionDao =& DAORegistry::getDAO('SectionDAO');
			$section =& $sectionDao->getSection($sectionId);

			if ($key == $firstArticleKey) {
				// Create the journal node
				$journalNode =& XMLCustomWriter::createElement($doc, 'journal');
				$journalMetadataNode =& $this->_generateJournalMetadataDom($doc, $journal);
				XMLCustomWriter::appendChild($journalNode, $journalMetadataNode);
				// Create the journal_issue node
				$journalIssueNode =& $this->_generateJournalIssueDom($doc, $journal, $issue, $section, $article);
				// Create the doi_data node
				if ($issue->getDatePublished() && $issue->getPubId('doi')) {
					$issueDoiNode =& $this->_generateDOIdataDom($doc, $issue->getPubId('doi'), Request::url($journal->getPath(), 'issue', 'view', $issue->getBestIssueId($journal)));
					XMLCustomWriter::appendChild($journalIssueNode, $issueDoiNode);
				}
				XMLCustomWriter::appendChild($journalNode, $journalIssueNode);
			}

			// Create the article node
			$journalArticleNode =& $this->_generateJournalArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($journalNode, $journalArticleNode);
			XMLCustomWriter::appendChild($bodyNode, $journalNode);
		}
	}

	/**
	 * Generate and append the XML per article
	 * @param $doc XMLNode
	 * @param $journal Journal
	 * @param $issue Issue
	 * @param $article Article
	 * @param $bodyNode XMLNode
	 */
	function _appendArticleXML(&$doc, &$journal, &$issue, &$article, &$bodyNode) {
		$sectionId = $article->getSectionId();
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$section =& $sectionDao->getSection($sectionId);
		// Create the journal node
		$journalNode =& XMLCustomWriter::createElement($doc, 'journal');
		$journalMetadataNode =& $this->_generateJournalMetadataDom($doc, $journal);
		XMLCustomWriter::appendChild($journalNode, $journalMetadataNode);
		// Create the journal_issue node
		$journalIssueNode =& $this->_generateJournalIssueDom($doc, $journal, $issue, $section, $article);
		XMLCustomWriter::appendChild($journalNode, $journalIssueNode);
		// Create the article node
		$journalArticleNode =& $this->_generateJournalArticleDom($doc, $journal, $issue, $section, $article);
		// DOI data node
		$articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
		$DOIdataNode =& $this->_generateDOIdataDom($doc, $article->getPubId('doi'), Request::url($journal->getPath(), 'article', 'view', $article->getBestArticleId()), $articleGalleyDao->getGalleysByArticle($article->getId()));
		XMLCustomWriter::appendChild($journalArticleNode, $DOIdataNode);
		/* Component list (supplementary files) */
		$componentListNode =& $this->_generateComponentListDom($doc, $journal, $article);
		if ($componentListNode) {
			XMLCustomWriter::appendChild($journalArticleNode, $componentListNode);
		}
		XMLCustomWriter::appendChild($journalNode, $journalArticleNode);
		XMLCustomWriter::appendChild($bodyNode, $journalNode);
	}


	/**
	 * Generate journal issue tag to accompany every article
	 * @param $doc XMLNode
	 * @param $journal Journal
	 * @param $issue Issue
	 * @param $section Section
	 * @param $article Article
	 * @return XMLNode
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

		return $journalIssueNode;
	}

	/**
	 * Generate the journal_article node (the heart of the file).
	 * @param $doc XMLNode
	 * @param $journal Journal
	 * @param $issue Issue
	 * @param $section Section
	 * @param $article Article
	 * @return XMLNode
	 */
	function &_generateJournalArticleDom(&$doc, &$journal, &$issue, &$section, &$article) {
		// Create the base node
		$journalArticleNode =& XMLCustomWriter::createElement($doc, 'journal_article');
		XMLCustomWriter::setAttribute($journalArticleNode, 'publication_type', 'full_text');
		XMLCustomWriter::setAttribute($journalArticleNode, 'metadata_distribution_opts', 'any');
		/* Titles */
		$titlesNode =& XMLCustomWriter::createElement($doc, 'titles');
		XMLCustomWriter::createChildWithText($doc, $titlesNode, 'title', $article->getTitle($article->getLocale()));
		XMLCustomWriter::appendChild($journalArticleNode, $titlesNode);
		/* AuthorList */
		$contributorsNode =& XMLCustomWriter::createElement($doc, 'contributors');
		$isFirst = true;
		foreach ($article->getAuthors() as $author) {
			$authorNode =& $this->_generateAuthorDom($doc, $author, $isFirst);
			$isFirst = false;
			XMLCustomWriter::appendChild($contributorsNode, $authorNode);
		}
		XMLCustomWriter::appendChild($journalArticleNode, $contributorsNode);
		/* Abstracts */
		if ($article->getAbstract($journal->getPrimaryLocale())) {
			$abstractNode =& XMLCustomWriter::createElement($doc, 'jats:abstract');
			XMLCustomWriter::createChildWithText($doc, $abstractNode, 'jats:p', $article->getAbstract($journal->getPrimaryLocale()));
			XMLCustomWriter::appendChild($journalArticleNode, $abstractNode);
		}
		/* publication date of article */
		if ($article->getDatePublished()) {
			$publicationDateNode =& $this->_generatePublisherDateDom($doc, $article->getDatePublished());
			XMLCustomWriter::appendChild($journalArticleNode, $publicationDateNode);
		}
		/* publisher_item is the article pages */
		if ($article->getPages() != '') {
			$pageNode =& XMLCustomWriter::createElement($doc, 'pages');
			// extract the first page for the first_page element, store the remaining bits in otherPages,
			// after removing any preceding non-numerical characters.
			if (preg_match('/^[^\d]*(\d+)\D*(.*)$/', $article->getPages(), $matches)) {
				$firstPage = $matches[1];
				$otherPages = $matches[2];
				XMLCustomWriter::createChildWithText($doc, $pageNode, 'first_page', $firstPage);
				if ($otherPages != '') {
					XMLCustomWriter::createChildWithText($doc, $pageNode, 'other_pages', $otherPages);
				}
			}
			XMLCustomWriter::appendChild($journalArticleNode, $pageNode);
		}
		/* License URL */
		if ($article->getLicenseUrl()) {
			$licenseNode =& XMLCustomWriter::createElement($doc, 'ai:program');
			XMLCustomWriter::setAttribute($licenseNode, 'name', 'AccessIndicators');
			XMLCustomWriter::createChildWithText($doc, $licenseNode, 'ai:license_ref', $article->getLicenseUrl());
			XMLCustomWriter::appendChild($journalArticleNode, $licenseNode);
		}

		return $journalArticleNode;
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
