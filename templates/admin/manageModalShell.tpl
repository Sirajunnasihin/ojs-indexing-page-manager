{**
 * templates/admin/manageModalShell.tpl
 *
 * Indexing Page Manager — self-contained modal shell.
 *
 * This is the ONLY template that pulls in the admin CSS + JS files + the
 * navigation/save bootstrap script — it is used exactly once, for the
 * initial AjaxModal open (see IndexingPageManagerPlugin::getActions() /
 * manage()). Every other admin verb (sections, templates, settings,
 * indexForm, sectionForm, and all the *Save endpoints) is fetched
 * afterwards by this script's own JS and swapped into #ipmModalBody, so
 * those fragments never need to repeat any of this.
 *
 * Deliberately does NOT depend on the plugin's custom
 * /indexingPageManager/* page route (IndexingPageManagerManageHandler) —
 * see the long comment on IndexingPageManagerPlugin::getActions() for why.
 * Every URL here is built from $ipmAssetsBase / the manageUrlTemplate in
 * the JSON bootstrap below, both computed server-side from OJS' own
 * dispatcher, so this works regardless of whether that custom route is
 * reachable on a given install.
 *}
{* .ipm-page carries the plugin's CSS custom properties (--ipm-primary etc.)
   that the rest of admin.css relies on via var(--ipm-*) with no fallback —
   keep it here even though the legacy page-header markup itself isn't.
   data-ipm-instance is unique per render: this shell can now legitimately
   appear twice on the same page at once (the "Manage Indexing Page" modal
   AND the Settings > Website embedded section both use it) — every DOM
   lookup below is scoped through this attribute instead of a fixed id so
   the two copies can't stomp on each other. *}
<div id="ipmModalRoot-{$ipmInstanceId|escape}" data-ipm-instance="{$ipmInstanceId|escape}" class="ipm-page ipm-admin-root">

    <link rel="stylesheet" href="{$ipmAssetsBase|escape}/styles/compiled/admin.css{$pluginVersionTag|escape:'quotes'}">

    <script type="application/json" id="ipm-bootstrap-json">{$ipmJsBootstrapJson nofilter}</script>
    <script>{literal}
    (function () {
        'use strict';

        // ---- config / i18n --------------------------------------------
        try {
            var raw = document.getElementById('ipm-bootstrap-json').textContent;
            var data = JSON.parse(raw);
            window.IPM      = data.config || {};
            window.IPM_I18N = data.i18n   || {};
        } catch (e) {
            window.IPM      = {};
            window.IPM_I18N = {};
        }

        // Build a verb URL from the server-supplied template (which already
        // has the correct current-page/plugin/category query string) —
        // never re-derive OJS' own URL scheme on the client.
        window.ipmManageUrl = function (verb, extra) {
            var tpl = window.IPM.manageUrlTemplate || '';
            var url = tpl.replace('__VERB__', encodeURIComponent(verb));
            if (extra) {
                for (var k in extra) {
                    if (!Object.prototype.hasOwnProperty.call(extra, k)) continue;
                    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
                }
            }
            return url;
        };

        // ---- toast (unchanged from the legacy custom-page admin) -------
        window.ipmToast = function (message, kind) {
            kind = (kind === 'error') ? 'error' : 'success';
            var el = document.createElement('div');
            el.className = 'ipm-toast ipm-toast-' + kind;
            el.textContent = message;
            el.style.backgroundColor = (kind === 'error') ? '#dc2626' : '#16a34a';
            el.style.color = '#ffffff';
            el.style.borderLeft = '4px solid ' + ((kind === 'error') ? '#991b1b' : '#15803d');
            document.body.appendChild(el);
            setTimeout(function () { el.classList.add('is-visible'); }, 10);
            setTimeout(function () {
                el.classList.remove('is-visible');
                setTimeout(function () { el.remove(); }, 250);
            }, 2600);
        };

        // Re-run <script> tags inside a freshly-injected HTML fragment —
        // innerHTML does NOT execute scripts on its own.
        function runScripts(container) {
            container.querySelectorAll('script').forEach(function (s) {
                var n = document.createElement('script');
                Array.from(s.attributes).forEach(function (a) { n.setAttribute(a.name, a.value); });
                n.textContent = s.textContent;
                s.parentNode.replaceChild(n, s);
            });
        }

        function widenDialog() {
            // Best-effort: our admin UI is a full dashboard, not a small
            // settings form, so ask each enclosing jQuery UI dialog (added
            // by AjaxModal) for more room. Safe no-op wherever there isn't
            // one (e.g. the Settings > Website embedded copy, which is
            // never inside a dialog) or jQuery isn't present.
            document.querySelectorAll('[data-ipm-instance]').forEach(function (root) {
                var dialog = root.closest ? root.closest('.ui-dialog') : null;
                if (dialog && window.jQuery) {
                    window.jQuery(dialog).css({ width: 'min(1100px, 96vw)', maxWidth: '96vw' });
                    var content = dialog.querySelector('.ui-dialog-content');
                    if (content) content.style.maxHeight = '78vh';
                }
            });
        }

        // ---- verb-based content loading (replaces full-page navigation) -
        function bodyOf(root) { return root ? root.querySelector('[data-ipm-role="body"]') : null; }
        function anyRoot() { return document.querySelector('[data-ipm-instance]'); }

        // Sub-views (the index/section forms) belong under their parent
        // tab — highlight that tab rather than leaving nothing active.
        var TAB_FOR_VERB = {
            manage: 'manage', indexes: 'manage', indexForm: 'manage',
            sections: 'sections', sectionForm: 'sections',
            templates: 'templates', templateSelect: 'templates',
            settings: 'settings'
        };
        function setActiveTab(verb, root) {
            if (!root) return;
            var tabName = TAB_FOR_VERB[verb] || verb;
            root.querySelectorAll('.ipm-tabs [data-ipm-tab]').forEach(function (a) {
                a.classList.toggle('is-active', a.getAttribute('data-ipm-tab') === tabName);
            });
        }

        // root is optional for backward compatibility (defaults to the
        // first instance on the page) — every internal caller below always
        // passes it explicitly once it has one, so this fallback only
        // matters if something outside this file calls ipmLoadVerb() directly.
        window.ipmLoadVerb = function (verb, extra, root) {
            root = root || anyRoot();
            var host = bodyOf(root);
            if (!host) return;
            setActiveTab(verb, root);
            fetch(window.ipmManageUrl(verb, extra), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (resp) { return resp.text(); })
            .then(function (text) {
                var parsed;
                try { parsed = JSON.parse(text); } catch (e) { parsed = null; }
                if (!parsed || parsed.status === false) {
                    var msg = (parsed && typeof parsed.content === 'string' && parsed.content)
                        || (window.IPM_I18N && window.IPM_I18N.requestFailed) || 'Request failed.';
                    window.ipmToast(msg, 'error');
                    return;
                }
                host.innerHTML = parsed.content || '';
                runScripts(host);
                if (typeof window.ipmAdminInit === 'function') window.ipmAdminInit();
                if (typeof window.ipmIndexFormInit === 'function') window.ipmIndexFormInit();
                host.scrollTop = 0;
            })
            .catch(function () {
                window.ipmToast((window.IPM_I18N && window.IPM_I18N.networkError) || 'Network error. Please try again.', 'error');
            });
        };

        // [data-ipm-action="open"] buttons now swap modal content instead
        // of navigating — everything else about how they're built (verb +
        // data-ipm-extra-* params) is unchanged from the legacy admin.
        var VERB_TO_OP = {
            manage: 'manage', indexes: 'manage', indexForm: 'indexForm',
            sections: 'sections', sectionForm: 'sectionForm',
            templateSelect: 'templates', templates: 'templates', settings: 'settings'
        };
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-ipm-action="open"]');
            if (!btn) return;
            var root = btn.closest('[data-ipm-instance]');
            if (!root) return;
            e.preventDefault();
            var verb = btn.getAttribute('data-ipm-verb') || '';
            var op = VERB_TO_OP[verb] || verb;
            if (!op) return;
            var extra = {};
            for (var k in btn.dataset) {
                if (k.indexOf('ipmExtra') === 0 && k !== 'ipmExtra') {
                    var name = k.substr('ipmExtra'.length);
                    name = name.charAt(0).toLowerCase() + name.substr(1);
                    extra[name] = btn.dataset[k];
                }
            }
            window.ipmLoadVerb(op, extra, root);
        }, false);

        // Locale-tab handler (unchanged behaviour from the legacy admin).
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('.ipm-locale-tab');
            if (!tab) return;
            e.preventDefault();
            var loc = tab.getAttribute('data-locale');
            var scope = tab.closest('.ipm-form-card') || tab.closest('form') || document.body;
            scope.querySelectorAll('.ipm-locale-tab').forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            scope.querySelectorAll('.ipm-locale-pane').forEach(function (p) {
                p.classList.toggle('is-active', p.getAttribute('data-locale') === loc);
            });
        }, false);

        // ---- FormData-based submit handler for ALL admin forms ---------
        // Same rationale as the legacy custom-page admin: OJS' AjaxFormHandler
        // serialises via jQuery $.ajax, which DROPS <input type="file"> from
        // the multipart body, so every admin form (file upload or not) uses
        // this uniform {ok, successVerb, message, formHtml} envelope.
        //
        // opts: { saveVerb, successVerb, successMessage, savingText, formHost }
        window.ipmSubmitWithFiles = function (selector, opts) {
            opts = opts || {};
            // Scope to whichever instance's fragment this call came from —
            // document.currentScript is reliable here because runScripts()
            // re-creates and synchronously re-executes each inline <script>
            // in place, inside that fragment's own root.
            var callerScript = document.currentScript;
            var scopeRoot = callerScript && callerScript.closest ? callerScript.closest('[data-ipm-instance]') : null;
            var form = scopeRoot ? scopeRoot.querySelector(selector) : document.querySelector(selector);
            if (!form || form.dataset.ipmFileBound === '1') return;
            form.dataset.ipmFileBound = '1';

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(form);
                var btn = form.querySelector('button[type="submit"]');
                var originalText = btn ? btn.textContent : null;
                if (btn) { btn.disabled = true; btn.textContent = opts.savingText || (window.IPM_I18N && window.IPM_I18N.saving) || 'Saving…'; }

                function reenable() { if (btn) { btn.disabled = false; btn.textContent = originalText; } }

                fetch(window.ipmManageUrl(opts.saveVerb), {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (resp) {
                    return resp.text().then(function (text) {
                        try { return { ok: resp.ok, data: JSON.parse(text) }; }
                        catch (e) { return { ok: resp.ok, data: null, raw: text }; }
                    });
                })
                .then(function (result) {
                    var data = result.data;
                    if (data && data.ok === true) {
                        window.ipmToast(data.message || opts.successMessage || (window.IPM_I18N && window.IPM_I18N.saved) || 'Saved.', 'success');
                        var verb = data.successVerb || opts.successVerb || 'manage';
                        window.ipmLoadVerb(verb, null, scopeRoot);
                        return;
                    }
                    // Validation / server error.
                    var host = scopeRoot ? bodyOf(scopeRoot)
                        : (opts.formHost ? document.querySelector(opts.formHost) : null);
                    if (data && typeof data.formHtml === 'string' && host) {
                        host.innerHTML = data.formHtml;
                        runScripts(host);
                    } else {
                        reenable();
                    }
                    var msg = (data && data.message) || (window.IPM_I18N && window.IPM_I18N.saveFailed) || 'Save failed.';
                    window.ipmToast(msg, 'error');
                })
                .catch(function () {
                    reenable();
                    window.ipmToast((window.IPM_I18N && window.IPM_I18N.networkError) || 'Network error. Please try again.', 'error');
                });
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', widenDialog);
        } else {
            widenDialog();
        }
    })();
    {/literal}</script>

    <script src="{$ipmAssetsBase|escape}/js/admin/indexList.js{$pluginVersionTag|escape:'quotes'}"></script>
    <script src="{$ipmAssetsBase|escape}/js/admin/indexForm.js{$pluginVersionTag|escape:'quotes'}"></script>

    {* Persistent tab bar — lives OUTSIDE #ipmModalBody so it survives every
       verb swap below. This was missing entirely when the modal admin first
       shipped (0.3.2): the index-list view had a "+ Add Index" button but no
       way at all to reach Sections / Templates / Settings, since those used
       to be reachable only via the legacy page's own <nav class="ipm-tabs">
       (templates/admin/_page.tpl), which the modal never rendered. *}
    <nav class="ipm-tabs" aria-label="{translate key="plugins.generic.indexingPageManager.admin.tabs.ariaLabel"}">
        <div class="ipm-tabs-inner">
            <a href="#" data-ipm-action="open" data-ipm-verb="manage" data-ipm-tab="manage" class="is-active">
                {translate key="plugins.generic.indexingPageManager.action.manage"}
            </a>
            <a href="#" data-ipm-action="open" data-ipm-verb="sections" data-ipm-tab="sections">
                {translate key="plugins.generic.indexingPageManager.admin.sections.title"}
            </a>
            <a href="#" data-ipm-action="open" data-ipm-verb="templates" data-ipm-tab="templates">
                {translate key="plugins.generic.indexingPageManager.admin.templates.title"}
            </a>
            <a href="#" data-ipm-action="open" data-ipm-verb="settings" data-ipm-tab="settings">
                {translate key="manager.plugins.settings"}
            </a>
        </div>
    </nav>

    <div id="ipmModalBody-{$ipmInstanceId|escape}" data-ipm-role="body">
        {$ipmFragmentHtml nofilter}
    </div>

</div>
