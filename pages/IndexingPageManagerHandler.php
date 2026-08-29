<?php
/**
 * @file pages/IndexingPageManagerHandler.php
 *
 * Indexing Page Manager — public-facing page handler.
 *
 * Mounted in place of /about/<slug> (default /about/databases) by the
 * LoadHandler hook in IndexingPageManagerPlugin. Resolves sections + indexes,
 * applies visibility rules, embeds Schema.org JSON-LD when enabled, and
 * dispatches to one of the two grid templates (logos / named).
 */

namespace APP\plugins\generic\indexingPageManager\pages;

use APP\handler\Handler;
use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerSchemaOrg;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerSmartyHelper;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextRequiredPolicy;

class IndexingPageManagerHandler extends Handler
{
    /** @var IndexingPageManagerPlugin */
    public $plugin;

    public function __construct(IndexingPageManagerPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    /**
     * @copydoc PKPHandler::authorize()
     *
     * IMPORTANT: this handler must extend APP\handler\Handler (not
     * PKP\controllers\page\PageHandler). PageHandler::authorize() adds a
     * PKPSiteAccessPolicy that unconditionally denies any request from a
     * logged-out user, regardless of the op — that policy is meant for
     * internal backend components (tasks/css), not public-facing pages.
     * Inheriting it here would silently redirect every anonymous visitor
     * to the login page instead of showing the public showcase, which is
     * the opposite of what this page is for. We only require that a
     * context (journal) is present, matching AboutContextHandler's own
     * authorize() pattern.
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Kept as a safety net: loadHandler() below always rewrites the request
     * op to the real `index` method before dispatch, but __call guards
     * against any op that somehow reaches the handler unrewritten.
     */
    public function __call($op, $arguments)
    {
        return call_user_func_array([$this, 'index'], $arguments);
    }

    public function index($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            $request->getDispatcher()->handle404();
            return;
        }

        // Prefer the plugin instance handed in via the constructor (the
        // documented OJS 3.4+/3.5 LoadHandler pattern); fall back to a
        // registry lookup only if the handler was somehow constructed
        // without one.
        /** @var IndexingPageManagerPlugin $plugin */
        $plugin = $this->plugin ?: PluginRegistry::getPlugin('generic', 'indexingpagemanagerplugin');
        if (!$plugin) {
            $request->getDispatcher()->handle404();
            return;
        }

        $contextId = (int) $context->getId();

        if (!$plugin->getEnabled($contextId)) {
            $request->getDispatcher()->handle404();
            return;
        }

        // Resolve template + column count.
        $template = IndexingPageManagerPlugin::normalizeTemplate($plugin->getSetting($contextId, 'displayTemplate'));
        $flags    = IndexingPageManagerPlugin::templateFlags($template);
        $columns = (int) ($plugin->getSetting($contextId, 'displayColumns') ?: 4);
        if (!in_array($columns, IndexingPageManagerPlugin::COLUMN_OPTIONS, true)) {
            $columns = 4;
        }

        $enableSchema = $plugin->getSetting($contextId, 'enableSchemaOrg');
        $enableSchema = ($enableSchema === null) ? true : (bool) $enableSchema;

        $pageTitle = $this->_localizedSetting($plugin, $contextId, 'pageTitle')
            ?: __('plugins.generic.indexingPageManager.frontend.defaultTitle');
        $introText = $this->_localizedSetting($plugin, $contextId, 'introText');

        // Build payload — only active sections that contain active indexes.
        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');

        $sections = $sectionDao->getByJournalId($contextId, true);

        $blocks = [];
        foreach ($sections as $section) {
            $indexes = $indexDao->getBySectionId($section->getId(), true, $contextId);
            if (empty($indexes)) continue;
            $blocks[] = ['section' => $section, 'indexes' => $indexes];
        }

        // Pre-render Schema.org JSON-LD.
        $schemaJson = '';
        if ($enableSchema) {
            $schemaJson = IndexingPageManagerSchemaOrg::renderForBlocks($blocks, $request, $contextId, $pageTitle);
        }

        $tm = TemplateManager::getManager($request);

        // Smarty helpers (registered idempotently via the display hook; call
        // here too in case the hook was missed).
        IndexingPageManagerSmartyHelper::register($tm, $plugin);

        // Stylesheets — append ?v=<plugin version> so an upgrade invalidates
        // the visitor's browser cache without a hard refresh.
        $base = $request->getBaseUrl() . '/' . $plugin->getPluginPath();
        $versionTag = $this->_assetVersionTag($plugin);
        $tm->addStyleSheet(
            'ipmFrontendBase',
            $base . '/styles/compiled/frontend-base.css' . $versionTag,
            ['contexts' => 'frontend']
        );
        $tm->addStyleSheet(
            'ipmFrontendGrid',
            $base . '/styles/compiled/frontend-grid.css' . $versionTag,
            ['contexts' => 'frontend', 'priority' => STYLE_SEQUENCE_LATE]
        );

        $pluginRes = $plugin->getTemplateResource('');

        $tm->assign([
            'ipmBlocks'         => $blocks,
            'ipmPageTitle'      => $pageTitle,
            'ipmIntroText'      => $introText,
            'ipmContextId'      => $contextId,
            'ipmTemplate'       => $template,
            'ipmShowName'       => $flags['name'],
            'ipmShowDesc'       => $flags['desc'],
            'ipmColumns'        => $columns,
            'ipmEnableSchemaOrg'=> $enableSchema,
            'ipmSchemaJsonLd'   => $schemaJson,
            'ipmLayoutPath'     => $plugin->getTemplateResource('frontend/_layout.tpl'),
            'ipmTplPrefix'      => $pluginRes,
        ]);

        $resource = $plugin->getTemplateResource('frontend/template-grid.tpl');
        $tm->display($resource);
    }

    private function _localizedSetting($plugin, $contextId, $name)
    {
        $values = $plugin->getSetting($contextId, $name);
        if (!is_array($values)) {
            return $values ?: null;
        }
        $locale = Locale::getLocale();
        if (isset($values[$locale]) && $values[$locale] !== '') return $values[$locale];
        $primary = Locale::getPrimaryLocale();
        if (isset($values[$primary]) && $values[$primary] !== '') return $values[$primary];
        foreach ($values as $v) if ($v !== '') return $v;
        return null;
    }

    /**
     * "?v=0.1.0"-style cache-busting tag derived from the plugin's current
     * version. Falls back to an empty string if the version can't be read.
     */
    private function _assetVersionTag($plugin)
    {
        try {
            // Plugin::getCurrentVersion() resolves the plugin's own version
            // record (passing args to VersionDAO::getCurrentVersion without
            // $isPlugin=true would silently return the OJS app version).
            $version = $plugin->getCurrentVersion();
            if ($version) {
                return '?v=' . rawurlencode((string) $version->getVersionString());
            }
            $xmlPath = $plugin->getPluginPath() . '/version.xml';
            if (is_file($xmlPath)) {
                $xml = @simplexml_load_string((string) file_get_contents($xmlPath));
                if ($xml && isset($xml->release)) {
                    return '?v=' . rawurlencode((string) $xml->release);
                }
            }
        } catch (\Throwable $e) {
            // ignore — fall through to untagged URLs
        }
        return '';
    }
}
