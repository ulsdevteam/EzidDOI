<?php

/**
 * @file plugins/importexport/.../classes/PubObjectCache.inc.php
 *
 * Copyright (c) 2013-2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PubObjectCache
 * @ingroup plugins_importexport_..._classes
 *
 * @brief A cache for publication objects required during export.  This is a stub to call the source from the crossref plugin.
 */


if (!class_exists('PubObjectCache')) {
  import('plugins.importexport.crossref.classes.PubObjectCache');
}