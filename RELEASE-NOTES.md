# Release notes

Human-readable summaries for GitHub Releases. For the full change-by-change
history see [CHANGELOG.md](CHANGELOG.md).

---

## v0.4.9 — 2026-08-29

**The public showcase page now works, and the plugin has a proper home in the admin sidebar.**

This release rolls up everything since v0.4.2. If you are on any 0.4.x before
this, upgrade straight to 0.4.9.

### Highlights

- **Public page fixed.** The showcase page used to return *404 Not Found* on
  every URL, with nothing in the error log. It now renders reliably.
- **Shorter public URL:** `https://your-site/index.php/<journal>/indexes-and-databases`.
  The old addresses (`…/gateway/plugin/ipmShowcase`, `…/ipmShowcase`,
  `…/about/databases`) still resolve, so existing navigation-menu items and
  bookmarks keep working. The "Preview" button, the Settings page and the
  navigation-menu item all point at the new URL.
- **Dedicated sidebar entry.** *Indexing Page Manager* now appears in the
  admin sidebar (for Journal Managers and Site Admins) and opens the full
  management screen. Previously the only entry point was the pop-up from the
  Plugins list.
- **Keyboard accessibility.** The index cards on the public page now show a
  visible focus ring when tabbed to (WCAG 2.4.7). Before, keyboard users had
  no indication of which tile was selected.

### Fixes

- **404 on the public page** — the plugin was lazy-loaded and frequently not
  loaded at all before routing decided the page didn't exist. It is now a
  normal (non-lazy) plugin, and all of its hooks register unconditionally;
  the per-journal "is it enabled?" check happens inside each hook where the
  journal context is actually known.
- **"Installed Plugins" list stuck on *Loading…*** — a panel the plugin
  injected into *Settings → Website* was breaking that page's scripts. The
  embedded panel has been removed; use the sidebar entry or the Plugins-list
  pop-up.
- **"Authorization denied" when opening the management page** — the admin
  handler used the wrong OJS base class, which denied every request that
  wasn't an internal `tasks`/`css` call. Fixed.
- **OJS 3.5 compatibility** — role lookups no longer use the removed
  `UserGroupDAO`; the manage page uses the same authorization policy as
  core settings pages.
- **Performance** — first-run database setup (creating tables, seeding the
  four built-in sections and default settings) is version-stamped, so it runs
  once instead of on every request.

### Docs

- README is now English-only.
- Screenshots moved to `screenshots/` and excluded from release archives.

### Upgrading

1. **Install as the web user, not `root`** — running OJS CLI tools as root
   leaves cache files the web server can't read, which breaks the Plugins
   list and cache clearing:
   ```
   sudo -u www php lib/pkp/tools/installPluginVersion.php plugins/generic/indexingPageManager/version.xml
   sudo -u www php tools/clearCache.php
   ```
   (replace `www` with your PHP-FPM pool user). Uploading the `.tar.gz`
   through *Settings → Website → Plugins → Upload a New Plugin* does the same
   thing safely.
2. Restart PHP-FPM so the opcode cache picks up the new files.
3. No content is touched. Existing indexes, sections, settings and
   navigation-menu items are preserved.

### Requirements

- OJS 3.4.0.x or 3.5.0.x
- PHP 8.0–8.3 (OJS 3.4 needs 8.0+, OJS 3.5 needs 8.2+)
- MySQL / MariaDB
