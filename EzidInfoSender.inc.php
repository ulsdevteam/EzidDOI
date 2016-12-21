<?php

/**
 * @file plugins/importexport/ezid/EzidInfoSender.php
 *
 * Copyright (c) 2013-2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class EzidInfoSender
 * @ingroup plugins_importexport_ezid
 *
 * @brief Scheduled task to register article with EZID.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.core.JSONManager');


class EzidInfoSender extends ScheduledTask {
  /** @var $_plugin EzidRegisterPlugin */
  var $_plugin;

  /**
   * Constructor.
   * @param $argv array task arguments
   */
  function EzidInfoSender($args) {
    PluginRegistry::loadCategory('importexport');
    $plugin =& PluginRegistry::getPlugin('importexport', 'EzidRegisterPlugin'); /* @var $plugin EzidRegisterPlugin */
    $this->_plugin =& $plugin;

    if (is_a($plugin, 'EzidRegisterPlugin')) {
      $plugin->addLocaleData();
    }

    parent::ScheduledTask($args);
  }

  /**
   * @see ScheduledTask::getName()
   */
  function getName() {
    return __('plugins.importexport.ezid.senderTask.name');
  }

  /**
   * @see ScheduledTask::executeActions()
   */
  function executeActions() {
    if (!$this->_plugin) return false;

    $plugin = $this->_plugin;

    $journals = $this->_getJournals();
    $request =& Application::getRequest();

    $errorsOccurred = false;
    foreach ($journals as $journal) {
      $unregisteredArticles = $plugin->_getUnregisteredArticles($journal);

      $unregisteredArticlesIds = array();
      foreach ($unregisteredArticles as $articleData) {
        $article = $articleData['article'];
        $errors = array();
        if (is_a($article, 'PublishedArticle') && $plugin->canBeExported($article, $errors)) {
          $unregisteredArticlesIds[$article->getId()] = $article;
        }
      }

      $toBeRegisteredIds = array();
      foreach ($unregisteredArticlesIds as $id => $article) {
        if (!$article->getData('ezid::registeredDoi')) {
          array_push($toBeRegisteredIds, $id);
        }
      }

      // If there are unregistered things and we want automatic deposits
      if (count($toBeRegisteredIds) && $plugin->getSetting($journal->getId(), 'automaticRegistration')) {
        foreach ($toBeRegisteredIds as $articleId) {
          $exportSpec = array(DOI_EXPORT_ARTICLES => array($articleId));
          $result = $plugin->registerObjects($request, $exportSpec, $journal);
          if ($result !== true) {
            if (is_array($result)) {
              foreach($result as $error) {
                assert(is_array($error) && count($error) >= 1);
                $this->addExecutionLogEntry(
                  __($error[0], array('param' => (isset($error[1]) ? $error[1] : null))),
                  SCHEDULED_TASK_MESSAGE_TYPE_WARNING
                );
              }
            }
            $errorsOccurred = true;
          }
        }
      }
    }
    return !$errorsOccurred;
  }

  /**
   * Get all journals that meet the requirements to have
   * their articles DOIs sent to Crossref .
   * @return array
   */
  function _getJournals() {
    $plugin =& $this->_plugin;
    $journalDao =& DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
    $journalFactory =& $journalDao->getJournals(true);

    $journals = array();
    while($journal =& $journalFactory->next()) {
      $journalId = $journal->getId();
      if (!$plugin->getSetting($journalId, 'enabled') && !$plugin->getSetting($journalId, 'automaticRegistration')) continue;

      $doiPrefix = null;
      $pubIdPlugins =& PluginRegistry::loadCategory('pubIds', true, $journalId);
      if (isset($pubIdPlugins['DOIPubIdPlugin'])) {
        $doiPubIdPlugin =& $pubIdPlugins['DOIPubIdPlugin'];
        $doiPrefix = $doiPubIdPlugin->getSetting($journalId, 'doiPrefix');
      }

      if ($doiPrefix) {
        $journals[] =& $journal;
      } else {
        $this->notify(SCHEDULED_TASK_MESSAGE_TYPE_WARNING,
          __('plugins.importexport.crossref.senderTask.warning.noDOIprefix', array('path' => $journal->getPath())));
      }
      unset($journal);
    }

    return $journals;
  }
}
?>
