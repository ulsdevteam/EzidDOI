<?php

/**
 * @file plugins/importexport/ezid/ezidExportPlugin.inc.php
 *
 * Copyright (c) 2013-2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ezidExportPlugin
 * @ingroup plugins_importexport_ezid
 *
 * @brief ezid export/registration plugin.
 */


if (!class_exists('CrossRefExportPlugin')) { // Bug #7848
  import('plugins.importexport.crossref.CrossRefExportPlugin');
}

import('lib.pkp.classes.webservice.WebService');

// EZID API
define('EZID_API_RESPONSE_CREATED', 201);
define('EZID_API_RESPONSE_OK', 200);
define('EZID_API_MINT_URL', 'https://ezid.cdlib.org/shoulder/doi:');
define('EZID_API_CRUD_URL', 'https://ezid.cdlib.org/id/doi:');

class EzidRegisterPlugin extends CrossRefExportPlugin {

  //
  // Constructor
  //
  function EzidRegisterPlugin() {
    parent::CrossRefExportPlugin();
  }

  /**
   * Skip the Crossref register() method
   * @see PKPPlugin::register()
   */
  function register($category, $path) {
    $success = DOIExportPlugin::register($category, $path);
    return $success;
  }
  //
  // Implement template methods from ImportExportPlugin
  //
  /**
   * @see ImportExportPlugin::getName()
   */
  function getName() {
    return 'EzidRegisterPlugin';
  }

  /**
   * @see ImportExportPlugin::getDisplayName()
   */
  function getDisplayName() {
    return __('plugins.importexport.ezid.displayName');
  }

  /**
   * @see ImportExportPlugin::getDescription()
   */
  function getDescription() {
    return __('plugins.importexport.ezid.description');
  }

  //
  // Implement template methods from DOIExportPlugin
  //
  /**
   * @see DOIExportPlugin::getPluginId()
   */
  function getPluginId() {
    return 'ezid';
  }

  /**
   * @see DOIExportPlugin::getSettingsFormClassName()
   */
  function getSettingsFormClassName() {
    return 'EzidSettingsForm';
  }

  /**
   * @see DOIExportPlugin::displayAllUnregisteredObjects()
   */
  function displayAllUnregisteredObjects(&$templateMgr, &$journal) {
    // Prepare information specific to this plug-in.
    $this->setBreadcrumbs(array(), true);
    AppLocale::requireComponents(array(LOCALE_COMPONENT_PKP_SUBMISSION));

    // Prepare and display the template.
    $templateMgr->assign_by_ref('articles', $this->_getUnregisteredArticles($journal));
    $templateMgr->display($this->getTemplatePath() . 'unregisteredArticles.tpl');
  }

  /**
   * @see DOIExportPlugin::generateExportFiles()
   */
  function generateExportFiles(&$request, $exportType, &$objects, $targetPath, &$journal, &$errors) {
    // Additional locale file.
    AppLocale::requireComponents(array(LOCALE_COMPONENT_OJS_EDITOR));

    $this->import('classes.EzidExportDom');
    $dom = new EzidExportDom($request, $this, $journal, $this->getCache());
    $doc =& $dom->generate($objects);
    if ($doc === false) {
      $errors =& $dom->getErrors();
      return false;
    }

    // Write the result to the target file.
    $exportFileName = $this->getTargetFileName($targetPath, $exportType);
    file_put_contents($exportFileName, XMLCustomWriter::getXML($doc));
    $generatedFiles = array($exportFileName => &$objects);
    return $generatedFiles;
  }

    /**
   * Register publishing objects.
   *
   * @param $request Request
   * @param $exportSpec array An array with DOI_EXPORT_* constants as keys and
   *  object ids as values.
   * @param $journal Journal
   *
   * @return boolean|array True for success or an array of error messages.
   */
  function registerObjects(&$request, $exportSpec, &$journal) {
    // Registering can take a long time.
    @set_time_limit(0);

    // Get the target directory.
    $result = $this->_getExportPath();
    if (is_array($result)) return $result;
    $exportPath = $result;
    // Run through the export spec and generate XML files and register dois for the corresponding
    // objects.
    $errors = array();

    // Run through the export types and generate the corresponding
    // export files.
    foreach($exportSpec as $exportType => $objectIds) {
      // Normalize the object id(s) into an array.
      if (is_scalar($objectIds)) $objectIds = array($objectIds);

      // Retrieve the object(s).
      $objects =& $this->_getObjectsFromIds($exportType, $objectIds, $journal->getId(), $errors);
      if (empty($objects)) {
        return $errors;
      }
      $result = $this->processRegisterObjects($request, $exportType, $objects, $exportPath, $journal, $errors);
      if ($result !== true) {
        return $result;
      }
    }
    return true;
  }

  function processRegisterObjects(&$request, $exportType, &$objects, $exportPath, &$journal, &$errors) {
    // Run through the export types and generate the corresponding
    // export files.
    $exportFiles = array();

    // Export the object(s) to file(s).
    // Additional locale file.
    AppLocale::requireComponents(array(LOCALE_COMPONENT_OJS_EDITOR));

    $this->import('classes.EzidExportDom');
    foreach($objects as $object) {
      // Issues will pre-process articles within the issue
      if (is_a($object, 'Issue')) {
        // Fetch the articles
        $publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
        $issueArticles =& $publishedArticleDao->getPublishedArticles($object->getId());
        $articles = array();
        // Extract the article IDs
        foreach ($issueArticles as $issueArticle) {
          if ($issueArticle->getPubId('doi') || $this->getSetting($journal->getId(), 'shoulder')) {
            array_push($articles, $issueArticle->getId());
          }
        }
        // Package these articles as objects
        $articleObjects =& $this->_getObjectsFromIds(DOI_EXPORT_ARTICLES, $articles, $journal->getId(), $errors);
        if (empty($articleObjects)) {
          return $errors;
        }
        // Call this same function for the articles
        $articleResult = $this->processRegisterObjects($request, DOI_EXPORT_ARTICLES, $articleObjects, $exportPath, $journal, $errors);
        if ($articleResult !== true) {
          // If an error occurred, don't finish processing the Issue registration
          return $articleResult;
        }
      }
      // Write the result to the target file.
      $exportFileName = $this->getTargetFileName($exportPath, $exportType, $object->getId());
      array_push($exportFiles, $exportFileName);

      $dom = new EzidExportDom($request, $this, $journal, $this->getCache());
      $object_array = array();
      array_push($object_array, $object);
      $doc =& $dom->generate($object_array);
      if ($doc === false) {
        $errors =& $dom->getErrors();
        return $errors;
      }

      file_put_contents($exportFileName, XMLCustomWriter::getXML($doc));
      $result = $this->registerDoi($request, $journal, $object, $exportFileName);
      if ($result !== true) {
        $this->cleanTmpfiles($exportPath, $exportFiles);
        return $result;
      }
    }
    // Remove all temporary files.
    $this->cleanTmpfiles($exportPath, $exportFiles);
    return true;
  }

  /**
   * @see DOIExportPlugin::registerDoi()
   */
  function registerDoi(&$request, &$journal, &$object, $filename) {
    $shoulder = $this->getSetting($journal->getId(), 'shoulder');
    // Transmit CrossRef XML metadata.
    assert(is_readable($filename));
    $xml = simplexml_load_file($filename);
    assert($xml !== false && !empty($xml));
    $targetUrl = $xml->body->journal->journal_article->doi_data->resource->__toString();
    $payload = file_get_contents($filename);
    assert($payload !== false && !empty($payload));
    // we only consider articles and issues here
    $result = true;

    if (is_a($object, 'PublishedArticle') || is_a($object, 'Issue') ) {

      $input = "_profile: crossref" . PHP_EOL;
      $input .= "_crossref: yes" . PHP_EOL;
      $input .= "_target: " . $targetUrl . PHP_EOL;
      $input .= "crossref: " . $this->_doiMetadataEscape($payload) . PHP_EOL;


      // TODO: SHOW BOTH DATACITE METADATA AS WELL
      //5 required datacite fields:
      $input .= "datacite.creator: ";
      if (is_a($object, 'PublishedArticle')) {
        foreach ($object->getAuthors() as $author) {
          $input .= $author->getLastName() . ", " . $author->getFirstName() . " " . $author->getMiddleName() . "; ";
        }
      }
      $input .= PHP_EOL;
      $input .= "datacite.title: " . $object->getLocalizedTitle() . PHP_EOL;
      $input .= "datacite.publisher: " . $journal->getSetting('publisherInstitution') . PHP_EOL;
      $input .= "datacite.publicationyear: " . date('Y', strtotime($object->getDatePublished())) . PHP_EOL;
      $input .= "datacite.resourcetype: " . $object->getLocalizedData('type'). PHP_EOL;
      if ($object->getData('ezid::registeredDoi')) {
        $webServiceRequest = new WebServiceRequest(EZID_API_CRUD_URL . $object->getData('ezid::registeredDoi'), $input, 'POST');
        $expectedResponse = EZID_API_RESPONSE_OK;
      } else {
        if ($shoulder){
          $webServiceRequest = new WebServiceRequest(EZID_API_MINT_URL . $shoulder, $input, 'POST');
          $expectedResponse = EZID_API_RESPONSE_CREATED;
        }
        else {
          $webServiceRequest = new WebServiceRequest(EZID_API_CRUD_URL . $object->getPubId('doi'), $input, 'PUT');
          $expectedResponse = EZID_API_RESPONSE_CREATED;
        }

      }
      $webServiceRequest->setHeader('Content-Type', 'text/plain; charset=UTF-8');
      $webServiceRequest->setHeader('Content-Length', strlen($input));

      $webService = new WebService();
      $username = $this->getSetting($journal->getId(), 'username');
      $password = $this->getSetting($journal->getId(), 'password');
      $webService->setAuthUsername($username);
      $webService->setAuthPassword($password);
      $response =& $webService->call($webServiceRequest);


      if ($response === false) {
        $result = array(array('plugins.importexport.common.register.error.mdsError', __('plugins.importexport.ezid.error.webserviceNoResponse')));
      } else if ($response === NULL) {
        $result = array(array('plugins.importexport.ezid.error.webserviceInvalidRequest'));
      } else {
        $status = $webService->getLastResponseStatus();
        if ($status != $expectedResponse) {
          $result = array(array('plugins.importexport.common.register.error.mdsError', "$status - ".htmlentities($response)));
        }
      }
    } else {
      return false;
    }

    if ($result === true) {
      # trim off "success: doi:"
      $trimmed_body = preg_replace('/(success: doi:)/', '', $response);
      if (strstr($trimmed_body, ' | ark:') !== FALSE) {
        list($doi, $ark) = explode(' | ark:', $trimmed_body, 2);
        $ark = 'ark:'.$ark;
      } else {
        $doi = $trimmed_body;
        $ark = '';
      }

      if (is_a($object, 'Issue')) {
        $dao =& DAORegistry::getDAO('IssueDAO');
      } elseif (is_a($object, 'Article')) {
        $dao =& DAORegistry::getDAO('ArticleDAO');
      }
      $dao->changePubId($object->getId(), 'doi', $doi);
      // Update the stored pub id if the change is not just text case
      if (strtoupper($object->getStoredPubId('doi')) !== $doi) {
        $object->setStoredPubId('doi', $doi);
      }
      // Mark the object as registered.
      $this->markRegistered($request, $object, $shoulder);
    }

    return $result;
  }

  //
  // Private helper methods
  //

  /**
   * Encode DOI according to ANSI/NISO Z39.84-2005, Appendix E.
   * @param $pubId string
   * @return string
   */
  function _doiMetadataEscape($anvl_string) {
    $search = array ("%", ":", "\r", "\n");
    $replace = array ("%25", "%3A", "%0D", "%0A");
    $anvl_string = str_replace($search, $replace, $anvl_string);
    return $anvl_string;
  }

  /**
   * @see DOIExportPlugin::processMarkRegistered()
   */
  function processMarkRegistered(&$request, $exportType, &$objects, &$journal) {
    $this->import('classes.EzidExportDom');
    $dom = new EzidExportDom($request, $this, $journal, $this->getCache());
    foreach($objects as $object) {
      if (is_a($object, 'Issue')) {
        $articlesByIssue =& $dom->retrieveArticlesByIssue($object);
        foreach ($articlesByIssue as $article) {
          if ($article->getPubId('doi')) {
            $this->markRegistered($request, $article);
          }
        }
      } else {
        if ($object->getPubId('doi')) {
          $this->markRegistered($request, $object);
        }
      }
    }
  }

  /**
   * Assert that we don't have a "testMode"
   * @param $request Request
   * @return boolean
   */
  function isTestMode(&$request) {
    return false;
  }

  /**
   * For now, the crontab is disabled
   * TODO: add scheduledTasks.xml, remove this method override
   * @see AcronPlugin::parseCronTab()
   */
  function callbackParseCronTab($hookName, $args) {
    return false;
  }

  /**
   * Display a list of issues for export.
   * N.b.: this method should probably be removed when https://github.com/pkp/pkp-lib/issues/808 is resolved (OJS 2.4.8),
   *  assuming CrossRefExportPlugin::displayIssueList() continues to accomodate the workflow.
   * @param $templateMgr TemplateManager
   * @param $journal Journal
   */
  function displayIssueList(&$templateMgr, &$journal) {
    $this->setBreadcrumbs(array(), true);
    // Retrieve all published issues.
    AppLocale::requireComponents(array(LOCALE_COMPONENT_OJS_EDITOR));
    $issueDao =& DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
    $this->registerDaoHook('IssueDAO');
    $issueIterator =& $issueDao->getPublishedIssues($journal->getId(), Handler::getRangeInfo('issues'));
    // Filter only issues that contain an article that have a DOI assigned.
    $publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
    $issues = array();
    $numArticles = array();
    while ($issue =& $issueIterator->next()) {
      $issueArticles =& $publishedArticleDao->getPublishedArticles($issue->getId());
      $issueArticlesNo = 0;
      $allArticlesRegistered[$issue->getId()] = true;
      foreach ($issueArticles as $issueArticle) {
        $articleRegistered = $issueArticle->getData($this->getPluginId().'::registeredDoi');
        if (!in_array($issue, $issues)) $issues[] = $issue;
        $issueArticlesNo++;
        if ($allArticlesRegistered[$issue->getId()] && !isset($articleRegistered)) {
          $allArticlesRegistered[$issue->getId()] = false;
        }
        // Is the issue itself registered?
        if (!$issue->getData($this->getPluginId().'::registeredDoi')) {
          $allArticlesRegistered[$issue->getId()] = false;
        }
      }
      $numArticles[$issue->getId()] = $issueArticlesNo;
    }
    // Instantiate issue iterator.
    import('lib.pkp.classes.core.ArrayItemIterator');
    $rangeInfo = Handler::getRangeInfo('articles');
    $iterator = new ArrayItemIterator($issues, $rangeInfo->getPage(), $rangeInfo->getCount());
    // Prepare and display the issue template.
    $templateMgr->assign_by_ref('issues', $iterator);
    $templateMgr->assign('numArticles', $numArticles);
    $templateMgr->assign('allArticlesRegistered', $allArticlesRegistered);
    $templateMgr->display($this->getTemplatePath() . 'issues.tpl');
  }

  /**
   * The selected issue can be exported if it contains an article that has a DOI,
   * and the articles containing a DOI also have a date published.
   * The selected article can be exported if it has a DOI and a date published.
   * @param $foundObject Issue|PublishedArticle
   * @param $errors array
   * @return array|boolean
  */
  function canBeExported($foundObject, &$errors) {
    $journal =& Request::getJournal();
    $optionalDoi = (boolean) $this->getSetting($journal->getId(), 'shoulder');
    if (is_a($foundObject, 'Issue') || is_a($foundObject, 'PublishedArticle')) {
      return !is_null($foundObject->getPubId('doi')) || $optionalDoi;
    }
    return false;
  }

  /**
   * @copydoc DOIExportPlugin::displayArticleList
   * @todo Remove this method when https://github.com/pkp/ojs/pull/647 is resolved
   */
  function displayArticleList(&$templateMgr, &$journal) {
    $templateMgr->assign('depositStatusSettingName', $this->getDepositStatusSettingName());
    $templateMgr->assign('depositStatusUrlSettingName', $this->getDepositStatusUrlSettingName());
    $this->setBreadcrumbs(array(), true);

    // Retrieve all published articles.
    $this->registerDaoHook('PublishedArticleDAO');
    $allArticles = $this->getAllPublishedArticles($journal);

    // Filter only articles that have a DOI assigned.
    $articles = array();
    foreach($allArticles as $article) {
      $errors = array();
      if ($this->canBeExported($article, $errors)) {
        $articles[] = $article;
      }
      unset($article);
    }
    unset($allArticles);

    // Paginate articles.
    $totalArticles = count($articles);
    $rangeInfo = Handler::getRangeInfo('articles');
    if ($rangeInfo->isValid()) {
      $articles = array_slice($articles, $rangeInfo->getCount() * ($rangeInfo->getPage()-1), $rangeInfo->getCount());
    }

    // Retrieve article data.
    $articleData = array();
    foreach($articles as $article) {
      $preparedArticle = $this->_prepareArticleData($article, $journal);
      // We should always get a prepared article as we've already
      // filtered non-published articles above.
      assert(is_array($preparedArticle));
      $articleData[] = $preparedArticle;
      unset($article, $preparedArticle);
    }
    unset($articles);

    // Instantiate article iterator.
    import('lib.pkp.classes.core.VirtualArrayIterator');
    $iterator = new VirtualArrayIterator($articleData, $totalArticles, $rangeInfo->getPage(), $rangeInfo->getCount());

    // Prepare and display the article template.
    $templateMgr->assign_by_ref('articles', $iterator);
    $templateMgr->display($this->getTemplatePath() . 'articles.tpl');
  }
}

?>
