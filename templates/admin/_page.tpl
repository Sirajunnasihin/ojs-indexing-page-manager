{**
 * templates/admin/_page.tpl
 *
 * Backend wrapper for Indexing Page Manager admin pages.
 *
 * Extends OJS' standard layouts/backend.tpl so the journal's left-hand
 * sidebar stays visible while the admin works inside the plugin. The plugin's
 * page heading + tab row + body fragment are injected into the `page` block;
 * the surrounding Vue chrome is rendered by OJS.
 *
 * Variables from IndexingPageManagerManageHandler::_renderPage():
 *   $pageTitle, $indexingPageBody, $indexingPageHomeUrl, $csrfToken,
 *   $pluginName, $pluginVersionTag, $requestedOp, $ipmJsBootstrapJson
 *
 * NOTE: inline <style> inside this block is stripped by OJS' backend Vue
 * mount — all styling lives in styles/compiled/admin.css. Inline <script>
 * DOES run, and is also re-executed when a fragment is re-injected after a
 * validation failure.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}

<link rel="stylesheet" href="{$baseUrl}/plugins/generic/indexingPageManager/styles/compiled/admin.css{$pluginVersionTag}">

<div class="ipm-page pkp_op_{$requestedOp|escape|default:'index'}">

    <header class="ipm-pageheading">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
        <h1>{$pageTitle|escape}</h1>
    </header>

    <nav class="ipm-tabs" aria-label="{translate key="plugins.generic.indexingPageManager.admin.tabs.ariaLabel"}">
        <div class="ipm-tabs-inner">
            <a href="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="indexes"}"
               {if $requestedOp == 'indexes' || $requestedOp == 'indexForm'}class="is-active"{/if}>
                {translate key="plugins.generic.indexingPageManager.action.manage"}
            </a>
            <a href="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="sections"}"
               {if $requestedOp == 'sections' || $requestedOp == 'sectionForm'}class="is-active"{/if}>
                {translate key="plugins.generic.indexingPageManager.admin.sections.title"}
            </a>
            <a href="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="templates"}"
               {if $requestedOp == 'templates'}class="is-active"{/if}>
                {translate key="plugins.generic.indexingPageManager.admin.templates.title"}
            </a>
            <a href="{url router=$smarty.const.ROUTE_PAGE page="indexingPageManager" op="settings"}"
               {if $requestedOp == 'settings'}class="is-active"{/if}>
                {translate key="manager.plugins.settings"}
            </a>
        </div>
    </nav>

    <main class="ipm-body" id="ipmBody">
        {$indexingPageBody nofilter}
    </main>

    <footer class="ipm-admin-footer" role="contentinfo">
        <div class="ipm-admin-footer-inner">
            <span>{translate key="plugins.generic.indexingPageManager.admin.footer.developedBy"}</span>
            <a href="https://litpam.com" target="_blank" rel="noopener noreferrer">litpam.com</a>
        </div>
    </footer>

</div>

{* JS bootstrap — window.IPM (config) + window.IPM_I18N (translations) parsed
   from a JSON data island so all admin JS shares one config source. *}
<script type="application/json" id="ipm-bootstrap-json">{$ipmJsBootstrapJson nofilter}</script>
<script>{literal}
    (function () {
        try {
            var raw = document.getElementById('ipm-bootstrap-json').textContent;
            var data = JSON.parse(raw);
            window.IPM      = data.config || {};
            window.IPM_I18N = data.i18n   || {};
        } catch (e) {
            window.IPM      = {};
            window.IPM_I18N = {};
        }
    })();
{/literal}</script>

<script>{literal}
(function () {
    'use strict';

    /* Toast helper. Background + text colour are set INLINE alongside the
       class so the correct colour reaches the user even when the host theme
       injects competing .ipm-toast-success / .ipm-toast-error rules. */
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

    /* Re-run <script> tags inside a freshly-injected HTML fragment — innerHTML
       does NOT execute scripts on its own. */
    function runScripts(container) {
        container.querySelectorAll('script').forEach(function (s) {
            var n = document.createElement('script');
            Array.from(s.attributes).forEach(function (a) { n.setAttribute(a.name, a.value); });
            n.textContent = s.textContent;
            s.parentNode.replaceChild(n, s);
        });
    }

    /* FormData-based submit handler for ALL admin forms. We deliberately use
       this for both file-upload forms (the index logo) AND plain forms so the
       {ok, redirect, message, formHtml} envelope is honoured uniformly. OJS'
       AjaxFormHandler is avoided because it serialises via jQuery $.ajax,
       which DROPS <input type="file"> from the multipart body.
     *
     * opts: { successUrl, successMessage, savingText, formHost }
     */
    window.ipmSubmitWithFiles = function (selector, opts) {
        opts = opts || {};
        var form = document.querySelector(selector);
        if (!form || form.dataset.ipmFileBound === '1') return;
        form.dataset.ipmFileBound = '1';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            var btn = form.querySelector('button[type="submit"]');
            var originalText = btn ? btn.textContent : null;
            if (btn) { btn.disabled = true; btn.textContent = opts.savingText || (window.IPM_I18N && window.IPM_I18N.saving) || 'Saving…'; }

            function reenable() { if (btn) { btn.disabled = false; btn.textContent = originalText; } }

            fetch(form.action, {
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
                    ipmToast(data.message || opts.successMessage || (window.IPM_I18N && window.IPM_I18N.saved) || 'Saved.', 'success');
                    var dest = data.redirect || opts.successUrl;
                    if (dest) {
                        setTimeout(function () { window.location.href = dest; }, 900);
                    } else {
                        reenable();
                    }
                    return;
                }
                // Validation / server error.
                var host = opts.formHost ? document.querySelector(opts.formHost) : null;
                if (data && typeof data.formHtml === 'string' && host) {
                    host.innerHTML = data.formHtml;
                    runScripts(host);
                } else {
                    reenable();
                }
                var msg = (data && data.message) || (window.IPM_I18N && window.IPM_I18N.saveFailed) || 'Save failed.';
                ipmToast(msg, 'error');
            })
            .catch(function () {
                reenable();
                ipmToast((window.IPM_I18N && window.IPM_I18N.networkError) || 'Network error. Please try again.', 'error');
            });
        });
    };

    /* Vanilla locale-tab handler (works regardless of pkp.min.js). */
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

    /* Vanilla navigation for [data-ipm-action="open"] buttons. */
    function homeBase() {
        var home = (window.IPM && window.IPM.homeUrl) || '';
        if (home) {
            return home.replace(/\/+$/, '').replace(/\/[^\/?#]+$/, '');
        }
        var path = window.location.pathname || '';
        var match = path.match(/^(.*\/indexingPageManager)(?:\/[^\/?#]*)?\/?$/);
        if (match) {
            return window.location.protocol + '//' + window.location.host + match[1];
        }
        return '';
    }
    var VERB_TO_OP = {
        manage:'indexes', indexes:'indexes', indexForm:'indexForm',
        sections:'sections', sectionForm:'sectionForm',
        templateSelect:'templates', templates:'templates', settings:'settings'
    };
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ipm-action="open"]');
        if (!btn) return;
        e.preventDefault();
        var verb = btn.getAttribute('data-ipm-verb') || '';
        var op = VERB_TO_OP[verb] || verb;
        if (!op) return;
        var base = homeBase();
        if (!base) { return; }
        var url = base + '/' + encodeURIComponent(op);
        var qs = [];
        for (var k in btn.dataset) {
            if (k.indexOf('ipmExtra') === 0 && k !== 'ipmExtra') {
                var name = k.substr('ipmExtra'.length);
                name = name.charAt(0).toLowerCase() + name.substr(1);
                qs.push(encodeURIComponent(name) + '=' + encodeURIComponent(btn.dataset[k]));
            }
        }
        if (qs.length) url += '?' + qs.join('&');
        window.location.href = url;
    }, false);
})();
{/literal}</script>

<script src="{$baseUrl}/plugins/generic/indexingPageManager/js/admin/indexList.js{$pluginVersionTag}"></script>
<script src="{$baseUrl}/plugins/generic/indexingPageManager/js/admin/indexForm.js{$pluginVersionTag}"></script>

{/block}
