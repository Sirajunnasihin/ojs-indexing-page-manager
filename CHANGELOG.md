# Changelog — Indexing Page Manager

All notable changes to this plugin are documented here. Versioning follows the
plugin's `version.xml` `<release>` value; every shipped change bumps it.

## 0.4.8 — 2026-08-29

- **Fix: the new sidebar entry led to "The operation you tried to access is
  either private or doesn't exist" (authorization denied).**
  `IndexingPageManagerManageHandler::authorize()` wrapped
  `ContextAccessPolicy` inside a `PolicySet(COMBINING_PERMIT_OVERRIDES)`
  together with a `PKPSiteAccessPolicy`. When the site policy permitted
  first (e.g. for a Site Admin) the set short-circuited before
  `ContextAccessPolicy` ran — so `RoleBasedHandlerOperationPolicy` never
  called `markRoleAssignmentsChecked()`, and `PKPHandler::authorize()`'s
  final guard (`$decision == PERMIT && (empty($_roleAssignments) ||
  $_roleAssignmentsChecked)`) returned `false` despite the PERMIT decision.
  `authorize()` now adds `ContextAccessPolicy` alone — the exact pattern
  core `PKPManageHandler` / `SettingsHandler` use. It permits a Journal
  Manager *or* a Site Admin and marks the role assignments checked.

## 0.4.7 — 2026-08-29

- **Fix: sidebar entry never appeared even with no errors in the log.**
  0.4.6 stopped `_getUserRoles()` from *throwing*, but its role read
  (`$userGroup->getRoleId()`) still doesn't exist on OJS 3.5, where
  `UserGroup` is an Eloquent model with a `role_id` attribute and no
  `getRoleId()` method — so the call failed, the catch swallowed it, roles
  came back empty, and `addSidebarLink()` bailed before adding the item.
  New `_readRoleId()` helper reads `getRoleId()` (3.4 DataObject) OR the
  `roleId` / `role_id` attribute (3.5 model). The sidebar item should now
  show for Journal Managers / Site Admins.
- **Fix: "Installed Plugins" list stuck on "Loading".** Root cause was
  `register()` calling `_maybeRunSetup()` (migration + seeding) on *every*
  request — which, with the plugin non-lazy since 0.4.4, included the
  plugins grid's own AJAX fetch. DB writes on that hot path made the
  component response unreliable. `register()` now only registers hooks;
  `_maybeRunSetup()` runs solely from the admin entry points —
  `manage()` (modal) and `addSidebarLink()` (any backend page) — where a
  context is present and DB work is expected. Still version-stamped, so
  it's one cheap `getSetting()` after the first pass.
- `registerSmartyHelpers()` wrapped in try/catch too — no
  `TemplateManager::display` callback can break a page render now.
- **Public page URL changed** from `/index.php/<journal>/ipmShowcase` to
  **`/index.php/<journal>/indexes-and-databases`** (hyphens are valid — core
  `cleanFileVar()` allows `[\w-]`). The old `/ipmShowcase` page,
  `/gateway/plugin/ipmShowcase`, and `/about/<slug>` all still resolve as
  fallbacks; `getFrontendUrl()` (Preview button, Settings public-URL line,
  Navigation Menu item) now emits the new URL.

## 0.4.6 — 2026-08-29

- **Fix: `Exception: Unrecognized DAO UserGroupDAO` thrown from the
  `TemplateManager::display` hook on every backend page** (reported after
  deploying 0.4.5 — the sidebar entry still didn't appear because
  `addSidebarLink()` threw before it could add the item). OJS 3.5 removed
  `UserGroupDAO`; `DAORegistry::getDAO('UserGroupDAO')` now *throws* instead
  of returning null. `_getUserRoles()` now uses
  `Repo::userGroup()->userUserGroups($userId, $contextId)` (present on both
  3.4 and 3.5), falls back to the legacy DAO only for older installs, and
  can no longer throw — worst case it returns `[]` and the link is hidden.
  The whole `addSidebarLink()` body is also wrapped so nothing in it can
  ever break `TemplateManager::display` again.
- **The sidebar item now carries an `icon`** (`'Settings'`). Every top-level
  entry in the 3.5 admin sidebar has one; an item without an icon renders
  blank. Item shape (`name` / `url` / `icon` / `isCurrent`, appended to the
  `menu` Vue state) now matches core `setupBackendPage()` exactly.

## 0.4.5 — 2026-08-29

Follow-ups to the 0.4.4 non-lazy switch, reported on the same install.

- **Fix: "Installed Plugins" list stuck on "Loading" forever, and the
  Indexing Page Manager admin UI rendering as a stray block at the bottom of
  *Settings → Website*.** Both came from the `Template::Settings::website`
  hook (`injectWebsiteSettingsTab()`), which appended a full self-contained
  admin shell — CSS, JS bootstrap, the whole index list — into the Website
  settings page. Now that the plugin actually loads (0.4.4), that block
  rendered for real; its injected `<script>` ran on the settings page and
  broke the Vue plugins grid's init, leaving it spinning. **The
  `Template::Settings::website` embedding is removed entirely**
  (`injectWebsiteSettingsTab()` and `templates/admin/websiteSettingsSection.tpl`
  deleted). Management is now reached two ways, both unaffected by this:
  the **sidebar entry** (below) and the plugin grid's **"Manage Indexing
  Page"** modal.
- **The sidebar now carries a dedicated "Indexing Page Manager" entry.**
  `addSidebarLink()` already added one, but it never appeared because the
  plugin wasn't loading on backend page requests (the lazy-load bug). It now
  shows for Journal Managers / Site Admins and opens the full management UI
  at `/<journal>/indexingPageManager/indexes` — which works now that the
  `LoadHandler` hook is reliably registered. Label changed from "Manage
  Indexing Page" to "Indexing Page Manager".
- **All hooks are now registered unconditionally in `register()`** (not just
  the two routing hooks from 0.4.3). Since the plugin is non-lazy,
  `register()` runs once per request with `$mainContextId = null` — before
  the context is resolved — so the old `if ($this->getEnabled($ctx))` gate
  around hook registration evaluated `false` and dropped the sidebar link,
  nav-menu type and Smarty helpers for the whole request. Each callback
  (`registerSmartyHelpers`, `addSidebarLink`, `addNavigationMenuItemTypes`,
  `setNavigationMenuItemDisplaySettings`) now does its own `getEnabled()`
  check at call time, when the context is resolved.
- **DB seeding/migration no longer runs on every request.** With the plugin
  non-lazy, the old unconditional seed block would fire its
  `_maybeSeedBuiltIns` / `_maybeRepairBuiltInLabels` / `_maybeSeedDefaultSettings`
  / demo passes on *every* page load. It's now `_maybeRunSetup()`, guarded by
  a per-journal `ipmSetupStamp` setting so it does real work only once per
  plugin version, with a safety-net call the first time a manager opens a
  backend page.

## 0.4.4 — 2026-08-29

- **Fix: the public showcase still 404'd on every route, with nothing in the
  error log, even after 0.4.3.** 0.4.3 correctly made the routing hooks
  unconditional — but that only helps if `register()` runs at all, and on
  the reporting install it never did. `version.xml` shipped
  `<lazy-load>1</lazy-load>`, and OJS 3.5's `VersionDAO::getCurrentProducts()`
  includes a lazy-load plugin in the loaded set **only** when its `enabled`
  setting matches the context resolved *at load time*:

  ```
  WHERE ps.setting_value = 1        -- enabled for this context / sitewide
     OR v.lazy_load != 1            -- ...or the plugin isn't lazy-load
  ```

  Core loads the generic category in `Dispatcher::dispatch()`
  (`PluginRegistry::loadCategory('generic', true)`) *before* it can reliably
  resolve the journal context — it force-reloads the context on the very
  next line. On this install that early resolution fell back to SITE, so the
  per-journal `enabled=1` row didn't match, the plugin was skipped entirely,
  `register()` never ran, no hooks were added, and `/…/ipmShowcase`,
  `/gateway/plugin/ipmShowcase` and `/about/<slug>` all 404'd silently.
  - **`version.xml` is now `<lazy-load>0</lazy-load>`.** The plugin loads on
    every request (`v.lazy_load != 1` branch), so `register()` always runs
    and the unconditional routing hooks from 0.4.3 always register. The real
    per-journal enabled check happens inside the hook callbacks
    (`loadHandler()`, `registerGatewayPlugin()`), which run during routing —
    after the context has been force-reloaded — so a disabled journal still
    gets a clean 404 and never has `/about/<slug>` shadowed. `register()`
    short-circuits cheaply when the plugin is disabled for the context.
  - **After upgrading, install the new version** so the `versions.lazy_load`
    column actually flips to 0: upload the `.tar.gz` through *Settings →
    Website → Plugins → Upload a New Plugin* (the upgrade path runs the
    version check), or run
    `php lib/pkp/tools/installPluginVersion.php plugins/generic/indexingPageManager/version.xml`.
    Simply copying files over will not update that column.

## 0.4.3 — 2026-08-29

- **New: shorter public URL.** The showcase now has a top-level page route,
  `/index.php/<journal>/ipmShowcase`, and that is what the plugin advertises
  everywhere (admin "Preview" button, the Settings page's public-URL line,
  the Navigation Menu item) via `getFrontendUrl()`. The longer
  `/gateway/plugin/ipmShowcase` and `/about/<slug>` routes still work as
  fallbacks so existing bookmarks and nav-menu items don't break;
  `getGatewayFrontendUrl()` returns the gateway form if it's ever needed.
  This is only viable now that the LoadHandler-hook 404 below is fixed —
  the top-level route depends on that hook.
- **Fix: the public showcase page 404'd on BOTH of its routes**
  (`/gateway/plugin/ipmShowcase` and `/about/<slug>`), reported against a
  live 3.5 install (`cistaverse.litpam.com`). Root cause was an
  ordering/context problem, not the routes themselves:
  `Dispatcher::dispatch()` loads the `generic` plugin category exactly once
  per request — `PluginRegistry::loadCategory('generic', true)` with **no
  context id**, and *before* it resolves the journal context
  (`Router::getContext($request, true)` runs on the next line) and before
  routing. So `IndexingPageManagerPlugin::register('generic', …, null)`
  ran with no resolvable context, `_resolveContextId(null)` fell back to
  `null`, and `getEnabled(null)` read the **site-level** `enabled` flag —
  always unset for this per-journal plugin — and returned `false`. That
  gated out the *entire* hook block, so
  `Hook::add('PluginRegistry::loadCategory', …)` and
  `Hook::add('LoadHandler', …)` never ran. `GatewayHandler::__construct()`
  then called `loadCategory('gateways')`, found no `ipmShowcase` entry, and
  core threw `NotFoundHttpException`. Normal page handlers got a second
  chance because `TemplateManager` re-loads `generic` *with* a context, but
  the Gateway handler and the `LoadHandler` hook both fire during
  `route()`, before any `TemplateManager` exists — so there was no second
  chance for either public route.
  - The two **routing** hooks (`LoadHandler`, `PluginRegistry::loadCategory`)
    are now registered **unconditionally** on every request, no longer
    behind the `register()`-time `getEnabled()` check. The real per-journal
    enabled check moved *into* the callbacks (`loadHandler()`,
    `registerGatewayPlugin()`), which run during routing — after the
    context is resolved, where `$this->getEnabled()` is trustworthy —
    and `IndexingPageManagerGatewayPlugin::fetch()` / the handlers still
    re-check as defence-in-depth. `loadHandler()` returns `false` when the
    plugin is disabled so it can never shadow a journal's real
    `/about/<slug>` page. All DB seeding/migration and the non-routing
    hooks stay gated as before.
- **Fix: the public showcase page had no visible keyboard focus indicator on
  the index tiles (WCAG 2.4.7 "Focus Visible").** `frontend-grid.css` styled
  the mouse `:hover` state of `.ipm-card` (border + shadow + lift) but defined
  no `:focus`/`:focus-visible`/`:focus-within` rule at all, and
  `.ipm-card-link` sets `text-decoration: none` — so a keyboard user tabbing
  through the logo grid got zero indication of which tile was focused (most
  themes' generic `a:focus` underline has no effect on a link that contains
  only an `<img>`). Added a focus treatment that mirrors the hover lift and
  adds a high-contrast outline ring (uses `--ipm-accent`, with an explicit
  `#1e3a8a` fallback, since the tile is a fixed light surface on every theme
  incl. dark mode). The ring is drawn on `.ipm-card` itself via
  `:focus-within`, because `.ipm-card` sets `overflow: hidden` which would
  clip an outline on the inner `<a>`; a `:has()` rule keeps it keyboard-only
  (suppressed for mouse focus), and an `@supports not (selector(:has(*)))`
  block falls back to an inset ring on the link for older engines. Also
  bumps the outline width under `prefers-contrast: more` / `forced-colors`.
- No template, PHP, schema or locale changes — the fix is entirely within the
  plugin's own compiled frontend stylesheet.

## 0.4.2 — 2026-08-19

- **Cleaner public showcase URL.** The Gateway route added in 0.4.0
  (`/gateway/plugin/IndexingPageManagerGatewayPlugin`) used the plugin's own
  class name as the URL segment — technically correct but needlessly ugly
  for something meant to be a shareable public page. `IndexingPageManagerGatewayPlugin::PLUGIN_PATH`
  is now `ipmShowcase`, giving `/gateway/plugin/ipmShowcase` instead.
- **New: the admin UI is now also embedded directly on Settings > Website**,
  not just reachable via the plugin grid's modal. Confirmed against PKP's
  own `backendUiExample` reference plugin (the user's suggestion) that the
  underlying mechanism for adding content to that page is a plain PHP/Smarty
  hook — `Hook::add('Template::Settings::website', ...)` appending rendered
  HTML to `$args[2]` — not a Vue.js/Vite build pipeline; that newer,
  heavier mechanism (documented in the 3.5 Plugin Guide's "Extending
  Backend UI with Vue.js") is for injecting components into *existing*
  Vue-driven pages (Dashboard, Workflow, FileManager, etc.), which the
  Website settings page (still classic PHP/Smarty/jQuery in 3.5) isn't.
  Implemented as a purely *additive* section appended to the page — not an
  attempt to register as a native jQuery-UI tab next to Appearance/Setup/
  Plugins/Navigation Menus, since the exact surrounding tab markup couldn't
  be verified against a live 3.5.0.x install before shipping this, and
  guessing wrong there risked breaking the journal's *existing*, working
  Website settings tabs. An appended block can't corrupt anything around it
  regardless of exactly where in the page it lands. Reuses the exact same
  self-contained shell already built for the "Manage Indexing Page" modal —
  same CSS/JS/tab bar, nothing new to maintain.
  - Because the same shell can now legitimately render twice on one page at
    once (the modal *and* the embedded section, if both happen to be open
    together), every DOM id in `manageModalShell.tpl` that used to be fixed
    (`ipmModalRoot`, `ipmModalBody`) is now generated per-render and scoped
    via a `data-ipm-instance` attribute; navigation, tab highlighting, and
    form-save handling all resolve their target instance from the
    triggering element/script rather than a hardcoded id, so the two
    copies can no longer interfere with each other's content.
  - Known residual limitation: `indexList.js`'s toggle/delete/reorder
    binding is still scope-selector-based (`.ipm-admin`, jQuery's own
    `.off('.ipm')`/`.on('.ipm')` re-binding pattern), which is safe but not
    as strictly per-instance-isolated as the navigation/save paths above.
    In the unlikely event both the modal and the embedded section are open
    at the same time, prefer finishing an edit in one before switching to
    the other.
- The plugin grid's "Manage Indexing Page" modal is unchanged and remains
  available as before — this is an additional entry point, not a
  replacement.

## 0.4.1 — 2026-08-19

- **Fix: "Indexes & Databases page" was missing from the Navigation Menu
  Items "Add item" type dropdown** (present in the original
  ojs-services/indexingPageManager, missing after this fork's upgrade
  work). `register()` guarded its entire hook-registration block — including
  `Hook::add('NavigationMenus::itemTypes', ...)`, the exact hook that
  populates that dropdown — behind `$this->getEnabled($mainContextId)`,
  trusting whatever `$mainContextId` PluginRegistry happened to pass in for
  a given request. Every other context-dependent method in this class
  already routes through `_resolveContextId()` for exactly this reason: some
  request paths (certain AJAX/component calls, e.g. the Navigation Menu
  Items grid's own "Add Item" dialog) can invoke `register()` with
  `$mainContextId` as `CONTEXT_SITE`/`0` or `null` even when a specific
  journal context is available on the request — and since this plugin is
  enabled per-journal, checking the SITE-level enabled setting in that
  situation reads the wrong flag, comes back false, and silently skips the
  *entire* hook block for that one request, even though the plugin is
  correctly enabled for the journal actually being administered.
  `register()` now resolves its enabled-check context through
  `_resolveContextId()` too, matching every other method.
- Investigated the user's request to move the admin UI back from the 0.3.2
  AjaxModal to a "normal" page registered as its own top-level nav entry
  (alongside Settings/Statistics/Tools/Administration), per the OJS 3.5
  Plugin Guide (docs.pkp.sfu.ca/dev/plugin-guide). Findings, so this doesn't
  get re-litigated later: the guide's own "Add Custom Page" example (as of
  3.5) still documents `LoadHandler` + a custom `PageHandler` as the way to
  add a plugin settings page — i.e. exactly the mechanism already found
  unreliable on this install (see 0.3.2/0.4.0's changelog entries) — so
  reverting to it would likely reintroduce the same 404. The *new* 3.5 "JS
  Hooks" Vue.js mechanism the guide points to instead is documented as being
  for **injecting components into existing core pages/managers** (Dashboard,
  Workflow, FileManager, ReviewerManager, ParticipantManager, GalleyManager)
  — there is no documented, supported way for a plugin to add its own
  top-level primary navigation entry the way Settings/Statistics/Tools/
  Administration are wired in; those remain hardcoded in the Vue admin
  shell. The AjaxModal approach (via `Plugin::manage()`, OJS' own long-
  standing mechanism for a plugin's "Settings"/"Manage" action) remains the
  most reliable available option and is unchanged in this release.

## 0.4.0 — 2026-08-19

Four issues reported against a live upgrade from the original
ojs-services/indexingPageManager, all fixed:

- **Fix: only the last built-in section ("Archiving & Preservation") ever
  showed a label; the other three appeared blank.** Root cause:
  `IpmSectionDAO::insertObject()` trusted `getInsertId()` (backed by
  `Illuminate\Support\Facades\DB::connection()->getPdo()->lastInsertId()`)
  immediately after each INSERT when seeding all four built-in sections
  back-to-back in a single request. When that id came back stale/wrong for
  an earlier section in the loop, `updateLocaleFields()` wrote its display
  name onto whatever section that stale id actually belonged to instead —
  silently corrupting the earlier sections' labels while leaving only the
  last-processed section intact. `insertObject()` now verifies the resolved
  id actually owns the row it just inserted (checked via the section's
  unique `(journal_id, slug)` natural key) and falls back to looking the row
  up by that key if not, so a stale `lastInsertId()` can no longer
  cross-contaminate a different section's settings.
  - Added `IndexingPageManagerPlugin::_maybeRepairBuiltInLabels()`, a
    self-healing pass that runs on every `register()` and repopulates any
    built-in section's display name if it's found empty — this retroactively
    repairs installs that already hit the bug before upgrading (the
    `insertObject()` fix alone only prevents new corruption; it can't fix
    rows that were already written wrong).
- **Fix: sections couldn't be edited, and the Templates tab (display
  template + column count) was completely unreachable.** Both were a direct
  regression from 0.3.2's switch to a modal-based admin (see 0.3.2's
  changelog entry): the index list view only ever had a "+ Add Index"
  button — there was no way at all to navigate to Sections, Templates, or
  Settings from inside the modal, since the legacy page's own tab bar
  (`templates/admin/_page.tpl`) was never carried over. Added a persistent
  tab bar (Manage / Sections / Templates / Settings) to the new
  `templates/admin/manageModalShell.tpl`, living outside `#ipmModalBody` so
  it survives every content swap, with active-tab highlighting driven by
  the current verb.
- **Fix: the public showcase page (`/about/<slug>`), including the admin's
  own "Preview" link, 404'd** — and by extension, so did the Navigation
  Menu item once added, since it pointed at the same URL. Like the admin
  0.3.2 fix, this route depends on this plugin's own `LoadHandler` hook
  being reached for every request to that page — which isn't happening
  reliably on this install for reasons that don't reproduce outside it.
  Added `IndexingPageManagerGatewayPlugin` (`classes/
  IndexingPageManagerGatewayPlugin.php`), registered via the confirmed-
  current PKP pattern (mirrors `pkp/pln`'s `PLNGatewayPlugin`): a
  `PluginRegistry::loadCategory` hook adds it under the "gateways" category,
  giving the showcase a second, *reliable* URL
  (`/gateway/plugin/IndexingPageManagerGatewayPlugin`) resolved entirely by
  core routing — never touching `LoadHandler` at all. New
  `IndexingPageManagerPlugin::getFrontendUrl()` is now the single source of
  truth for that URL, used by the admin "Preview" link, the Settings page's
  public-URL display, and the Navigation Menu item alike. The `/about/<slug>`
  route (and its slug setting) is left in place unchanged as a secondary,
  bonus path for installs where it does work.

## 0.3.3 — 2026-08-19

- **Fix: fatal `BadMethodCallException: Method Illuminate\Session\Store::
  getCSRFToken does not exist`, breaking the new 0.3.2 modal admin entirely**
  on some live OJS 3.5.0.x installs (reported in production). PKP moved CSRF
  token generation onto Laravel's own session store as part of the 3.5
  session/cookie rework (`Illuminate\Session\Store`, which implements CSRF
  access via `token()`) — on installs where `$request->getSession()` returns
  that Laravel object directly, the old PKP-native `Session::getCSRFToken()`
  method this plugin called everywhere (`IndexingPageManagerAdminController`,
  `IndexingPageManagerManageHandler`, and the new
  `IndexingPageManagerPlugin::_manageModalShellHtml()`) simply doesn't exist
  on it, so every one of those calls was a fatal error. Added
  `IndexingPageManagerPlugin::sessionCsrfToken($request)`, a small
  version-safe accessor that tries `token()` (current 3.5.0.x Laravel
  session), then `getCSRFToken()`/`getCsrfToken()` (older PKP-native
  session) in order, and every call site in the plugin now goes through it
  instead of calling the session object directly. This does not affect the
  `{csrf}` Smarty tag used inside the form templates themselves (that's
  core OJS' own implementation, already correct) — only this plugin's own
  CSRF-token reads (building the JS bootstrap config, and validating our own
  POST endpoints) were affected.

## 0.3.2 — 2026-08-19

- **Fix: "Manage Indexing Page" in the plugin list could 404, with no way
  in.** That action navigated to this plugin's own custom page route
  (`/indexingPageManager/*`, served by `IndexingPageManagerManageHandler`
  via the `LoadHandler` hook). Every 0.1.x–0.3.x release up to this point
  chased a series of distinct root causes for that same route 404ing on one
  install or another (see 0.2.3–0.2.7 above) — and on at least one reported
  install it still 404s even with all of those fixes applied, for reasons
  that don't reproduce outside that environment (likely something in how a
  proxy/rewrite layer or opcache handles a *custom* page route specifically).
  Rather than keep chasing environment-specific custom-routing failures,
  "Manage Indexing Page" now opens as a **popup (AjaxModal)** driven by
  `IndexingPageManagerPlugin::manage()` through OJS' own core
  plugin-management routing (`$router->url($request, null, null, 'manage',
  ...)`) — the exact same, long-proven mechanism every OJS plugin's
  "Settings" action already relies on (confirmed against several current,
  actively-maintained namespaced 3.4/3.5 plugins: `pkp/addThis`,
  `pkp/customHeader`, `pkp/pln`). This path never touches our own
  `LoadHandler` hook or custom page route at all, so it can't fail the same
  way.
  - The full admin UI (index/section CRUD, drag-drop reorder, logo upload,
    template + settings forms) is now available from the modal: opening a
    sub-view or saving a form swaps content inside the modal via a small
    fetch()-based navigation layer instead of full-page navigation, so nothing
    inside the modal ever needs the custom page route either.
  - `IndexingPageManagerAdminController` gained `indexSave()`, `sectionSave()`,
    `templateSave()` and `settingsSave()` methods — the save logic that used
    to live only in `IndexingPageManagerManageHandler` — so both the new modal
    entry point and the legacy custom-page entry point call the exact same
    code and can never drift out of sync. `IndexingPageManagerManageHandler`
    itself is unchanged in behaviour and left in place as a bonus,
    bookmarkable entry point for installs where the custom route does work;
    it's simply no longer the *only* way in.
  - New template `templates/admin/manageModalShell.tpl` — a self-contained
    wrapper (admin CSS, admin JS, a small config/i18n bootstrap, and the
    verb-fetch/navigation helpers) used only for the modal's first paint;
    every other verb reuses the same fragment-only responses the legacy admin
    already produced.

## 0.3.1 — 2026-08-19

- **Fix: the "+ Add to this section" button on the section list (and each
  section's own admin row) silently lost the section context.** Clicking it
  opens the new-index form with `?sectionId=N` in the URL — intended to
  pre-check that section's checkbox — but `IndexingPageManagerAdminController::indexForm()`
  only ever read `indexId` from the request, and `IpmIndexForm::initData()`
  returned no `sectionIds` at all for a brand-new index. The button behaved
  identically to the generic "Add Index" button, with no section
  pre-selected. `IpmIndexForm` now accepts an optional `$preselectSectionId`
  constructor argument (ignored once an index has real, saved section
  assignments) and the controller now reads `sectionId` from the request and
  passes it through. No schema, locale or template changes were needed — the
  template/JS already sent the parameter correctly; only the backend was
  dropping it.

## 0.3.0 — 2026-07-23

**Dual-target compatibility: OJS 3.4.0.x AND 3.5.0.x** (was 3.5-only since
0.2.0). Verified by diffing this plugin's every `use`-imported core class,
hook signature, and DAO/Form/Handler contract against the actual `pkp-lib`
and `ojs` source on the `stable-3_4_0` and `stable-3_5_0` branches (not just
release notes) — the two APIs turned out to be almost entirely identical for
everything this plugin touches (namespaced classes, `Hook::add()`, the
`LoadHandler` 4-arg contract, `PKP\controllers\page\PageHandler`,
`PKP\facades\Locale`, `DAO::getInsertId()`/`getLocaleFieldNames()` return
types, the `NavigationMenus::*` and `Context::delete` hooks, and the
`Illuminate\Support\Facades\Schema`/`DB` schema-builder migration — despite
OJS 3.5 bumping its bundled Laravel framework from 9 to 12, the small,
stable subset of the Schema/Migration API this plugin uses is unchanged).
So no version-branching code was needed anywhere.

- **Fix: fatal `Class "PKP\file\PublicFileManager" not found` error on
  logo upload.** `IndexingPageManagerPlugin.php` and `classes/IpmLogoStore.php`
  both imported `PKP\file\PublicFileManager` — but no such class exists (and
  never has, on either 3.4 or 3.5). Only the **abstract base class**
  `PKP\file\PKPPublicFileManager` lives in that namespace; the concrete,
  instantiable class is `APP\file\PublicFileManager` (which extends it).
  Both files now import the correct `APP\file\PublicFileManager`. This bug
  predates this release (it was already present in 0.2.0–0.2.7) and would
  have fatally errored the instant any code path touched a logo file —
  saving/deleting an index logo, or building the logo directory's
  `.htaccess` guard — on **any** OJS version, not just 3.4.
- Confirmed (no code change needed) that `Locale::getAllLocales()` and
  `AppLocale::requireComponents()` — the pre-3.4 APIs this plugin's own
  code comments still reference for historical context — are **not**
  actually called anywhere in the codebase; the real call sites already use
  the correct modern equivalents (`Locale::getLocales()` wrapped by
  `IndexingPageManagerPlugin::localeDisplayNames()`, `Locale::getPrimaryLocale()`,
  `Locale::getSupportedLocales()`), all confirmed present with matching
  signatures on both 3.4 and 3.5.
- Updated `version.xml`, `README.md` (EN + TR) and requirements to reflect
  OJS 3.4.0.x/3.5.0.x + PHP 8.0–8.3 (3.4 needs PHP 8.0+; 3.5 needs PHP 8.2+).
- All plugin PHP files re-verified with `php -l` (PHP 8.3) after the fix —
  zero syntax errors.

## 0.2.7 — 2026-07-19

- **Fix: plugin could show "Enabled" with the correct version installed and
  still 404 on every custom page (`/about/<slug>` and
  `/indexingPageManager/*`), on some live installs (reported on OJS
  3.5.0.5), even after the 0.2.6 authorize() fix.**
  `register()` ran its DB seeding/migration logic (`_maybeSeedBuiltIns()`,
  `_maybeSeedDefaultSettings()`, etc.) *before* calling
  `Hook::add('LoadHandler', ...)`. `PluginRegistry::register()` (pkp-lib)
  wraps the whole call to a plugin's `register()` in a try/catch — and
  `register()` runs on *every* request. If any seed step threw for any
  reason on a given install (a DB error, a locale edge case, a schema
  quirk, etc.), the exception was swallowed silently, `register()` returned
  false, and the `Hook::add('LoadHandler', ...)` line simply never
  executed — so every request to the plugin's custom pages hit a bare
  `NotFoundHttpException` (404) with no visible error, while the plugin
  still showed "Enabled" in the plugin list (that flag only reflects a DB
  setting, not whether `register()` completed on a given request).
  All `Hook::add(...)` calls now run first, unconditionally, as soon as the
  plugin is confirmed enabled; the seed/migration block now runs afterward
  wrapped in its own try/catch, so a seeding failure is logged
  (`[indexingPageManager] seed/migration step failed...`) but can no longer
  take the custom pages down with it.

## 0.2.6 — 2026-07-19

- **Fix: public `/about/<slug>` showcase page silently required a login,
  redirecting every logged-out visitor to the login page instead of showing
  the page.**
  The 0.2.5 fix (below) correctly moved `IndexingPageManagerManageHandler`
  (the **backend admin** pages) to `PKP\controllers\page\PageHandler` — that
  part was right and stays. But it *also* moved
  `IndexingPageManagerHandler` (the **public frontend** page) to the same
  base class. `PageHandler::authorize()` adds a `PKPSiteAccessPolicy` whose
  `effect()` unconditionally returns `AUTHORIZATION_DENY` for any request
  without a logged-in user, regardless of op — it's designed for internal
  backend components (`tasks`, `css`), not public pages. Since
  `IndexingPageManagerHandler` never overrode `authorize()`, it inherited
  that check, so every anonymous visitor to `/about/databases` (or whatever
  slug is configured) got bounced to `/login` instead of seeing the
  showcase — the plugin's entire public-facing purpose.
  `IndexingPageManagerHandler` now extends `APP\handler\Handler` again
  (matching core `AboutContextHandler`/`ArticleHandler`) and defines its own
  minimal `authorize()` with just `ContextRequiredPolicy`, so anonymous
  visitors are permitted while backend/admin logic (unchanged) still
  requires login via `IndexingPageManagerManageHandler`.
- **Fix: `pageSlug` could be set to a word already used by the core About
  page (`contact`, `submissions`, `editorialMasthead`, `editorialHistory`,
  `index`), silently shadowing that built-in page instead of producing an
  error.** Added `IndexingPageManagerPlugin::getReservedSlugs()` and wired
  it into both the slug resolver (`_resolvePageSlug()`) and
  `IpmSettingsForm` (new `FormValidatorCustom` check + locale string
  `settings.error.pageSlugReserved`, added for en/en_US/tr/tr_TR).

## 0.2.5 — 2026-07-17

- **Fix: root cause of the "manage indexing page" 404 with no error log.**
  Both page handlers now extend `PKP\controllers\page\PageHandler` (not
  `APP\handler\Handler`) and accept the plugin instance through their
  constructor — `new IndexingPageManagerManageHandler($this)` and
  `new IndexingPageManagerHandler($this)` — matching PKP's documented
  `LoadHandler` example exactly ("Example - Add Custom Page" in the Plugin
  Guide).
  - The 0.2.0–0.2.4 releases *did* correctly assign the handler object to
    the hook's 4th argument (`$args[3]`) instead of the old
    `HANDLER_CLASS`/`$sourceFile` approach, which is the other half of the
    3.4+ change — but constructed both handlers with **no constructor
    argument** and left the base class as the generic `APP\handler\Handler`.
    Per the plugin sidebar link not even appearing, this suggests the
    handler construction itself was silently failing (or being skipped)
    somewhere in the router before ever reaching plugin code — hence a 404
    with no logged error, since nothing in *our* code ever ran.
  - Both handlers now also expose `public $plugin` and read it directly
    (`$this->plugin`) instead of `PluginRegistry::getPlugin('generic',
    'indexingpagemanagerplugin')`, with the registry lookup kept only as a
    fallback.

## 0.2.4 — 2026-07-17

- **Fix: fatal error seeding built-in sections** — `Call to undefined method
  IpmSectionDAO::_getInsertId()`. OJS 3.5's base `PKP\db\DAO` class no
  longer ships the legacy `_getInsertId($table, $column)` convenience
  helper that `getInsertId()` overrides used to delegate to (most DAOs have
  moved to Eloquent/`insertGetId()`-style patterns). `IpmSectionDAO` and
  `IpmIndexDAO` now implement insert-ID resolution themselves via
  `Illuminate\Support\Facades\DB`, driver-aware (MySQL/MariaDB/SQLite vs.
  Postgres, which needs an explicit sequence name).
  - This was the last DAO-signature/legacy-helper mismatch found while
    exercising the plugin end-to-end (enable → tables created → built-in
    sections seeded) against a live OJS 3.5 install; `retrieve()`,
    `update()`, `updateDataObjectSettings()`, and `getDataObjectSettings()`
    were all checked and are unaffected (still core, universally-used DAO
    plumbing).

## 0.2.3 — 2026-07-17

- **Fix: plugin's database tables were never being created**, which
  cascaded into the "manage indexing page" giving a 404 (the page loaded,
  but every query against the missing `ipm_*` tables failed). The real
  error was logged, but silently swallowed by the migration's own
  try/catch: `[indexingPageManager] migration failed: Call to a member
  function connection() on null`.
  - Root cause: the schema migration and `_tablesExist()` called the raw
    `Illuminate\Database\Capsule\Manager` static facade directly. That
    facade only works once `Capsule::setAsGlobal()` has run for the current
    request, which isn't guaranteed at every point in OJS 3.5's request
    lifecycle (this request was an AJAX/component call, not a full page
    load).
  - Fix: switched to the standard `Illuminate\Support\Facades\Schema` and
    `Illuminate\Support\Facades\DB` facades throughout
    `IndexingPageManagerSchemaMigration` and `IndexingPageManagerPlugin::_tablesExist()`.
    These resolve through PKP's own Laravel container (PKP replaced Pimple
    with Laravel's DI toolkit as of 3.5) and are the same facades PKP's own
    Eloquent-based repositories use internally, so they're reliably
    available regardless of request type.
  - Also improved the migration failure log line to include the exception
    class, file, and line number, so if a *different* migration error
    occurs it's immediately diagnosable from the log alone.
  - **After updating**, reload the plugin settings page once — the
    self-healing migration in `register()` will create the `ipm_*` tables
    on that request now that `Schema::` can actually reach the database.

## 0.2.2 — 2026-07-16

- **Fix: fatal error on the plugin settings grid** — `Declaration of
  IpmSectionDAO::getLocaleFieldNames() must be compatible with
  PKP\db\DAO::getLocaleFieldNames(): array`. Same root cause as the 0.2.1
  fix: OJS 3.5's base `DAO` class declares a strict `: array` return type;
  `IpmSectionDAO::getLocaleFieldNames()` and `IpmIndexDAO::getLocaleFieldNames()`
  now declare a matching return type.
- **Locale codes: `en_US`/`tr_TR` → `en`/`tr`.** OJS renamed its English
  locale code from `en_US` to `en` between 3.3 and 3.4 (and Turkish's
  `tr_TR` region suffix is dropped the same way under 3.5's Weblate-aligned
  locale list). The plugin no longer hardcodes either form:
  - Built-in section names and default `pageTitle`/`introText` settings are
    now seeded against whatever locale codes are **actually installed** on
    the journal/site (via `Context::getSupportedLocales()` or the `Locale`
    facade), matched against an internal `en`/`tr` text dictionary by base
    language — so this works whether your install uses `en`, `en_US`,
    `en_GB`, etc.
  - The bundled locale translation files now ship under **both** naming
    conventions (`locale/en/` + `locale/en_US/`, `locale/tr/` + `locale/tr_TR/`)
    so the UI strings load correctly regardless of which locale-code scheme
    your OJS install uses.
  - If you installed 0.2.0/0.2.1 first, built-in sections seeded under the
    old `en_US`/`tr_TR` keys will keep working (existing data isn't
    touched) — new journals enabling the plugin from now on seed under
    whatever locale codes your site actually has installed.

## 0.2.1 — 2026-07-16

- **Fix: fatal error on the plugin settings grid** — `Declaration of
  IpmSectionDAO::getInsertId() must be compatible with PKP\db\DAO::getInsertId(): int`.
  OJS 3.5's base `DAO` class declares `getInsertId(): int` with a strict
  return type; `IpmSectionDAO` and `IpmIndexDAO` now declare a matching
  `: int` return type on their own `getInsertId()` overrides.
  - If you see a similar `Declaration of ... must be compatible with ...`
    fatal error for a *different* method after installing this release,
    it's the same class of issue for a different overridden DAO/Form method
    — the fix is the same: make the child method's signature (including any
    return type shown in the error) match the parent's exactly. See
    `MIGRATION_NOTES.md` for background.

## 0.2.0 — 2026-07-16

**OJS 3.5 compatibility + rebrand to Litpam.** This is a breaking-target
release: the plugin now requires OJS **3.5.0.x** (PHP 8.2–8.3) and is no
longer compatible with OJS 3.3/3.4 installs.

- **Namespaced plugin.** Every class now lives under the
  `APP\plugins\generic\indexingPageManager` namespace tree, as required by
  OJS 3.5 ("plugins without namespaces are no longer supported"). All
  `import('lib.pkp...')`/`import('plugins.generic...')` calls were replaced
  with `use` statements; sibling plugin classes are resolved by PKP's
  namespace-based autoloader instead of manual `Plugin::import()`.
- **`HookRegistry::register()` → `Hook::add()`**, per the OJS 3.4+ hooks API.
- **`HANDLER_CLASS` removed.** `loadHandler()` now assigns the handler
  instance directly to the hook's by-reference 4th argument instead of
  `require_once`-ing a file and `define()`-ing `HANDLER_CLASS`, matching the
  OJS 3.4+/3.5 LoadHandler contract.
- **`AppLocale` removed upstream.** All `AppLocale::*` calls were replaced
  with the `PKP\facades\Locale` equivalents; `AppLocale::requireComponents()`
  calls were dropped entirely (locale components now load on demand).
  `AppLocale::getAllLocales()`'s code→name map is now produced by a small
  `IndexingPageManagerPlugin::localeDisplayNames()` helper built on
  `Locale::getLocales()`.
- File names changed from `*.inc.php` to `*.php` to match current OJS plugin
  conventions.
- Rebranded: plugin is now developed and maintained by **Litpam**
  ([litpam.com](https://litpam.com)); all "OJS Services" / ojs-services.com
  references (README, LICENSE, admin footer, locale metadata) were updated
  accordingly.
- **Not yet re-verified against a live OJS 3.5 install.** The conversion
  follows PKP's documented 3.4/3.5 breaking-change guidance, but hasn't been
  exercised against real OJS 3.5 core code. Test thoroughly on a staging
  install before deploying to production — see the migration notes shipped
  alongside this release.

## 0.1.15 — 2026-06-16

- **Fix: card captions were invisible in a theme's dark mode.** The index cards
  are a fixed light tile (so logos stay legible), but the name/description text
  used `color: inherit`, which picks up the theme's text colour — light in dark
  mode — making it white-on-white and unreadable. Captions now use explicit dark
  colours (`#1f2937` / `#4b5563`) that stay readable on the light tile in both
  light and dark mode, on any theme. Verified against a dark-mode journal.

## 0.1.14 — 2026-06-16

- **Admin forms now show real language names.** The per-locale tabs/badges on the
  index, section and settings forms display the language name (e.g. *English*,
  *Türkçe*) instead of the raw locale code (`en_US`, `tr_TR`). The forms pass a
  code→name map (`AppLocale::getAllLocales()`) and the badge style no longer
  force-uppercases the label.
- **Public-facing documentation.** Rewrote `README.md` as a journal-owner-friendly
  guide in English and Turkish, with screenshots, requirements (OJS 3.3.0.x, PHP
  7.4–8.2) and install/usage steps. Added a `screenshots/` folder for the README
  images.
- Removed the internal developer note file from the package.

## 0.1.13 — 2026-06-16

Pre-release hardening pass (security / compatibility / accessibility review).

- **Fix: journal-delete cleanup never ran.** The cleanup hook was registered on
  `JournalDAO::deleteJournalById`, which OJS 3.3 never fires — so deleting a
  journal orphaned its `ipm_*` rows and logo files. Now registered on the real
  `Context::delete` hook, with the callback adapted to the Context object it
  passes.
- **Fix: LICENSE carried the wrong product name** ("Editorial Board Manager" →
  "Indexing Page Manager").
- **Security/robustness:** the logo directory's `.htaccess` PHP-execution guard
  now fails loudly (logged) instead of silently when it can't be written, and
  the file header documents the nginx/IIS equivalent; Schema.org JSON-LD now
  `strip_tags()`es name/description (defence-in-depth on top of the existing
  JSON hex-encoding); logo URLs are `|escape`d at every template output site.
- **PHP 8.1:** the index settings eager-loader now decodes object values with
  `json_decode()` (matching OJS core) and casts to string before `unserialize`,
  avoiding a null-to-string deprecation.
- **Accessibility:** active-state toggles expose `aria-pressed` (+ `aria-label`)
  and keep it in sync in JS; section titles on the public page are now `<h2>`;
  admin secondary text raised to a WCAG-AA contrast colour.
- **i18n:** all remaining hard-coded UI strings (nav aria-label, slug label, and
  the JS saving/saved/failed/network/invalid-request messages) moved to locale
  keys, added to both en_US and tr_TR (full parity retained).
- **Cleanup:** removed the dead `templates/admin/_assets.tpl`, all debug
  `console.*` calls, and internal cross-references in shipped code/comments; raw
  exception text is no longer surfaced to users (generic localized message).

## 0.1.12 — 2026-06-15

- **Sections are no longer collapsible — always shown in full.** Dropped the
  native `<details>/<summary>` accordion (and its chevron, hover/marker styling
  and open animation) in favour of a plain `<section>` + static header. Every
  section's logos are always visible; there is no expand/collapse control. The
  section title + count badge and the underline separator are unchanged, so the
  look is identical apart from the removed toggle.

## 0.1.11 — 2026-06-15

- **Page title is now centred on every theme, with proper vertical spacing.**
  The page body is the plugin's own space, so the header is treated as our
  content: the `<h1>` is centred and the intro is centred + width-limited, while
  STILL inheriting the active theme's font, size, weight and colour (we set only
  alignment + margins, at normal specificity so it wins over a theme's
  left-aligned content-h1 default). `.ipm-page` also gained top/bottom padding
  (`clamp(20–36px)` top, `clamp(28–48px)` bottom) so the title no longer sits
  flush against the theme's masthead ("stuck to the top") and the grid clears at
  the end. Browser-verified centred on default, defaultManuscript, journalplus,
  nivo and axis. (Atlas is the one exception by design: it prints the page title
  in its own masthead nameplate for ALL pages, so our duplicate stays hidden and
  the title follows Atlas there — consistent with Atlas's own pages.)
- Supersedes the 0.1.10 "stay theme-agnostic/left-aligned" stance at the user's
  request: a tidy, consistently-centred header reads better across themes than a
  top-hugging left title.

## 0.1.10 — 2026-06-15

- **Page now uses the OJS-canonical `page page_*` wrapper.** The showcase is
  wrapped in `<div class="page page_databases …">` — exactly the structure core
  OJS pages (about/contact) and themes such as JournalPlus emit — so each active
  theme treats it like one of its OWN content pages (page padding, the
  `.page h1` rules, the heading underline, the zeroed title top-margin, etc.)
  WITHOUT the plugin coupling to any single theme. Our `.ipm-*` classes ride
  alongside for the grid/cards we own.
- **Verified the title is theme-compatible across ALL six installed themes**
  (each theme's own `/about/contact` title vs our `/about/databases` title,
  computed styles compared):
  - **Pixel-identical** on **default**, **defaultManuscript**, **journalplus**
    (same font, size, weight, colour, alignment, margins — including
    JournalPlus's title underline) and on **atlas** (Atlas prints the page title
    in its masthead nameplate from `pageTitleTranslated`; our de-dupe hides the
    duplicate, leaving one fully Atlas-styled title).
  - **Base-typography match** on **axis** and **nivo**: font, colour (and, on
    nivo, alignment) are inherited automatically, but these themes give *their
    own* hand-built pages a slightly larger bespoke title via a private class
    (`.axis-page-title` 48px/centred, `.nivo-page-title` 36px/800) that is not
    reachable by any plugin — or by core pages those themes don't override —
    without theme coupling. This is the intended, portable "plugin, not theme"
    behaviour.

## 0.1.9 — 2026-06-15

- **Page title now truly follows the theme.** 0.1.8 styled the title with the
  plugin's own typography, so it didn't match the theme's headings. We're a
  plugin, not a theme — so the title is now a plain semantic `<h1>` with NO
  plugin typography and NO theme-specific classes; the active theme styles it
  like its own content headings. Our only CSS is a `:where()` (0-specificity)
  bottom-margin fallback. (Verified on Axis: the heading inherits the theme's
  font, weight, colour and alignment automatically.)
- **Logos render in their own colours.** Removed the grayscale filter; the
  card's hover shadow/lift is the only effect.
- **Better logo-upload hint:** recommends ~400×150 px and notes that
  consistent, small logos look tidier and load faster (transparent PNG best;
  JPG/PNG/WebP accepted).

## 0.1.8 — 2026-06-15

- **Fix: page title disappeared on most themes.** 0.1.5 had removed the
  plugin's own `<h1>` and relied on the theme to print the title — but only some
  themes (e.g. Atlas, in compact masthead mode) render a visible title from
  `pageTitleTranslated`; the default OJS theme and most others render only a
  screen-reader heading, so the title vanished. The layout now ALWAYS prints its
  own visible `<h1 class="ipm-page-title">` (so the title shows on every theme),
  keeps passing `pageTitleTranslated` (correct `<title>` tag), and a small
  script HIDES our `<h1>` when the theme already shows a matching visible
  heading — yielding exactly one title on every theme (no duplicate on Atlas,
  no missing title on the default theme).

## 0.1.7 — 2026-06-15

- **Theme-agnostic page width.** Instead of the fixed `1280px` from 0.1.6 (which
  only matched the Atlas theme), the layout now measures the *active theme's own*
  content-column width at runtime — the widest constrained wrapper in the
  theme's masthead/footer — and applies it to `.ipm-page` (centred), so the
  showcase lines up with whatever theme is active. The CSS `var(--ipm-max-width,
  1280px)` remains as the pre-JS / no-match fallback, and a debounced resize
  handler keeps it matched. Page LAYOUT follows the theme; the grid/cards
  (content) stay ours. Still no imposed background, card, font or colour.

## 0.1.6 — 2026-06-15

- **Respect the theme's content width.** 0.1.5 dropped the plugin's own
  max-width container (to stop imposing a card), which made the showcase run
  full-bleed and ignore the theme's centred content column. The frontend now
  constrains + centres `.ipm-page` to `var(--ipm-max-width, 1280px)` with a
  responsive side gutter — listening to the theme's width like its own
  header/footer rows do — while still NOT imposing a background, card, font or
  colour. The container can't exceed its parent, so narrower themes cap it.

## 0.1.5 — 2026-06-15

- **Removed the duplicate page heading.** The active theme already renders the
  page title (via `header.tpl`'s `pageTitleTranslated`); the plugin no longer
  adds its own `<h1>`, so the title shows once.
- **Theme-harmonious frontend.** The plugin no longer imposes a page/body
  background, its own "card", font-family, or text colour — the showcase now
  inherits the active theme's page chrome (incl. dark mode). `_layout.tpl` drops
  the forced wrappers + `nivo-*` coupling; captions inherit the theme text
  colour; only the small logo tiles keep a light surface so logos stay legible.
- **Four display templates** (was two): `logos` (logo only), `logo-name`
  (logo + name), `logo-name-desc` (logo + name + description), `logo-desc`
  (logo + description). Rendering is now a single `template-grid.tpl` driven by
  `withName` / `withDesc` flags from `IndexingPageManagerPlugin::templateFlags()`;
  the template selector shows four SVG-preview cards. The legacy `named` value
  normalises to `logo-name-desc` (`normalizeTemplate()`), so existing installs
  don't break. Default is `logo-name-desc`.

## 0.1.4 — 2026-06-15

- **Re-organised the built-in sections + demo data into 4 real categories**
  (replacing the previous 3): `indexing-and-abstracting` "Indexing &
  Abstracting" (8), `discovery-and-search` "Discovery & Search" (7),
  `identifiers-and-registration` "Identifiers & Registration" (5),
  `archiving-and-preservation` "Archiving & Preservation" (4) — 24 demo
  entries (Scopus, WoS, DOAJ, PubMed/MEDLINE, TR Dizin, ERIH PLUS, EBSCO,
  Index Copernicus; Google Scholar, BASE, CORE, OpenAlex, Dimensions, WorldCat,
  EBSCO Discovery Service; Crossref, ORCID, ISSN/ROAD, DataCite, ROR; LOCKSS,
  CLOCKSS, Portico, PKP PN). Built-in slugs + EN/TR labels + locale keys updated.
- **`getBuiltInSections()`** public getter added; `seed-demo.php` now (re)creates
  the built-in sections in a CLI context before seeding indexes.
- **Demo logos:** 11 real (Scopus, WoS/Clarivate, DOAJ, PubMed, TR Dizin, EBSCO,
  EBSCO Discovery Service, Index Copernicus, Google Scholar, BASE, ISSN/ROAD,
  sourced from ojsdergi.com) + 13 clean text placeholders (ERIH PLUS, CORE,
  OpenAlex, Dimensions, WorldCat, Crossref, ORCID, DataCite, ROR, LOCKSS,
  CLOCKSS, Portico, PKP PN) — drop a real PNG into `demo-data/logos/<name>.png`
  to replace any placeholder.
- Upgrade note: changing the built-in slugs means an existing seeded install
  would get the 4 new sections added alongside the old ones; for a clean switch,
  delete the old sections first (the dev/demo journal was wiped + reseeded).

## 0.1.3 — 2026-06-15

- **Fix: admin form "Save" showed raw JSON instead of saving + redirecting.**
  Each form fragment's inline `<script>` called `window.ipmSubmitWithFiles(...)`
  immediately, but that helper is defined later in `_page.tpl` and the `<form>`
  is parsed after the script — so the call no-op'd, the submit was never
  intercepted, and the browser did a normal POST that displayed the
  `{"ok":true,...}` JSON response. Fixed by deferring every form/list bind to
  `DOMContentLoaded` (matching Editorial Board Manager's `$(function(){…})`
  approach). The re-inject-after-validation path still binds because the
  document is already complete then.
- **Demo logos:** replaced the generated text placeholders with real index
  logos for 16 of the 20 demo entries (Scopus, Web of Science/Clarivate, DOAJ,
  PubMed, PubMed Central, Embase, Engineering Village, TR Dizin, ULAKBİM,
  Reaxys, İDEAL, EBSCO, ProQuest, GALE, J-Gate, British Library). CNKI,
  Crossref, Galenos and Manuscript Manager keep text placeholders.
- **Browser-verified on a live OJS 3.3 install** (journal "nivol"): add, edit,
  delete, active-toggle, logo upload (multipart FormData path), section rename,
  section toggle, settings save, template save, and the public `/about/databases`
  page (20 logos, 3 collapsible sections, 4-column grid, Schema.org JSON-LD).

## 0.1.2 — 2026-06-15

- **Critical fix: global class-name collision with OJS core.** The plugin's
  data/DAO classes were named `Section` and `SectionDAO`, identical to OJS core
  (`classes/journal/Section.inc.php`, `SectionDAO.inc.php`). Because the plugin
  loads its `SectionDAO` on every front-end page (via the `TemplateManager::display`
  hooks) and core loads its own `Section`/`SectionDAO` to render articles/issues,
  the two met in one request and PHP fatally aborted with *"Cannot declare class
  Section, because the name is already in use"* — taking down every article and
  the journal homepage.
- Fix: prefixed **all** generically-named plugin classes with `Ipm` to follow
  the Editorial Board Manager discipline (every global class uniquely prefixed)
  and remove all latent collision risk:
  `Section`→`IpmSection`, `SectionDAO`→`IpmSectionDAO`, `Index`→`IpmIndex`,
  `IndexDAO`→`IpmIndexDAO`, `IndexSectionDAO`→`IpmIndexSectionDAO`,
  `IndexLogoStore`→`IpmLogoStore`, `IndexForm`→`IpmIndexForm`,
  `SectionForm`→`IpmSectionForm`, `SettingsForm`→`IpmSettingsForm`,
  `TemplateForm`→`IpmTemplateForm`. Files renamed to match.
- DAORegistry keys were already prefixed (`IpmSectionDAO`, `IpmIndexDAO`,
  `IpmIndexSectionDAO`), so no `getDAO()` call sites changed — only the
  registered class and `import()`/`new` sites. Verified: no plugin class
  collides with OJS core, and the classes load cleanly even when core's
  `Section`/`SectionDAO` are already declared.

## 0.1.1 — 2026-06-15

- Fix: removed the unused `indexSave` / `sectionSave` cases from the plugin's
  legacy `manage()` verb switch — those saves are handled exclusively by the
  URL-based manage handler (multipart fetch), and the controller has no such
  methods, so the dead cases could fatal if ever dispatched. Every other
  `manage()` verb maps 1:1 to a controller method.

## 0.1.0 — 2026-06-15

Initial release. Built on the architecture proven by the Editorial Board
Manager plugin; the gotchas it documented are pre-applied here.

### Features
- Index entries with logo, multilingual name + description, and external URL.
- Three auto-seeded, immutable built-in sections (Abstracting & Indexing,
  Discovery Services, Publishing Systems) plus admin-defined custom sections.
- Backend admin (URL-addressable, inside the OJS sidebar chrome): index list,
  section list, index form with logo upload, section form, template selector,
  settings. Drag-drop ordering via jQuery UI sortable.
- Public page at `/about/<slug>` (default `databases`, configurable) with two
  templates — **logos only** and **logos + names** — and a 3/4/5-column grid
  that collapses to 2 columns < 640px and 1 column < 420px. Sections render as
  collapsible groups (native `<details>`).
- Navigation Menu integration: the page is registered as a selectable
  destination type (`NMI_TYPE_IPM_DATABASES`) via the
  `NavigationMenus::itemTypes` + `NavigationMenus::displaySettings` hooks.
- `{ipm_blocks}` Smarty function for theme homepage embeds (assign the data, or
  render a ready-made self-styled logo strip).
- Schema.org `CollectionPage` + `Organization` (name/url/logo) JSON-LD, with
  `JSON_HEX_*` escaping so entry names can't break out of the `<script>` tag.
- Turkish + English locale files.

### Architecture notes (gotchas pre-applied from Editorial Board Manager)
- **File-upload forms use `ipmSubmitWithFiles` (FormData + fetch), never
  `AjaxFormHandler`** — the latter serialises via jQuery `$.ajax` and drops
  `<input type="file">` from the multipart body. For a uniform response
  contract, *all* admin forms use this helper and the `{ok, redirect, message,
  formHtml}` envelope keyed on `data.ok`.
- **Asset cache-busting:** every CSS/JS URL carries `?v=<plugin version>` read
  from `Plugin::getCurrentVersion()->getVersionString()` (not
  `VersionDAO::getCurrentVersion`, which without `$isPlugin=true` returns the
  OJS app version).
- **Toast colours are set inline** (`#16a34a` / `#dc2626`) so a host theme
  overriding `.ipm-toast-*` can't change them; the class only handles layout.
- **Backend pages** set `$this->_isBackendPage = true` and call
  `setupTemplate()`; templates extend `layouts/backend.tpl` and put content in
  `{block name="page"}`. No inline `<style>` in that block (Vue strips it) —
  all admin styling is in `styles/compiled/admin.css`.
- **Sidebar detection** keys off `$templateMgr->getState('menu')` rather than
  a template-path allowlist, so other plugins' admin pages aren't broken.
- **Plugin typography is parent-scoped** (`.ipm-page h1`, `.ipm-page
  .ipm-section-title`, …) to win the PKP `.pkp_structure_main hN` specificity
  war.
- **Locale components** (`PKP_USER`, `PKP_COMMON`, `APP_COMMON`) are required
  in the frontend handler before render so theme header strings don't fall
  through to `##key##` sentinels.
- **Idempotent migration:** `hasTable` guards, MyISAM→InnoDB conversion, orphan
  cleanup, and try/catch FKs so re-running on every `register()` is safe.
- **PHP 7.4 / 8.x dual-target:** explicit `(string)` casts; no `match`, `?->`,
  named args, `readonly`, enums, or 8.0-only string functions.
- **Smarty brace trap:** template SVG previews use literal coordinates, not
  computed `{$x*76}` expressions, to avoid the PKP Smarty fork's quirks.
