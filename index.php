<?php

/**
 * @defgroup plugins_generic_indexingPageManager Indexing Page Manager
 */

/**
 * @file index.php
 *
 * Distributed under the GNU GPL v2. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_indexingPageManager
 * @brief Wrapper for the Indexing Page Manager plugin. OJS's PluginRegistry
 *        loads each generic plugin by including its index.php and using the
 *        returned object — so this thin wrapper is required for the plugin
 *        to appear in the plugin manager list. Namespaced plugin classes are
 *        autoloaded, so index.php only needs to return a new instance.
 */

return new \APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin();
