<?php
/**
 * @file classes/IndexingPageManagerGatewayPlugin.php
 *
 * Indexing Page Manager — gateway sub-plugin.
 *
 * Serves the exact same public showcase as the /about/<slug> route
 * (IndexingPageManagerHandler), but reached via OJS' core Gateway routing
 * (/gateway/plugin/IndexingPageManagerGatewayPlugin) instead. That route is
 * handled entirely by core (GatewayHandler resolving a plugin registered
 * under the "gateways" category) and never touches this plugin's own
 * LoadHandler hook — unlike /about/<slug>, which on some installs' custom
 * page routing doesn't reach our handler reliably (mirrors the reasoning
 * behind IndexingPageManagerPlugin::getActions() using an AjaxModal instead
 * of a custom-page link for the admin side).
 *
 * Registered via the confirmed-current PKP pattern (see e.g. pkp/pln's
 * PLNGatewayPlugin): a `PluginRegistry::loadCategory` hook callback on the
 * parent GenericPlugin instantiates this class and adds it to the
 * "gateways" category array.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use APP\plugins\generic\indexingPageManager\pages\IndexingPageManagerHandler;
use PKP\plugins\GatewayPlugin;
use PKP\plugins\PluginRegistry;

class IndexingPageManagerGatewayPlugin extends GatewayPlugin
{
    /** URL path segment identifying this gateway plugin (/gateway/plugin/<this>). */
    public const PLUGIN_PATH = 'ipmShowcase';

    /** @var string Name of the parent generic plugin, for PluginRegistry lookups. */
    private $parentPluginName;

    public function __construct($parentPluginName)
    {
        parent::__construct();
        $this->parentPluginName = $parentPluginName;
    }

    public function getName()
    {
        return self::PLUGIN_PATH;
    }

    /** Explicit override — this instance is never registered via the normal
     *  directory-scanning register() call, so the base class's own path
     *  resolution logic doesn't apply; the URL segment must be predictable. */
    public function getPluginPath()
    {
        return self::PLUGIN_PATH;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.indexingPageManager.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.indexingPageManager.description');
    }

    public function getSeq()
    {
        return 0;
    }

    /** @return \APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin|null */
    private function _getParentPlugin()
    {
        return PluginRegistry::getPlugin('generic', $this->parentPluginName);
    }

    /**
     * @copydoc GatewayPlugin::getEnabled()
     *
     * Always true: this subsidiary is only ever registered by the parent
     * plugin's own register() when the parent itself is already enabled
     * (see IndexingPageManagerPlugin::registerGatewayPlugin()), matching
     * the documented convention for gateway sub-plugins (see e.g.
     * WebFeedGatewayPlugin). fetch() below re-checks the parent regardless,
     * as defence-in-depth.
     */
    public function getEnabled($contextId = null)
    {
        return true;
    }

    /**
     * @copydoc GatewayPlugin::fetch()
     */
    public function fetch($args, $request)
    {
        $parent = $this->_getParentPlugin();
        if (!$parent || !$parent->getEnabled()) {
            $request->getDispatcher()->handle404();
            return true;
        }

        // Calling the frontend handler's index() directly (rather than via
        // the page router) skips authorize()/initialize() — harmless here,
        // since index() already redundantly checks for a missing context
        // itself and does nothing else that depends on prior router state.
        $handler = new IndexingPageManagerHandler($parent);
        $handler->index($args, $request);
        return true;
    }
}
