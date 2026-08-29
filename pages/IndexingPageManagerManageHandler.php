<?php
/**
 * @file pages/IndexingPageManagerManageHandler.php
 *
 * Indexing Page Manager — backend admin handler (URL-based).
 *
 * Mounted by the plugin's loadHandler hook on the page slug
 * `indexingPageManager`. Provides full URL-addressable admin pages that live
 * inside the OJS backend chrome (sidebar + top user menu).
 *
 * URL layout:
 *   /index.php/<journal>/indexingPageManager            → index (→ indexes)
 *   /index.php/<journal>/indexingPageManager/indexes
 *   /index.php/<journal>/indexingPageManager/indexForm[?indexId=N]
 *   /index.php/<journal>/indexingPageManager/indexSave        (POST, multipart)
 *   /index.php/<journal>/indexingPageManager/indexDelete      (POST)
 *   /index.php/<journal>/indexingPageManager/indexToggle      (POST)
 *   /index.php/<journal>/indexingPageManager/indexReorder     (POST)
 *   /index.php/<journal>/indexingPageManager/sections
 *   /index.php/<journal>/indexingPageManager/sectionForm[?sectionId=N]
 *   /index.php/<journal>/indexingPageManager/sectionSave      (POST)
 *   /index.php/<journal>/indexingPageManager/sectionDelete    (POST)
 *   /index.php/<journal>/indexingPageManager/sectionToggle    (POST)
 *   /index.php/<journal>/indexingPageManager/sectionReorder   (POST)
 *   /index.php/<journal>/indexingPageManager/templates
 *   /index.php/<journal>/indexingPageManager/templateSave     (POST)
 *   /index.php/<journal>/indexingPageManager/settings
 *   /index.php/<journal>/indexingPageManager/settingsSave     (POST)
 */

namespace APP\plugins\generic\indexingPageManager\pages;

use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerAdminController;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerSmartyHelper;
use APP\plugins\generic\indexingPageManager\classes\form\IpmIndexForm;
use APP\plugins\generic\indexingPageManager\classes\form\IpmSectionForm;
use APP\plugins\generic\indexingPageManager\classes\form\IpmSettingsForm;
use APP\plugins\generic\indexingPageManager\classes\form\IpmTemplateForm;
use APP\template\TemplateManager;
use PKP\controllers\page\PageHandler;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\authorization\PolicySet;

class IndexingPageManagerManageHandler extends PageHandler
{
    /** @var IndexingPageManagerPlugin */
    public $plugin;

    public function __construct(IndexingPageManagerPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        // Mark this as a backend page so PKPHandler::setupTemplate() calls
        // setupBackendPage(), which populates the Vue state (menu, etc.) that
        // layouts/backend.tpl references. Without this the page renders raw
        // mustaches and loses the sidebar.
        $this->_isBackendPage = true;
        $this->addRoleAssignment(
            [ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER],
            [
                'index',
                'indexes', 'indexForm', 'indexSave', 'indexDelete',
                'indexToggle', 'indexReorder',
                'sections', 'sectionForm', 'sectionSave', 'sectionDelete',
                'sectionToggle', 'sectionReorder',
                'templates', 'templateSave',
                'settings', 'settingsSave',
            ]
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $rolePolicy = new PolicySet(COMBINING_PERMIT_OVERRIDES);
        $rolePolicy->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));
        $rolePolicy->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy($rolePolicy);
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function initialize($request, $args = null)
    {
        // Locale components are auto-loaded on demand as of OJS 3.4+; the
        // explicit AppLocale::requireComponents() call this used to need is
        // no longer necessary (and AppLocale itself no longer exists).
        return parent::initialize($request, $args);
    }

    private function _getPlugin()
    {
        return $this->plugin ?: PluginRegistry::getPlugin('generic', 'indexingpagemanagerplugin');
    }

    private function _getController()
    {
        $plugin = $this->_getPlugin();
        return new IndexingPageManagerAdminController($plugin);
    }

    /**
     * Eagerly register the plugin's Smarty helpers so fragments rendered via
     * $tm->fetch() inside the controller have access to them (the display hook
     * only fires during $tm->display(), after fetch()).
     */
    private function _ensureSmartyHelpers($request)
    {
        $plugin = $this->_getPlugin();
        $tm = TemplateManager::getManager($request);
        IndexingPageManagerSmartyHelper::register($tm, $plugin);
    }

    /**
     * Render a controller fragment as a full backend page wrapped in the
     * OJS backend chrome (sidebar, top menu).
     */
    private function _renderPage($request, $title, $jsonMessage, $extraVars = [])
    {
        $plugin = $this->_getPlugin();
        $tm = TemplateManager::getManager($request);

        $body = '';
        if (is_object($jsonMessage)) {
            if (method_exists($jsonMessage, 'getContent')) {
                $body = (string) $jsonMessage->getContent();
            } else {
                $decoded = json_decode($jsonMessage->getString(), true);
                $body = is_array($decoded) && isset($decoded['content']) ? $decoded['content'] : '';
            }
        } elseif (is_string($jsonMessage)) {
            $body = $jsonMessage;
        }

        $context = $request->getContext();
        $contextPath = $context ? $context->getPath() : null;

        $homeUrl = $request->getDispatcher()->url(
            $request, ROUTE_PAGE, $contextPath, 'indexingPageManager', 'indexes'
        );
        $csrfToken = IndexingPageManagerPlugin::sessionCsrfToken($request);

        // Build the JS bootstrap payload server-side and inject it via a JSON
        // data island — avoids the |escape:'javascript' failure mode where
        // translated strings containing quotes produce a JS SyntaxError.
        $jsBootstrap = [
            'config' => [
                'baseUrl'    => $request->getBaseUrl(),
                'pluginName' => $plugin->getName(),
                'homeUrl'    => $homeUrl,
                'csrfToken'  => $csrfToken,
            ],
            'i18n' => [
                'requestFailed'        => __('plugins.generic.indexingPageManager.admin.js.requestFailed'),
                'deleteIndexConfirm'   => __('plugins.generic.indexingPageManager.admin.js.deleteIndexConfirm'),
                'deleteSectionConfirm' => __('plugins.generic.indexingPageManager.admin.js.deleteSectionConfirm'),
                'logoUploadFailed'     => __('plugins.generic.indexingPageManager.admin.js.logoUploadFailed'),
                'saving'               => __('plugins.generic.indexingPageManager.admin.js.saving'),
                'saved'                => __('plugins.generic.indexingPageManager.admin.js.saved'),
                'saveFailed'           => __('plugins.generic.indexingPageManager.admin.js.saveFailed'),
                'networkError'         => __('plugins.generic.indexingPageManager.admin.js.networkError'),
            ],
        ];
        $jsBootstrapJson = json_encode(
            $jsBootstrap,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $tm->assign(array_merge([
            'pageTitle'            => $title,
            'indexingPageBody'     => $body,
            'indexingPageHomeUrl'  => $homeUrl,
            'csrfToken'            => $csrfToken,
            'pluginName'           => $plugin->getName(),
            'pluginVersionTag'     => $this->_assetVersionTag($plugin),
            'requestedOp'          => $request->getRouter()->getRequestedOp($request),
            'ipmJsBootstrapJson'   => $jsBootstrapJson,
        ], $extraVars));

        // setupTemplate() initialises the OJS backend Vue shell. Without this
        // the page would render with no chrome.
        $this->setupTemplate($request);

        return $tm->display($plugin->getTemplateResource('admin/_page.tpl'));
    }

    // -----------------------------------------------------------------
    // Display ops (full pages)
    // -----------------------------------------------------------------

    public function index($args, $request)
    {
        $request->redirectUrl(
            $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'indexingPageManager', 'indexes')
        );
    }

    public function indexes($args, $request)
    {
        $this->_ensureSmartyHelpers($request);
        $msg = $this->_getController()->indexList($request);
        return $this->_renderPage(
            $request,
            __('plugins.generic.indexingPageManager.action.manage'),
            $msg
        );
    }

    public function indexForm($args, $request)
    {
        $this->_ensureSmartyHelpers($request);
        $msg = $this->_getController()->indexForm($request);
        return $this->_renderPage(
            $request,
            __('plugins.generic.indexingPageManager.admin.indexForm.title'),
            $msg
        );
    }

    public function sections($args, $request)
    {
        $this->_ensureSmartyHelpers($request);
        $msg = $this->_getController()->sectionList($request);
        return $this->_renderPage(
            $request,
            __('plugins.generic.indexingPageManager.admin.sections.title'),
            $msg
        );
    }

    public function sectionForm($args, $request)
    {
        $this->_ensureSmartyHelpers($request);
        $msg = $this->_getController()->sectionForm($request);
        return $this->_renderPage(
            $request,
            __('plugins.generic.indexingPageManager.admin.sectionForm.title'),
            $msg
        );
    }

    public function templates($args, $request)
    {
        $this->_ensureSmartyHelpers($request);
        $msg = $this->_getController()->templateSelect($request);
        return $this->_renderPage(
            $request,
            __('plugins.generic.indexingPageManager.admin.templates.title'),
            $msg
        );
    }

    public function settings($args, $request)
    {
        $this->_ensureSmartyHelpers($request);
        $msg = $this->_getController()->settings($request);
        return $this->_renderPage(
            $request,
            __('plugins.generic.indexingPageManager.settings.title'),
            $msg
        );
    }

    // -----------------------------------------------------------------
    // POST endpoints (return JSON envelopes, called via fetch from page JS)
    // -----------------------------------------------------------------

    /**
     * Save an index. Returns a clean JSON envelope distinguishable from
     * validation-fail client-side:
     *   { ok:true,  redirect:"…/indexes", message:"Saved." }
     *   { ok:false, errors:{…}, formHtml:"…", message:"…" }
     */
    /**
     * Delegates to IndexingPageManagerAdminController::indexSave() (shared
     * with IndexingPageManagerPlugin::manage()'s modal-AJAX flow, so both
     * entry points always save with identical logic). That method returns a
     * plain {ok,...} array without a `redirect`, since the modal flow has no
     * URL to redirect to — this legacy URL-based handler adds one back on
     * success so the browser navigates to the index list as before.
     */
    public function indexSave($args, $request)
    {
        try {
            $this->_assertPostCsrf($request);
        } catch (\Throwable $e) {
            return $this->_emit(['ok' => false, 'message' => __('plugins.generic.indexingPageManager.admin.error.invalidRequest')]);
        }
        $result = $this->_getController()->indexSave($request);
        if (!empty($result['ok'])) {
            $result['redirect'] = $request->getDispatcher()->url(
                $request, ROUTE_PAGE,
                $request->getContext() ? $request->getContext()->getPath() : null,
                'indexingPageManager', 'indexes'
            );
        }
        return $this->_emit($result);
    }

    public function indexDelete($args, $request)
    {
        return $this->_jsonOut($this->_getController()->indexDelete($request));
    }

    public function indexToggle($args, $request)
    {
        return $this->_jsonOut($this->_getController()->indexToggle($request));
    }

    public function indexReorder($args, $request)
    {
        return $this->_jsonOut($this->_getController()->indexReorder($request));
    }

    /** @see indexSave for the response envelope + delegation rationale. */
    public function sectionSave($args, $request)
    {
        try {
            $this->_assertPostCsrf($request);
        } catch (\Throwable $e) {
            return $this->_emit(['ok' => false, 'message' => __('plugins.generic.indexingPageManager.admin.error.invalidRequest')]);
        }
        $result = $this->_getController()->sectionSave($request);
        if (!empty($result['ok'])) {
            $result['redirect'] = $request->getDispatcher()->url(
                $request, ROUTE_PAGE,
                $request->getContext() ? $request->getContext()->getPath() : null,
                'indexingPageManager', 'sections'
            );
        }
        return $this->_emit($result);
    }

    public function sectionDelete($args, $request)
    {
        return $this->_jsonOut($this->_getController()->sectionDelete($request));
    }

    public function sectionToggle($args, $request)
    {
        return $this->_jsonOut($this->_getController()->sectionToggle($request));
    }

    public function sectionReorder($args, $request)
    {
        return $this->_jsonOut($this->_getController()->sectionReorder($request));
    }

    /** @see indexSave for the response envelope + delegation rationale. */
    public function templateSave($args, $request)
    {
        try {
            $this->_assertPostCsrf($request);
        } catch (\Throwable $e) {
            return $this->_emit(['ok' => false, 'message' => __('plugins.generic.indexingPageManager.admin.error.invalidRequest')]);
        }
        return $this->_emit($this->_getController()->templateSave($request));
    }

    /** @see indexSave for the response envelope + delegation rationale. */
    public function settingsSave($args, $request)
    {
        try {
            $this->_assertPostCsrf($request);
        } catch (\Throwable $e) {
            return $this->_emit(['ok' => false, 'message' => __('plugins.generic.indexingPageManager.admin.error.invalidRequest')]);
        }
        return $this->_emit($this->_getController()->settingsSave($request));
    }

    private function _currentContextId($request)
    {
        $context = $request->getContext();
        return $context ? (int) $context->getId() : 0;
    }

    private function _assertPostCsrf($request)
    {
        if (!$request->isPost()) {
            throw new \Exception('POST required');
        }
        $expected = IndexingPageManagerPlugin::sessionCsrfToken($request);
        $expected = $expected !== '' ? $expected : null;
        $supplied = (string) $request->getUserVar('csrfToken');
        if (!$expected || !hash_equals($expected, $supplied)) {
            throw new \Exception('Invalid CSRF token');
        }
    }

    /** Emit the clean JSON envelope. */
    private function _emit(array $payload)
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    /** Controller returns JSONMessage objects; emit as plain JSON for fetch. */
    private function _jsonOut($jsonMessage)
    {
        if (is_object($jsonMessage) && method_exists($jsonMessage, 'getString')) {
            header('Content-Type: application/json');
            echo $jsonMessage->getString();
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => true]);
        exit;
    }

    /** "?v=<version>" cache-busting tag for the admin CSS/JS URLs. */
    private function _assetVersionTag($plugin)
    {
        try {
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
            // ignore
        }
        return '';
    }
}
