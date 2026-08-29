<?php
/**
 * @file IndexingPageManagerPlugin.php
 *
 * Indexing Page Manager — main plugin class (OJS 3.5 generic plugin).
 *
 * Plugin behaviour summary
 *   - Idempotently seeds 3 built-in sections per journal on enable
 *   - Registers DAOs and Smarty helpers (incl. the {ipm_blocks} theme embed)
 *   - Mounts a public showcase page at /about/<slug> (default "databases")
 *   - Registers that page as a selectable Navigation Menu destination
 *   - Hosts all admin UIs (index list, section list, index form with logo
 *     upload, settings, template selector) via URL-addressable backend pages
 */

namespace APP\plugins\generic\indexingPageManager;

use APP\core\Application;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerGatewayPlugin;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerSmartyHelper;
use APP\plugins\generic\indexingPageManager\classes\IpmIndexDAO;
use APP\plugins\generic\indexingPageManager\classes\IpmIndexSectionDAO;
use APP\plugins\generic\indexingPageManager\classes\IpmLogoStore;
use APP\plugins\generic\indexingPageManager\classes\IpmSectionDAO;
use APP\plugins\generic\indexingPageManager\pages\IndexingPageManagerHandler;
use APP\plugins\generic\indexingPageManager\pages\IndexingPageManagerManageHandler;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use APP\file\PublicFileManager;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

define('INDEXING_PAGE_MANAGER_PLUGIN_NAME', 'indexingPageManager');

// Custom Navigation Menu Item type — lets the journal manager drop the
// public databases page into any nav menu via Settings → Website →
// Navigation Menus → Add Item (reviewCertificatePro pattern).
if (!defined('NMI_TYPE_IPM_DATABASES')) {
    define('NMI_TYPE_IPM_DATABASES', 'NMI_TYPE_IPM_DATABASES');
}

class IndexingPageManagerPlugin extends GenericPlugin
{
    /**
     * Built-in section seed data. Slugs are immutable; rename only the
     * display name. Keyed by BASE language code ("en", "tr") rather than the
     * old region-qualified codes ("en_US", "tr_TR") — OJS 3.5 aligned its
     * locale list with Weblate's, which dropped the redundant region suffix
     * for locales like English and Turkish (see _localeSeedText() below for
     * how this maps onto whatever locale codes are actually installed).
     */
    private const BUILT_IN_SECTIONS = [
        ['slug' => 'indexing-and-abstracting',     'en' => 'Indexing & Abstracting',     'tr' => 'Dizinleme ve Özetleme'],
        ['slug' => 'discovery-and-search',         'en' => 'Discovery & Search',         'tr' => 'Keşif ve Arama'],
        ['slug' => 'identifiers-and-registration', 'en' => 'Identifiers & Registration', 'tr' => 'Tanımlayıcılar ve Kayıt'],
        ['slug' => 'archiving-and-preservation',   'en' => 'Archiving & Preservation',   'tr' => 'Arşivleme ve Koruma'],
    ];

    /**
     * Allowed values for the displayTemplate setting (4 layouts):
     *   logos          → logo only
     *   logo-name      → logo + name
     *   logo-name-desc → logo + name + description
     *   logo-desc      → logo + description
     */
    public const TEMPLATES = ['logos', 'logo-name', 'logo-name-desc', 'logo-desc'];

    /** Default template. */
    public const DEFAULT_TEMPLATE = 'logo-name-desc';

    /**
     * Resolve which caption parts a template shows.
     * @return array{name:bool,desc:bool}
     */
    public static function templateFlags($template)
    {
        switch ($template) {
            case 'logos':     return ['name' => false, 'desc' => false];
            case 'logo-name': return ['name' => true,  'desc' => false];
            case 'logo-desc': return ['name' => false, 'desc' => true];
            case 'logo-name-desc':
            default:          return ['name' => true,  'desc' => true];
        }
    }

    /**
     * Normalise a stored/posted template value: map the legacy "named" key to
     * "logo-name-desc" and fall back to the default for anything unknown.
     */
    public static function normalizeTemplate($template)
    {
        if ($template === 'named') return 'logo-name-desc'; // legacy (≤0.1.4)
        return in_array($template, self::TEMPLATES, true) ? $template : self::DEFAULT_TEMPLATE;
    }

    /** Allowed values for the displayColumns setting. */
    public const COLUMN_OPTIONS = [3, 4, 5];

    /** Default URL slug under /about/. */
    public const DEFAULT_SLUG = 'databases';

    /**
     * Top-level page slug for the public showcase, giving the URL
     * /index.php/<journal>/indexes-and-databases (served by
     * IndexingPageManagerHandler via the LoadHandler hook). This is the
     * primary, advertised public URL (see getFrontendUrl()); the
     * /gateway/plugin/ipmShowcase route and the /about/<slug> route both
     * remain as fallbacks. Hyphens are fine here — core cleanFileVar()
     * allows [\w-].
     */
    public const SHOWCASE_PAGE = 'indexes-and-databases';

    /**
     * Legacy top-level page slug (0.4.3–0.4.6). Still accepted by
     * loadHandler() so links/bookmarks made against the old URL keep
     * working; not advertised any more.
     */
    public const SHOWCASE_PAGE_LEGACY = 'ipmShowcase';

    /**
     * Ops already handled by PKP's own AboutContextHandler
     * (lib/pkp/pages/about/AboutContextHandler.php). The public showcase
     * page is mounted by matching this op against /about/<slug>; if an
     * admin were allowed to set pageSlug to one of these, our loadHandler()
     * would silently replace the built-in About/Contact/Submissions pages
     * instead of the databases page appearing there. Never allow the slug
     * to collide with these.
     *
     * @return string[]
     */
    public static function getReservedSlugs()
    {
        return ['index', 'contact', 'submissions', 'editorialMasthead', 'editorialHistory'];
    }

    public function getDisplayName()
    {
        return __('plugins.generic.indexingPageManager.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.indexingPageManager.description');
    }

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        $this->_registerDAOs();

        // ---------------------------------------------------------------
        // ALL hooks are registered UNCONDITIONALLY here — never behind a
        // register()-time getEnabled() check.
        //
        // Why: core loads the "generic" plugin category once per request in
        // Dispatcher::dispatch() (PluginRegistry::loadCategory('generic',
        // true)) with NO context id and BEFORE the journal context is
        // resolved (it force-reloads the context on the very next line).
        // With <lazy-load>0</lazy-load> this plugin loads on that call, so
        // register() runs with $mainContextId = null and no resolvable
        // context — getEnabled() there reads the SITE-level flag (unset for
        // a per-journal plugin) and returns false. register() is also only
        // ever invoked ONCE per request for our name (PluginRegistry skips
        // re-registration), so a later context-aware loadCategory() call
        // does NOT re-run this method. Gating hook registration on that
        // first, context-less getEnabled() therefore dropped the sidebar
        // link, nav-menu type, Smarty helpers and public routes for the
        // whole request — the 404-with-no-log symptom.
        //
        // Every callback below re-checks $this->getEnabled() at call time,
        // when it fires during/after routing and the context IS resolved.
        // That is the authoritative per-journal enabled check.
        // ---------------------------------------------------------------
        Hook::add('LoadHandler', [$this, 'loadHandler']);
        Hook::add('PluginRegistry::loadCategory', [$this, 'registerGatewayPlugin']);
        Hook::add('TemplateManager::display', [$this, 'registerSmartyHelpers']);
        Hook::add('TemplateManager::display', [$this, 'addSidebarLink']);
        Hook::add('NavigationMenus::itemTypes', [$this, 'addNavigationMenuItemTypes']);
        Hook::add('NavigationMenus::displaySettings', [$this, 'setNavigationMenuItemDisplaySettings']);
        Hook::add('Context::delete', [$this, 'cleanupOnJournalDelete']);

        // NOTE: DB setup (migration + seeding) is deliberately NOT run from
        // here. register() executes on *every* request now that the plugin
        // is non-lazy, including component/AJAX calls (the plugins grid's
        // own fetch, etc.) — doing DB writes on that hot path was fragile.
        // _maybeRunSetup() is instead called from the admin entry points
        // (manage(), addSidebarLink()) where a journal context is present
        // and DB work is expected. It is version-stamped, so it is a single
        // cheap getSetting() after the first successful pass.

        return true;
    }

    /** Bump when the seed/repair logic or default settings change. */
    private const SETUP_STAMP = '0.4.5';

    /**
     * Run migration + idempotent seeding at most once per (journal, stamp).
     * Safe to call on every request: the stamp check short-circuits after
     * the first successful pass, and the whole body is defensive so a
     * failure here can never affect the hooks registered above.
     */
    private function _maybeRunSetup($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) {
            return; // context not resolvable yet — try again next request
        }
        if (!$this->getEnabled($journalId)) {
            return; // not enabled for this journal
        }
        if ((string) $this->getSetting($journalId, 'ipmSetupStamp') === self::SETUP_STAMP) {
            return; // already set up at this version
        }

        try {
            if (!$this->_tablesExist()) {
                $this->_runMigrationDirect();
            }
            if ($this->_tablesExist()) {
                $this->_maybeSeedBuiltIns($journalId);
                $this->_maybeRepairBuiltInLabels($journalId);
                $this->_maybeSeedDefaultSettings($journalId);
                // Demo build only (no-op unless a demo-data/ payload ships).
                $this->_maybeSeedDemoIndexes($journalId);
                $this->_maybeUpgradeDemoIndexes($journalId);
                $this->updateSetting($journalId, 'ipmSetupStamp', self::SETUP_STAMP, 'string');
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[indexingPageManager] setup step failed (routes/hooks unaffected): %s: %s in %s:%d',
                get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
            ));
        }
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        return new IndexingPageManagerSchemaMigration();
    }

    /**
     * Cheap, driver-agnostic existence check used to keep register() safe
     * when tables haven't been migrated yet.
     *
     * Uses the `Illuminate\Support\Facades\Schema` facade (bound to PKP's
     * own Laravel container as of 3.5) rather than reaching directly for
     * `Illuminate\Database\Capsule\Manager`: the raw Capsule static facade
     * requires `Capsule::setAsGlobal()` to have run, which isn't guaranteed
     * at every point in the request lifecycle (e.g. AJAX/component routes)
     * and can result in `Call to a member function connection() on null`.
     */
    private function _tablesExist()
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('ipm_sections');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Run the schema migration directly. Used as a self-healing fallback
     * when the plugin is marked enabled but tables don't exist. Idempotent.
     */
    private function _runMigrationDirect()
    {
        try {
            (new IndexingPageManagerSchemaMigration())->up();
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[indexingPageManager] migration failed: %s: %s in %s:%d',
                get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
            ));
        }
    }

    /**
     * LoadHandler dispatcher.
     *
     *   1. Backend admin pages  (/indexingPageManager/...)
     *      → IndexingPageManagerManageHandler
     *   2. Frontend public page (/about/<slug>, default /about/databases)
     *      → IndexingPageManagerHandler
     *
     * As of OJS 3.4+ the HANDLER_CLASS constant is no longer used to wire up
     * a page handler; instead the hook receives the handler instance itself
     * by reference as the 4th argument, which this method assigns directly.
     * Namespaced handler classes are autoloaded, so no require_once() is
     * needed.
     */
    public function loadHandler($hookName, $args)
    {
        $page = $args[0];
        $handler =& $args[3];

        $isShowcase = ($page === self::SHOWCASE_PAGE || $page === self::SHOWCASE_PAGE_LEGACY);

        // Only our page families are candidates.
        if ($page !== 'indexingPageManager' && $page !== 'about' && !$isShowcase) {
            return false;
        }

        // This hook is registered unconditionally (see register()), so the
        // per-journal enabled check happens here — by now the request is
        // being routed and $this->getEnabled() resolves against the real
        // journal context. When disabled we must NOT claim /about/<slug>
        // (that would shadow a real About page a journal might have there).
        if (!$this->getEnabled()) {
            return false;
        }

        // (1) Backend admin route family.
        if ($page === 'indexingPageManager') {
            $handler = new IndexingPageManagerManageHandler($this);
            return true;
        }

        // (2) Primary public route — the top-level page
        //     /index.php/<journal>/indexes-and-databases
        //     (plus the legacy /ipmShowcase alias).
        if ($isShowcase) {
            // PKPPageRouter requires $op to be a real method name on the
            // handler; force it to `index` so any trailing path segment
            // still renders the showcase rather than 404ing at the router.
            $args[1] = 'index';
            $handler = new IndexingPageManagerHandler($this);
            return true;
        }

        // (3) Secondary public route — /about/<configured-slug>.
        $slug = $this->_resolvePageSlug();
        if ($args[1] !== $slug) {
            return false;
        }

        // PKPPageRouter requires $op to be a real method name on the
        // handler, so rewrite the op (the configured slug) to `index`.
        $args[1] = 'index';
        $handler = new IndexingPageManagerHandler($this);
        return true;
    }

    /**
     * Hook: PluginRegistry::loadCategory — registers
     * IndexingPageManagerGatewayPlugin under the "gateways" category, giving
     * the public showcase a route (/gateway/plugin/ipmShowcase, per
     * IndexingPageManagerGatewayPlugin::PLUGIN_PATH) resolved entirely by
     * core Gateway routing. Mirrors the confirmed-current pattern used by
     * pkp/pln's PLNGatewayPlugin registration.
     */
    public function registerGatewayPlugin($hookName, $args)
    {
        $category = $args[0];
        $plugins  =& $args[1];
        if ($category === 'gateways') {
            // Registered unconditionally (see register()); gate the actual
            // registration on the per-journal enabled flag here, where the
            // context is resolved. core's GatewayHandler::__construct()
            // triggers this during routing (after
            // Router::getContext($request, true)), so $this->getEnabled() is
            // reliable at this point. IndexingPageManagerGatewayPlugin::fetch()
            // re-checks the parent as defence-in-depth regardless.
            if ($this->getEnabled()) {
                $gatewayPlugin = new IndexingPageManagerGatewayPlugin($this->getName());
                $plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] = $gatewayPlugin;
            }
        }
        return false;
    }

    /**
     * The public showcase's canonical URL — the top-level page route
     * /index.php/<journal>/indexes-and-databases (see SHOWCASE_PAGE /
     * loadHandler()). Single source of truth: the admin "Preview" button,
     * the Settings page's public-URL display, and the Navigation Menu item
     * all resolve through this one method. The legacy /ipmShowcase page,
     * the /gateway/plugin/ipmShowcase route and the /about/<slug> route all
     * still work as fallbacks but are no longer what the plugin advertises.
     */
    public function getFrontendUrl($request, $context = null)
    {
        $context = $context ?: $request->getContext();
        $dispatcher = $request->getDispatcher();
        return $dispatcher->url(
            $request, ROUTE_PAGE,
            $context ? $context->getPath() : null,
            self::SHOWCASE_PAGE
        );
    }

    /**
     * The legacy Gateway route (/gateway/plugin/ipmShowcase). Kept for
     * bookmarks / nav-menu items created before 0.4.3; getFrontendUrl() now
     * returns the shorter page route instead.
     */
    public function getGatewayFrontendUrl($request, $context = null)
    {
        $context = $context ?: $request->getContext();
        $dispatcher = $request->getDispatcher();
        return $dispatcher->url(
            $request, ROUTE_PAGE,
            $context ? $context->getPath() : null,
            'gateway', 'plugin',
            [IndexingPageManagerGatewayPlugin::PLUGIN_PATH]
        );
    }

    /**
     * Resolve the public-page slug for the current context. Defaults to
     * "databases". Sanitised to the slug charset so it can never inject an
     * unexpected route segment.
     */
    private function _resolvePageSlug($contextId = null)
    {
        if ($contextId === null) {
            $contextId = $this->_currentContextId();
        }
        $slug = $contextId ? (string) $this->getSetting($contextId, 'pageSlug') : '';
        $slug = trim($slug);
        if ($slug === ''
            || !preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,99}$/', $slug)
            || in_array($slug, self::getReservedSlugs(), true)
        ) {
            return self::DEFAULT_SLUG;
        }
        return $slug;
    }

    /** Public accessor used by handlers/forms to resolve the active slug. */
    public function getPageSlug($contextId = null)
    {
        return $this->_resolvePageSlug($contextId);
    }

    /**
     * Registers Smarty modifiers/functions used by the templates (incl. the
     * {ipm_blocks} theme-embed function). Idempotent.
     */
    public function registerSmartyHelpers($hookName, $args)
    {
        // Bulletproof: this runs on every TemplateManager::display, incl.
        // component/AJAX renders — it must never throw out of the hook.
        try {
            if (!$this->getEnabled()) {
                return false;
            }
            /** @var TemplateManager $templateMgr */
            $templateMgr = $args[0];
            IndexingPageManagerSmartyHelper::register($templateMgr, $this);
        } catch (\Throwable $e) {
            error_log('[indexingPageManager] registerSmartyHelpers skipped: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Inject an "Indexing Page Manager" entry into the OJS admin sidebar menu
     * — its own top-level item, opening the full management UI at
     * /<journal>/indexingPageManager/indexes (served by
     * IndexingPageManagerManageHandler via the LoadHandler hook).
     *
     * Detection strategy: infer "this is a backend page" from the presence of
     * the `menu` Vue state (populated by setupBackendPage()) rather than
     * allowlisting template paths. Visible to journal managers + site admins only.
     */
    public function addSidebarLink($hookName, $args)
    {
        // Never let anything in here throw out of the TemplateManager::display
        // hook (see _getUserRoles() — a 3.5 API removal did exactly that).
        try {
            if (!$this->getEnabled()) {
                return false;
            }

            /** @var TemplateManager $templateMgr */
            $templateMgr = $args[0];

            // Present only on backend (Vue) pages — setupBackendPage() builds
            // this state. On the frontend / component AJAX it's absent.
            $menu = $templateMgr->getState('menu');
            if (!is_array($menu)) {
                return false;
            }

            $request = Application::get()->getRequest();
            $context = $request->getContext();
            if (!$context) return false;

            $user = $request->getUser();
            if (!$user) return false;
            $roles = $this->_getUserRoles($user, $context);
            if (!array_intersect($roles, [ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER])) {
                return false;
            }

            // One of the two setup entry points (the other is manage()):
            // guarantee tables/built-ins/settings exist by the time a
            // manager reaches any backend page. Cheap after the first pass
            // (version-stamped) and inside this method's try/catch.
            $this->_maybeRunSetup((int) $context->getId());

            $dispatcher = $request->getDispatcher();
            $manageUrl  = $dispatcher->url(
                $request, ROUTE_PAGE,
                $context->getPath(),
                'indexingPageManager', 'indexes'
            );

            $router      = $request->getRouter();
            $currentPage = method_exists($router, 'getRequestedPage')
                ? $router->getRequestedPage($request)
                : '';

            // Shape matches core setupBackendPage() menu items: a flat entry
            // keyed by identifier with name/url/isCurrent, plus an `icon`
            // (every top-level item in the 3.5 sidebar has one — items
            // without an icon render blank). 'Settings' is a real core icon.
            $menu['indexingPageManager'] = [
                'name'      => __('plugins.generic.indexingPageManager.name'),
                'url'       => $manageUrl,
                'icon'      => 'Settings',
                'isCurrent' => ($currentPage === 'indexingPageManager'),
            ];
            $templateMgr->setState(['menu' => $menu]);
        } catch (\Throwable $e) {
            error_log('[indexingPageManager] addSidebarLink skipped: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Hook: NavigationMenus::itemTypes — register the databases page as a
     * selectable navigation-menu destination type.
     */
    public function addNavigationMenuItemTypes($hookName, $args)
    {
        if (!$this->getEnabled()) {
            return false;
        }
        $types =& $args[0];
        $types[NMI_TYPE_IPM_DATABASES] = [
            'title'       => __('plugins.generic.indexingPageManager.navMenuItem.title'),
            'description' => __('plugins.generic.indexingPageManager.navMenuItem.description'),
        ];
        return false;
    }

    /**
     * Hook: NavigationMenus::displaySettings — resolve the URL + visibility
     * for our custom menu item type at render time.
     */
    public function setNavigationMenuItemDisplaySettings($hookName, $args)
    {
        $navigationMenuItem = $args[0];
        if ($navigationMenuItem->getType() !== NMI_TYPE_IPM_DATABASES) {
            return false;
        }
        if (!$this->getEnabled()) {
            return false;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        $isVisible = (bool) $context;
        $navigationMenuItem->setIsDisplayed($isVisible);

        if ($context) {
            // Short top-level page route — see getFrontendUrl().
            $navigationMenuItem->setUrl($this->getFrontendUrl($request, $context));
        }
        return false;
    }

    /**
     * Resolve the role IDs the given user holds in the given context.
     *
     * OJS 3.5 removed `UserGroupDAO` — `DAORegistry::getDAO('UserGroupDAO')`
     * now *throws* ("Unrecognized DAO UserGroupDAO"). `Repo::userGroup()
     * ->userUserGroups($userId, $contextId)` exists on BOTH 3.4 and 3.5.
     * The role id is read as `getRoleId()` on 3.4 (DataObject) or the
     * `role_id` / `roleId` attribute on 3.5 (Eloquent model — no
     * getRoleId()). Everything is wrapped so this can never throw out of a
     * hook — worst case it returns [], which the caller reads as "no
     * elevated roles" (link simply hidden).
     *
     * @return int[]
     */
    private function _getUserRoles($user, $context)
    {
        $roles = [];
        try {
            $userGroups = \APP\facades\Repo::userGroup()
                ->userUserGroups((int) $user->getId(), (int) $context->getId());
            foreach ($userGroups as $userGroup) {
                $roleId = self::_readRoleId($userGroup);
                if ($roleId !== null) {
                    $roles[] = (int) $roleId;
                }
            }
            return $roles;
        } catch (\Throwable $e) {
            // Fall through to the legacy DAO (pre-3.4 installs only).
        }
        try {
            $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
            if ($userGroupDao) {
                $userGroups = $userGroupDao->getByUserId($user->getId(), $context->getId());
                while ($userGroup = $userGroups->next()) {
                    $roleId = self::_readRoleId($userGroup);
                    if ($roleId !== null) {
                        $roles[] = (int) $roleId;
                    }
                }
            }
        } catch (\Throwable $e) {
            // give up — caller treats [] as "no elevated roles"
        }
        return $roles;
    }

    /**
     * Read a role id off a UserGroup regardless of era: getRoleId() on the
     * 3.4 DataObject, the `role_id` (or `roleId`) attribute on the 3.5
     * Eloquent model.
     */
    private static function _readRoleId($userGroup)
    {
        if (is_object($userGroup) && method_exists($userGroup, 'getRoleId')) {
            return $userGroup->getRoleId();
        }
        foreach (['roleId', 'role_id'] as $prop) {
            $value = $userGroup->{$prop} ?? null;
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Version-safe CSRF token accessor.
     *
     * OJS's session implementation changed between 3.4 and later 3.5.0.x
     * patch releases: PKP moved CSRF token generation onto Laravel's own
     * session store (`Illuminate\Session\Store`), which exposes the token
     * via `token()` — the old PKP-native `Session::getCSRFToken()` method
     * some installs' `$request->getSession()` still returns does not exist
     * on that class at all, and calling it fatals with `BadMethodCallException:
     * Method Illuminate\Session\Store::getCSRFToken does not exist`
     * (reported against a live 3.5.0.x install). This tries every accessor
     * PKP has used across versions, in order, so the plugin keeps working
     * whichever one a given install's session object actually implements.
     */
    public static function sessionCsrfToken($request)
    {
        $session = $request ? $request->getSession() : null;
        if (!$session) {
            return '';
        }
        foreach (['token', 'getCSRFToken', 'getCsrfToken'] as $method) {
            if (method_exists($session, $method)) {
                try {
                    $token = $session->$method();
                    if ($token !== null) {
                        return (string) $token;
                    }
                } catch (\Throwable $e) {
                    // try the next accessor
                }
            }
        }
        return '';
    }

    /**
     * Plugin actions in the plugin manager grid.
     *
     * IMPORTANT: this deliberately does NOT link to this plugin's own custom
     * page (/indexingPageManager/*, served by IndexingPageManagerManageHandler
     * via the LoadHandler hook). That route depends on the plugin's
     * loadHandler() hook being reached for every request to that page path —
     * which field reports show can 404 on some installs/hosting setups even
     * when register()/Hook::add() are provably wired correctly (proxies,
     * rewrite rules, opcache edge cases, etc. can all interfere with a
     * *custom* page route in ways that are hard to diagnose remotely).
     *
     * Instead, "Manage Indexing Page" opens an AjaxModal that calls this
     * plugin's own manage() method through OJS' core plugin-management
     * routing — the exact same battle-tested mechanism every OJS plugin's
     * "Settings" action has relied on for years. It navigates to the
     * *current* page (the Plugins grid) with op=manage, which core PKP code
     * resolves and dispatches to Plugin::manage() directly — this never
     * depends on our own custom LoadHandler hook, so it cannot suffer the
     * same failure mode. All admin functionality (index/section CRUD, drag
     * reorder, forms, settings) is fully available from this modal; the old
     * custom-page admin (IndexingPageManagerManageHandler) is left in place
     * unchanged as a bonus, bookmarkable entry point for installs where it
     * does work, but is no longer the only way in.
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();

        $manageAction = new LinkAction(
            'indexingPageManagerManage',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb'     => 'manage',
                    'plugin'   => $this->getName(),
                    'category' => 'generic',
                ]),
                $this->getDisplayName()
            ),
            __('plugins.generic.indexingPageManager.action.manage'),
            null
        );

        array_unshift($actions, $manageAction);
        return $actions;
    }

    /**
     * Plugin manager dispatcher — the primary, reliable admin entry point
     * (see getActions() above). Every verb here is served entirely by
     * IndexingPageManagerAdminController, so this and the legacy
     * IndexingPageManagerManageHandler custom page never duplicate business
     * logic — only the transport (modal AJAX vs. full custom page) differs.
     *
     * GET-style verbs return a JSONMessage ({status, content}); the modal
     * loader (or our own admin JS, for verbs fetched after the modal is
     * already open) reads `.content` as HTML to inject. Form *Save verbs
     * are POSTs from our own admin JS (never from the generic modal
     * loader), so they short-circuit with a raw JSON envelope
     * ({ok, message, errors?, formHtml?, successVerb?}) via header()+echo+
     * exit(), matching exactly what IndexingPageManagerManageHandler's
     * equivalent endpoints already return.
     */
    public function manage($args, $request)
    {
        // Locale components are auto-loaded on demand as of OJS 3.4+; no
        // explicit AppLocale::requireComponents() call is needed (or
        // possible — the AppLocale class no longer exists).

        // Ensure tables/built-ins/settings exist before any admin verb runs
        // (cheap after the first pass — version-stamped). This is one of the
        // two entry points that drive setup now that register() no longer
        // does; the other is addSidebarLink().
        $this->_maybeRunSetup();

        $verb = (string) $request->getUserVar('verb');
        $controller = new \APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerAdminController($this);

        // Every verb below is consumed by our own admin JS via fetch(), which
        // expects a JSON body. The controller's _assertPostAndCsrf() throws a
        // bare \Exception on a missing/stale CSRF token or a non-POST request;
        // without this guard that propagated out of manage() as an OJS HTML
        // 500 page (the modal JS then just showed a generic network error).
        // Return a well-formed error envelope instead — {status:false,content}
        // is understood by both consumer shapes (postAction + ipmSubmitWithFiles).
        try {
            switch ($verb) {
                // Initial modal open — self-contained: bundles the admin CSS/JS
                // and a small bootstrap script (nothing else has loaded them
                // yet) around the index list fragment. Every other verb below
                // is fetched by that bootstrap script's own JS afterwards, so it
                // only ever needs to return the bare fragment.
                case '':
                case 'manage':
                case 'indexes':
                    return new JSONMessage(true, $this->_manageModalShellHtml($request, $controller));

                case 'indexForm':     return $controller->indexForm($request);
                case 'indexSave':     $this->_emitManageJson($controller->indexSave($request));
                case 'indexDelete':   return $controller->indexDelete($request);
                case 'indexToggle':   return $controller->indexToggle($request);
                case 'indexReorder':  return $controller->indexReorder($request);

                case 'sections':      return $controller->sectionList($request);
                case 'sectionForm':   return $controller->sectionForm($request);
                case 'sectionSave':   $this->_emitManageJson($controller->sectionSave($request));
                case 'sectionDelete': return $controller->sectionDelete($request);
                case 'sectionToggle': return $controller->sectionToggle($request);
                case 'sectionReorder':return $controller->sectionReorder($request);

                case 'templates':
                case 'templateSelect':return $controller->templateSelect($request);
                case 'templateSave':  $this->_emitManageJson($controller->templateSave($request));

                case 'settings':      return $controller->settings($request);
                case 'settingsSave':  $this->_emitManageJson($controller->settingsSave($request));
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[indexingPageManager] manage(%s) failed: %s: %s',
                $verb, get_class($e), $e->getMessage()
            ));
            return new JSONMessage(false, __('plugins.generic.indexingPageManager.admin.error.invalidRequest'));
        }

        return parent::manage($args, $request);
    }

    /**
     * Build the URL TEMPLATE our admin JS uses to reach every other verb
     * from inside the already-open modal: the current-page "manage" op URL
     * with a literal `__VERB__` placeholder the client swaps out. Built once
     * server-side (correct routing/escaping guaranteed) instead of having
     * client JS try to reconstruct OJS' URL scheme itself.
     */
    private function _manageUrlTemplate($request)
    {
        $router = $request->getRouter();
        return $router->url($request, null, null, 'manage', null, [
            'verb'     => '__VERB__',
            'plugin'   => $this->getName(),
            'category' => 'generic',
        ]);
    }

    /**
     * Self-contained HTML for the modal's first paint: admin stylesheet,
     * the admin JS files, a small bootstrap (config + i18n + the
     * verb-fetch/navigation helpers all admin fragments rely on), and the
     * index list fragment itself.
     */
    private function _manageModalShellHtml($request, $controller)
    {
        $tm = TemplateManager::getManager($request);
        IndexingPageManagerSmartyHelper::register($tm, $this);

        $indexListFragment = $controller->indexList($request);
        $fragmentHtml = method_exists($indexListFragment, 'getContent')
            ? (string) $indexListFragment->getContent()
            : '';

        $csrfToken = $this->sessionCsrfToken($request);

        $jsBootstrap = [
            'config' => [
                'manageUrlTemplate' => $this->_manageUrlTemplate($request),
                'csrfToken'         => $csrfToken,
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

        $tm->assign([
            'ipmJsBootstrapJson' => $jsBootstrapJson,
            'ipmFragmentHtml'    => $fragmentHtml,
            'ipmAssetsBase'      => rtrim($request->getBaseUrl(), '/') . '/' . $this->getPluginPath(),
            'pluginVersionTag'   => $this->_assetVersionTag(),
            // Unique per render: this shell can legitimately appear twice
            // on the same page at once (the "Manage Indexing Page" modal
            // AND the Settings > Website embedded section) — every id in
            // the template is derived from this so the two copies never
            // collide. See the instance-scoping comments in
            // manageModalShell.tpl for how the JS side uses it.
            'ipmInstanceId'      => 'ipm' . bin2hex(random_bytes(6)),
        ]);

        return $tm->fetch($this->getTemplateResource('admin/manageModalShell.tpl'));
    }

    /** "?v=<version>" cache-busting tag for the admin CSS/JS URLs. */
    private function _assetVersionTag()
    {
        try {
            $version = $this->getCurrentVersion();
            if ($version) {
                return '?v=' . rawurlencode((string) $version->getVersionString());
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    /** Emit a raw JSON envelope for a form-save verb and terminate. */
    private function _emitManageJson(array $payload)
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    /**
     * Wire DAOs into the global registry. Safe to call repeatedly.
     */
    private function _registerDAOs()
    {
        DAORegistry::registerDAO('IpmSectionDAO',      new IpmSectionDAO());
        DAORegistry::registerDAO('IpmIndexDAO',        new IpmIndexDAO());
        DAORegistry::registerDAO('IpmIndexSectionDAO', new IpmIndexSectionDAO());
    }

    /**
     * Seed 3 built-in sections on first sight of a journal. Idempotent at the
     * slug level — missing slugs are inserted on the next call and existing
     * rows are left untouched.
     */
    private function _maybeSeedBuiltIns($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) {
            return;
        }

        /** @var IpmSectionDAO $dao */
        $dao = DAORegistry::getDAO('IpmSectionDAO');
        if (!$dao) {
            return;
        }

        $existing = [];
        foreach ($dao->getByJournalId($journalId) as $section) {
            $existing[$section->getSlug()] = true;
        }

        $needed = array_column(self::BUILT_IN_SECTIONS, 'slug');
        if (!array_diff($needed, array_keys($existing))) {
            return;
        }

        foreach (self::BUILT_IN_SECTIONS as $i => $row) {
            if (isset($existing[$row['slug']])) {
                continue;
            }
            $section = $dao->newDataObject();
            $section->setJournalId($journalId);
            $section->setSlug($row['slug']);
            $section->setIsBuiltIn(true);
            $section->setIsActive(true);
            $section->setSeq($i);
            foreach ($this->_seedLocales($journalId) as $locale) {
                $section->setDisplayName(self::_localeSeedText($row, $locale), $locale);
            }
            $dao->insertObject($section);
        }
    }

    /**
     * Self-healing repair pass for existing installs: fixes built-in
     * sections whose display-name settings row(s) are missing or empty for
     * every locale — the symptom of a section-id mix-up during seeding (see
     * IpmSectionDAO::insertObject()'s natural-key fallback, added once this
     * was diagnosed). That fix only prevents the corruption going forward;
     * it can't retroactively repair rows an install already seeded before
     * upgrading, since _maybeSeedBuiltIns() only inserts genuinely *missing*
     * slugs and never revisits ones that already exist. Idempotent and
     * cheap (a handful of built-in sections per journal): runs on every
     * request but only ever writes when something is actually missing, and
     * never touches a section that already has a real label.
     */
    private function _maybeRepairBuiltInLabels($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) {
            return;
        }

        /** @var IpmSectionDAO $dao */
        $dao = DAORegistry::getDAO('IpmSectionDAO');
        if (!$dao) {
            return;
        }

        $bySlug = [];
        foreach (self::BUILT_IN_SECTIONS as $row) {
            $bySlug[$row['slug']] = $row;
        }

        $locales = $this->_seedLocales($journalId);
        foreach ($dao->getByJournalId($journalId) as $section) {
            if (!$section->getIsBuiltIn()) {
                continue;
            }
            $row = $bySlug[$section->getSlug()] ?? null;
            if (!$row) {
                continue; // not one of ours (shouldn't happen, but be safe)
            }

            $names = $section->getDisplayNames();
            $hasAnyLabel = false;
            foreach ($names as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $hasAnyLabel = true;
                    break;
                }
            }
            if ($hasAnyLabel) {
                continue;
            }

            foreach ($locales as $locale) {
                $section->setDisplayName(self::_localeSeedText($row, $locale), $locale);
            }
            $dao->updateLocaleFields($section);
        }
    }

    /**
     * Resolve the locale codes to seed built-in text for: every locale
     * actually installed/supported for this journal (or the site, if no
     * journal-level list is available), so this works regardless of which
     * locale-code convention the running OJS version uses ("en_US" on 3.3,
     * "en" on 3.4+, etc.).
     *
     * @return string[]
     */
    private function _seedLocales($journalId)
    {
        $locales = [];
        try {
            $request = Application::get()->getRequest();
            $context = $request ? $request->getContext() : null;
            if ($context && (int) $context->getId() === (int) $journalId
                    && method_exists($context, 'getSupportedLocales')) {
                $locales = (array) $context->getSupportedLocales();
            }
        } catch (\Throwable $e) {
            // fall through to the Locale facade below
        }
        if (empty($locales)) {
            $locales = array_keys((array) Locale::getSupportedLocales());
        }
        return $locales ?: ['en'];
    }

    /**
     * Resolve seed text for a given installed locale code against a
     * dictionary keyed by BASE language ("en", "tr"). Matches the locale
     * directly first, then by its language prefix (so "en_US", "en_GB", or
     * plain "en" all resolve to the "en" entry), then falls back to English.
     */
    private static function _localeSeedText(array $dict, $locale)
    {
        if (isset($dict[$locale])) {
            return $dict[$locale];
        }
        $lang = strtolower(substr((string) $locale, 0, 2));
        foreach ($dict as $key => $value) {
            if ($key === 'slug') continue;
            if (strtolower(substr((string) $key, 0, 2)) === $lang) {
                return $value;
            }
        }
        return $dict['en'] ?? reset($dict);
    }

    /**
     * Seed sensible defaults for every plugin setting on first enable so the
     * admin lands on a Settings page that is already filled out. Each setting
     * is only persisted if not already present.
     */
    private function _maybeSeedDefaultSettings($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) return;

        $seedLocales = $this->_seedLocales($journalId);
        $pageTitleDict = ['en' => 'Indexes & Databases', 'tr' => 'İndeksler ve Veritabanları'];
        $pageTitle = [];
        $introText = [];
        foreach ($seedLocales as $locale) {
            $pageTitle[$locale] = self::_localeSeedText($pageTitleDict, $locale);
            $introText[$locale] = '';
        }

        $defaults = [
            'pageTitle'       => ['object', $pageTitle],
            'introText'       => ['object', $introText],
            'pageSlug'        => ['string', self::DEFAULT_SLUG],
            'displayTemplate' => ['string', self::DEFAULT_TEMPLATE],
            'displayColumns'  => ['int',    4],
            'enableSchemaOrg' => ['bool',   true],
        ];

        foreach ($defaults as $name => $spec) {
            list($type, $value) = $spec;
            $current = $this->getSetting($journalId, $name);
            $isUnset = ($current === null)
                    || ($current === '')
                    || (is_array($current) && empty($current));
            if ($isUnset) {
                $this->updateSetting($journalId, $name, $value, $type);
            }
        }
    }

    /**
     * Auto-seed the bundled demo indexes + logos on first activation — ONLY
     * when the plugin was distributed in its "demo build" form, i.e. when a
     * `demo-data/` directory ships alongside the plugin.
     *
     *   - Detects the demo payload by looking for `demo-data/indexes.json`
     *     and `demo-data/logos/` next to this plugin file.
     *   - Guards re-runs with the `demoIndexesSeeded` plugin setting.
     *   - Bails when the demo payload is absent (production build no-op) or
     *     when the journal already has indexes (admin content preserved).
     */
    private function _maybeSeedDemoIndexes($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) return;

        if ($this->getSetting($journalId, 'demoIndexesSeeded')) return;

        $demoDir  = $this->getPluginPath() . '/demo-data';
        $jsonPath = $demoDir . '/indexes.json';
        $logosDir = $demoDir . '/logos';
        if (!is_dir($demoDir) || !is_file($jsonPath) || !is_dir($logosDir)) {
            return; // production build: no demo payload
        }

        $rows = @json_decode(@file_get_contents($jsonPath), true);
        if (!is_array($rows) || empty($rows)) return;

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        /** @var IpmIndexDAO $indexDao */
        $indexDao   = DAORegistry::getDAO('IpmIndexDAO');
        /** @var IpmIndexSectionDAO $pivot */
        $pivot      = DAORegistry::getDAO('IpmIndexSectionDAO');
        if (!$sectionDao || !$indexDao || !$pivot) return;

        // If the journal already has indexes, don't overwrite — only the
        // built-in sections were seeded above.
        foreach ($indexDao->getByJournalId($journalId, false) as $_) {
            $this->updateSetting($journalId, 'demoIndexesSeeded', true, 'bool');
            return;
        }

        $sectionsBySlug = [];
        foreach ($sectionDao->getByJournalId($journalId) as $section) {
            $sectionsBySlug[$section->getSlug()] = $section;
        }
        if (empty($sectionsBySlug)) return;

        $publicFileManager = new PublicFileManager();
        $targetDir = $publicFileManager->getContextFilesPath((int) $journalId)
            . DIRECTORY_SEPARATOR . 'indexingPageManager' . DIRECTORY_SEPARATOR . 'logos';
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

        foreach ($rows as $row) {
            $slug = $row['slug'] ?? null;
            if (!$slug || !isset($sectionsBySlug[$slug])) continue;

            $index = $indexDao->newDataObject();
            $index->setJournalId($journalId);
            foreach ($this->_seedLocales($journalId) as $loc) {
                $index->setName(       self::_localeSeedText((array) ($row['name']        ?? []), $loc), $loc);
                $index->setDescription(self::_localeSeedText((array) ($row['description'] ?? []), $loc), $loc);
            }
            $index->setUrl($row['url'] ?? null);
            $index->setIsActive(true);
            $indexId = $indexDao->insertObject($index);

            // Copy the bundled logo into the journal's public dir.
            $logoFile = isset($row['logo']) ? basename((string) $row['logo']) : '';
            $src = $logosDir . DIRECTORY_SEPARATOR . $logoFile;
            if ($logoFile !== '' && is_readable($src)) {
                $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $filename = sprintf('index-%d-%s.%s',
                        (int) $indexId,
                        substr(md5($indexId . microtime(true)), 0, 8),
                        $ext);
                    $dst = $targetDir . DIRECTORY_SEPARATOR . $filename;
                    if (@copy($src, $dst)) {
                        @chmod($dst, 0644);
                        $index->setLogoPath('indexingPageManager/logos/' . $filename);
                        $indexDao->updateObject($index);
                    }
                }
            }

            $pivot->attach($indexId, $sectionsBySlug[$slug]->getId());
        }

        $this->updateSetting($journalId, 'demoIndexesSeeded', true, 'bool');
    }

    /**
     * Stamp recorded against the `demoIndexesPatchVersion` setting. Bump this
     * WHENEVER `demo-data/indexes.json` gains richer data that older demo
     * installs should pick up on upgrade. The patcher fills only *empty*
     * fields on demo-seeded rows (matched by URL), so admin edits are kept.
     */
    const DEMO_INDEXES_PATCH_VERSION = '0.1.0';

    /**
     * Apply the latest demo dataset to previously seeded indexes on upgrade.
     * No-op on production (no demo-data/), on un-seeded journals, and on
     * journals already at the current stamp.
     */
    private function _maybeUpgradeDemoIndexes($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) return;

        $stamp = (string) $this->getSetting($journalId, 'demoIndexesPatchVersion');
        if ($stamp === self::DEMO_INDEXES_PATCH_VERSION) return;

        if (!$this->getSetting($journalId, 'demoIndexesSeeded')) return;

        $jsonPath = $this->getPluginPath() . '/demo-data/indexes.json';
        if (!is_file($jsonPath)) return; // production build → no demo payload

        $rows = @json_decode(@file_get_contents($jsonPath), true);
        if (!is_array($rows) || empty($rows)) return;

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        if (!$indexDao) return;

        $byUrl = [];
        foreach ($indexDao->getByJournalId($journalId, false) as $existing) {
            $key = (string) $existing->getUrl();
            if ($key !== '') $byUrl[$key] = $existing;
        }
        if (!$byUrl) {
            $this->updateSetting($journalId, 'demoIndexesPatchVersion',
                self::DEMO_INDEXES_PATCH_VERSION, 'string');
            return;
        }

        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '' || !isset($byUrl[$url])) continue;
            $index   = $byUrl[$url];
            $touched = false;
            foreach ($this->_seedLocales($journalId) as $loc) {
                $newDesc = (string) self::_localeSeedText((array) ($row['description'] ?? []), $loc);
                if ($newDesc === '') continue;
                $current = (string) $index->getDescription($loc);
                if ($current !== '') continue; // never overwrite admin edits
                $index->setDescription($newDesc, $loc);
                $touched = true;
            }
            if ($touched) $indexDao->updateObject($index);
        }

        $this->updateSetting($journalId, 'demoIndexesPatchVersion',
            self::DEMO_INDEXES_PATCH_VERSION, 'string');
    }

    /**
     * Hook callback fired by Context::delete. Sweeps every ipm_* row that
     * references the deleted journal_id, plus its logo files. The hook passes
     * the Context being deleted by reference (array(&$context)).
     *
     * @return false  Don't short-circuit other listeners.
     */
    public function cleanupOnJournalDelete($hookName, $args)
    {
        $context = $args[0] ?? null;
        $journalId = is_object($context) ? (int) $context->getId() : (int) $context;
        if (!$journalId) return false;
        if (!$this->_tablesExist()) return false;

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');

        if ($indexDao) {
            foreach ($indexDao->getByJournalId($journalId, false) as $index) {
                if ($index->getLogoPath()) {
                    IpmLogoStore::deleteByPath($journalId, $index->getLogoPath());
                }
                $indexDao->deleteObject($index);
            }
        }
        if ($sectionDao) {
            foreach ($sectionDao->getByJournalId($journalId) as $section) {
                $sectionDao->deleteObject($section);
            }
        }
        return false;
    }

    /**
     * Resolve the active journal id, preferring the explicit $mainContextId
     * passed to register() and falling back to the current request context.
     */
    private function _resolveContextId($mainContextId = null)
    {
        if ($mainContextId !== null && $mainContextId !== CONTEXT_SITE) {
            return (int) $mainContextId;
        }
        return $this->_currentContextId();
    }

    private function _currentContextId()
    {
        $request = Application::get()->getRequest();
        if (!$request) return null;
        $context = $request->getContext();
        return $context ? (int) $context->getId() : null;
    }

    /**
     * Built-in slugs — used by the section form to lock slug rename and by
     * the delete handler to refuse deletion.
     *
     * @return string[]
     */
    public static function getBuiltInSlugs()
    {
        return array_column(self::BUILT_IN_SECTIONS, 'slug');
    }

    /**
     * Full built-in section definitions ([slug, en, tr]). Exposed so the
     * demo seeder can (re)create the built-in sections in a CLI context where
     * the plugin's register()/seed hooks don't run.
     *
     * @return array[]
     */
    public static function getBuiltInSections()
    {
        return self::BUILT_IN_SECTIONS;
    }

    /**
     * Locale code => display name map (e.g. "en_US" => "English"), for the
     * per-locale tabs/badges on the admin forms. Replaces the old
     * AppLocale::getAllLocales() call, which returned this same shape but no
     * longer exists as of OJS 3.4+ — locale metadata now comes from the
     * Locale facade as LocaleMetadata objects, so this normalises it back to
     * a simple map for the templates.
     *
     * @return array<string,string>
     */
    public static function localeDisplayNames()
    {
        $names = [];
        foreach (Locale::getLocales() as $code => $metadata) {
            $names[$code] = method_exists($metadata, 'getDisplayName')
                ? $metadata->getDisplayName()
                : $code;
        }
        return $names;
    }
}
