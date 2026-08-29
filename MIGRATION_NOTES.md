# Migration notes — OJS 3.3 → OJS 3.5, rebrand to Litpam

This document is for whoever maintains this plugin next. It summarizes what
changed in the 0.2.0 conversion and what still needs to be checked on a real
OJS 3.5 installation before this ships to production.

## What changed

1. **Rebrand.** Every "OJS Services" / ojs-services.com reference (README,
   LICENSE, CHANGELOG, admin footer template, locale file metadata) now
   reads **Litpam** / litpam.com. The plugin's internal identifiers (class
   names, directory name, DB table names, settings keys) were **not**
   renamed — only changing those would force a disruptive re-migration for
   existing installs, and OJS keys plugins by directory name, not by the
   developer's brand.
   - The email/domain used (`info@litpam.com`, `https://litpam.com`) are
     placeholders. Replace them with Litpam's real contact details before
     publishing.

2. **Namespacing (required by OJS 3.5).** PKP's 3.5 release notebook states
   plainly: *"Plugins without namespaces are no longer supported. All plugin
   classes should be namespaced."* Every class now lives under
   `APP\plugins\generic\indexingPageManager\...`, matching the directory
   layout (`classes/`, `classes/form/`, `pages/`). All `.inc.php` files were
   renamed to `.php`, matching current PKP plugin conventions.

   **Related, discovered iteratively against a live 3.5 install:** OJS 3.5's
   base `PKP\db\DAO` class declares several methods with strict return
   types (`getInsertId(): int`, `getLocaleFieldNames(): array`, confirmed so
   far). PHP raises a fatal `Declaration of X::method() must be compatible
   with DAO::method(): type` error for *each* overridden method with a
   mismatched signature — but only reports one at a time (compile stops at
   the first mismatch it finds), so fixing one can reveal another on the
   next page load. `IpmSectionDAO` and `IpmIndexDAO` now declare matching
   return types on both methods. If you see this error for a different
   method after applying this release, the fix is identical: add the exact
   return type shown in the error message to the child override.

3. **`import()` → `use`.** All `import('lib.pkp.classes...')` and
   `import('plugins.generic.indexingPageManager...')` calls were removed and
   replaced with `use` statements at the top of each file. This works because
   namespaced classes are autoloaded by PKP's own autoloader; the old
   dot-path `import()`/`Plugin::import()` mechanism was for the
   pre-namespace, non-autoloaded world.

4. **`HookRegistry::register()` → `Hook::add()`.** Per the 3.4+ hooks API
   (`PKP\plugins\Hook`).

5. **`HANDLER_CLASS` removed.** OJS 3.5 no longer supports directing the
   router to a handler via `require_once` + `define('HANDLER_CLASS', ...)`.
   `IndexingPageManagerPlugin::loadHandler()` assigns the handler instance
   straight into the `LoadHandler` hook's by-reference 4th argument
   (`$args[3]`), which is the documented 3.4+/3.5 pattern. **Both page
   handlers also had to change their base class and constructor** to match
   PKP's official example exactly:
   - `IndexingPageManagerHandler` and `IndexingPageManagerManageHandler` now
     extend `PKP\controllers\page\PageHandler`, not `APP\handler\Handler`.
   - Both now declare `public function __construct(IndexingPageManagerPlugin
     $plugin)`, call `parent::__construct()`, and store `$this->plugin =
     $plugin`.
   - `loadHandler()` instantiates them as `new
     IndexingPageManagerManageHandler($this)` / `new
     IndexingPageManagerHandler($this)` — passing the plugin instance in,
     not a bare `new Handler()`.
   - This was discovered the hard way: the earlier "4th-argument" fix alone
     produced a 404 with **no logged error at all**, because handler
     construction itself never completed far enough to run any plugin code.
     If you're adapting another OJS 3.3 plugin's custom page handler to
     3.5, do the base-class + constructor change and the 4th-argument
     change together — the official Plugin Guide's "Add Custom Page"
     example shows both in the same snippet.
   The frontend route still forces `$args[1]` (the `$op`) to the literal
   string `'index'`, because `PKPPageRouter` requires `$op` to be a real
   method name on the handler and the public op here is actually the
   configurable page slug (default `databases`), not a method name.

6. **`AppLocale` removed upstream.** OJS 3.4 dropped the `AppLocale` class
   entirely (`Class "AppLocale" not found` is a well-documented breakage for
   plugins upgrading from 3.3). Every call was ported to the `PKP\facades\Locale`
   facade, which keeps the same method names (`getPrimaryLocale()`,
   `getLocale()`, `getSupportedLocales()`). `AppLocale::requireComponents()`
   calls were deleted outright — locale components load on demand as of
   3.4+, so the explicit require is both unnecessary and impossible (the
   method doesn't exist on `Locale`).
   - `AppLocale::getAllLocales()` used to return a flat `code => name` map,
     which the admin forms use to label per-locale tabs/badges. The `Locale`
     facade's equivalent (`Locale::getLocales()`) returns `LocaleMetadata`
     objects instead of plain strings, so a small helper —
     `IndexingPageManagerPlugin::localeDisplayNames()` — was added to
     reconstruct the same `code => name` shape the templates expect.

7. **DAOs, forms, `Handler`, `TemplateManager`, `DAORegistry`,
   `PluginRegistry`, `Application`, `Core`, `Config`, `PublicFileManager`,
   `LinkAction`/`RedirectAction`, `JSONMessage`, and the `Form`/
   `FormValidator*` classes** were all given explicit `use` statements
   pointing at their PKP 3.4/3.5 namespaced locations (e.g. `PKP\db\DAO`,
   `PKP\form\Form`, `APP\handler\Handler`, `APP\template\TemplateManager`).
   Business logic in these files was otherwise left untouched.

8. **Global constants left alone.** Route/role constants (`ROUTE_PAGE`,
   `ROLE_ID_SITE_ADMIN`, `ROLE_ID_MANAGER`, `CONTEXT_SITE`,
   `STYLE_SEQUENCE_LATE`, etc.) are still defined globally via `define()` in
   OJS 3.5 and don't require namespace-qualification to use from inside a
   namespaced class.

9. **Nothing changed** in: the DB schema/migration logic, the Smarty
   templates' markup (aside from the footer link), the JS admin UI, the CSS,
   or the settings/data model. Existing installs upgrading in place should
   not need a data migration beyond what
   `IndexingPageManagerSchemaMigration` already handles idempotently.

## Locale codes: `en_US`/`tr_TR` → `en`/`tr`

OJS renamed its English locale code from `en_US` to `en` starting with the
3.3→3.4 upgrade, and Turkish's `tr_TR` loses its region suffix the same way
under 3.5's Weblate-aligned locale list. Any plugin that hardcodes
`'en_US'`/`'tr_TR'` as dictionary keys or setting values will silently stop
matching the site's actual locale codes after upgrading — not a fatal error,
just missing/blank translations, which is easy to miss in testing.

This plugin no longer hardcodes either form:
- `IndexingPageManagerPlugin::_seedLocales()` resolves the actual installed
  locale codes for the journal (falling back to the site's supported
  locales, then to `['en']`) at seed time, instead of assuming `en_US`/`tr_TR`.
- `IndexingPageManagerPlugin::_localeSeedText()` matches a locale code
  against the plugin's internal `en`/`tr` text dictionaries by **base
  language** (the part before the underscore), so `en`, `en_US`, and `en_GB`
  all resolve to the same English text.
- The shipped `.po` translation files live under **both** `locale/en/` +
  `locale/en_US/`, and `locale/tr/` + `locale/tr_TR/`, so the UI strings
  load regardless of which convention your OJS install uses.

If you fork this plugin to add more languages, key your seed-data
dictionaries by base language (`'de'`, not `'de_DE'`) and let
`_localeSeedText()`/`_seedLocales()` handle the actual installed locale code
— don't hardcode region-qualified codes anywhere in the PHP.

## Removed legacy DAO helper: `_getInsertId()`

OJS 3.5's base `PKP\db\DAO` no longer provides the old
`_getInsertId($table, $column)` protected convenience method that
`getInsertId()` overrides traditionally delegated to. If you fork this
plugin and add a new DAO, implement `getInsertId(): int` directly against
`Illuminate\Support\Facades\DB` instead of assuming `_getInsertId()` exists
— see `IpmSectionDAO::_lastInsertId()` / `IpmIndexDAO::_lastInsertId()` for
the driver-aware pattern (MySQL/MariaDB/SQLite vs. Postgres, which needs an
explicit `{table}_{column}_seq` sequence name).

## Capsule facade vs. Laravel facades for schema/DB access

If you fork this plugin and add more raw DB/schema code, **don't** reach for
`Illuminate\Database\Capsule\Manager` directly (`Capsule::schema()`,
`Capsule::connection()`) — that static facade requires
`Capsule::setAsGlobal()` to have executed for the current request, which
isn't guaranteed in every OJS 3.5 request context (observed failing on an
AJAX/component route: `Call to a member function connection() on null`).
Use `Illuminate\Support\Facades\Schema` and `Illuminate\Support\Facades\DB`
instead — the standard Laravel facades, resolved through PKP's own
container (PKP replaced Pimple with Laravel's DI toolkit in 3.5), which is
what PKP's own Eloquent-based repositories use internally and is reliably
available regardless of request type.

## What still needs verification on a real OJS 3.5 install

This conversion was written against PKP's published 3.4/3.5 release
notebooks and plugin-guide examples, but **could not be executed against
actual OJS 3.5 core code** in the environment this was produced in. Before
shipping, please verify on a staging OJS 3.5.0.x install:

- The plugin **enables cleanly** from *Settings → Website → Plugins* with no
  fatal errors in the PHP log.
- The **admin backend pages** (`/indexingPageManager/indexes`, `/sections`,
  `/templates`, `/settings`) render inside the OJS backend chrome, and the
  sidebar link appears for Managers/Site Admins.
- The **frontend page** (`/about/databases` by default) renders, including
  after changing the slug in Settings.
- **Index and section CRUD** (add/edit/delete/toggle/reorder, logo upload)
  all round-trip correctly — these hit `DAORegistry`, `Form`, and file-upload
  code paths that are the most likely to surface any namespace/FQN mistakes.
- The **Navigation Menu item** ("Indexes & Databases page") still appears as
  a selectable item type and resolves to the correct URL.
- `{ipm_blocks}` still renders correctly in a **theme** that calls it.
- A **fresh install** (not just an in-place upgrade) creates the five
  `ipm_*` tables correctly via `getInstallMigration()`.
- Deleting a journal correctly cleans up its `ipm_*` rows and logo files.

If any FQN in a `use` statement is wrong for the exact 3.5.0.x patch version
you're running, PHP will throw a clear `Class "..." not found` fatal
pointing at the offending line — those are quick to fix once you're running
against real core code.

## Addendum (0.3.0) — verified against real 3.4.0.x and 3.5.0.x source, dual-targeted

The section above was written from PKP's release notebooks without access to
actual core code. For 0.3.0, every `use`-imported core class, hook name/arg
signature, and DAO/Form/Handler/Policy method this plugin touches was
diffed against the real `pkp-lib`/`ojs` source on the `stable-3_4_0` and
`stable-3_5_0` git branches. Findings:

- **Everything this plugin uses is identical between 3.4 and 3.5** —
  namespaced plugin classes, `Hook::add()`/`Hook::call()`, the 4-argument
  `LoadHandler` contract, `PKP\controllers\page\PageHandler`,
  `PKP\facades\Locale` (same `@method` list, same underlying
  `LocaleMetadata` class with `getDisplayName()`), `DAO::getInsertId(): int`
  (present as the *default* implementation in both 3.4 and 3.5 — this
  plugin's own driver-aware override, added in 0.2.4, is still worth
  keeping since core's default doesn't handle Postgres's
  `{table}_{column}_seq` sequence naming), `DAO::getLocaleFieldNames()`
  (3.4's parent has no return type; 3.5's is `: array` — the child classes
  already declare `: array`, which is a valid covariant narrowing on both),
  `Form::execute(...$functionArgs)`, all seven `FormValidator*` subclasses'
  constructors, `ContextAccessPolicy`/`PKPSiteAccessPolicy`/`PolicySet`, and
  the `NavigationMenus::itemTypes`/`displaySettings`/`Context::delete` hooks.
  **So contrary to the 0.2.0 migration's assumption, essentially none of the
  3.5-specific porting work was actually 3.5-*only* — it was already
  required as of 3.4.** This plugin needed zero `if (version >= 3.5)`
  branches to dual-target.
- **One genuine, version-independent bug found and fixed:** `use
  PKP\file\PublicFileManager;` in both `IndexingPageManagerPlugin.php` and
  `classes/IpmLogoStore.php` pointed at a class that doesn't exist under
  that namespace on *either* version — only the abstract
  `PKP\file\PKPPublicFileManager` lives there. The concrete class both
  3.4's and 3.5's OJS app repo actually ship is `APP\file\PublicFileManager`
  (`ojs/classes/file/PublicFileManager.php`, `namespace APP\file;`, extends
  `PKP\file\PKPPublicFileManager`). This would have fatally errored on
  first logo upload/delete on any install, at any version — worth
  double-checking if you fork this plugin and add other `PKP\file\*`
  imports; several file-manager classes only exist under `APP\`, not `PKP\`.
- **The one real structural difference found (and it doesn't affect this
  plugin) is the bundled Laravel framework major version**: 3.4 ships
  Laravel 9 (`laravel/framework: ^9.0`), 3.5 ships Laravel 12
  (`laravel/framework: ^12.0`). The `Illuminate\Support\Facades\Schema`/`DB`
  facades and the `Blueprint` methods this plugin's schema migration uses
  (`create`, `hasTable`, `hasColumn`, `table`, `bigInteger`,
  `autoIncrement`, `string`, `longText`, `tinyInteger`, `integer`,
  `dateTime`, `index`, `unique`, `comment`, `after`) are long-stable
  Laravel API and behave identically across that range — but if you add
  *new* schema/DB code to this plugin later, double-check against Laravel's
  actual 9.x docs (not just 12.x) if 3.4 support still matters, since some
  newer Blueprint/query-builder methods (introduced in Laravel 10/11) won't
  exist on a 3.4 install.
- Not independently re-verified: a live install of either version (this
  environment has no OJS runtime, only the git source for static
  comparison, plus `php -l` for syntax). The static checks above cover
  every core symbol this plugin references, but please still smoke-test
  enable → seed → CRUD → public page on a staging 3.4.0.x install before
  shipping, the same way 0.2.0–0.2.7 flagged for 3.5.
