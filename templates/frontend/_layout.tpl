{**
 * templates/frontend/_layout.tpl
 *
 * Indexing Page Manager — shared frontend wrapper. Theme-agnostic: page LAYOUT
 * follows the active theme, the CONTENT (grid/cards) is ours.
 *
 * Page title: themes are inconsistent here — some (e.g. Atlas, in compact
 * masthead mode) render a VISIBLE page title from `pageTitleTranslated`, while
 * most (incl. the default OJS theme) render only a screen-reader heading and
 * expect the page body to print its own <h1>. So we ALWAYS print our own
 * visible <h1> (so the title shows on every theme), keep passing
 * `pageTitleTranslated` (correct <title> tag), and then a small script HIDES
 * our <h1> if the theme already rendered a matching visible heading — giving
 * exactly one title on every theme.
 *
 * We impose NO background, card, font or colour; width is read from the theme.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$ipmPageTitle}

{* We render the OJS-canonical page wrapper (`page page_*`) — exactly what core
   page templates (about/contact) and themes like JournalPlus output — so the
   active theme treats this like one of its OWN content pages (page padding,
   `.page h1` rules, etc.) WITHOUT us coupling to any single theme. Our `.ipm-*`
   classes ride alongside for the grid/cards we own. *}
<div class="page page_databases ipm-page ipm-page-{$ipmTemplate|escape}">

    {* A plain, semantic page heading — no opinionated styling and no
       theme-specific classes (we are a plugin, not a theme). Sitting inside the
       theme's main content area + the `.page` wrapper, the active theme's own
       content <h1> CSS styles it (font, weight, colour, size, margins); the
       .ipm-page-title class is only a hook for the de-dupe script below. *}
    <h1 class="ipm-page-title">{$ipmPageTitle|escape}</h1>

    {if $ipmIntroText && $ipmIntroText|trim != $ipmPageTitle|trim}
        <p class="ipm-page-intro">{$ipmIntroText|escape}</p>
    {/if}

    {if $ipmEnableSchemaOrg && $ipmSchemaJsonLd}
        {* JSON_HEX_* already encodes `<`; replace any literal `</script` once
           more as defence-in-depth. JSON parsers are unaffected. *}
        <script type="application/ld+json">{$ipmSchemaJsonLd|replace:'</script':'<\/script' nofilter}</script>
    {/if}

    {block name="ipmContent"}{/block}

</div>

<script>{literal}
(function () {
    'use strict';

    /* (1) De-duplicate the page title: if the active theme already rendered a
       visible heading with the same text (e.g. Atlas' masthead nameplate),
       hide ours so only one title shows. */
    function isVisible(el) {
        // Skip screen-reader / clipped / hidden headings (e.g. the core
        // header's <h1 class="pkp_screen_reader"> which also holds the page
        // title): only a genuinely visible theme heading should suppress ours.
        if (getComputedStyle(el).visibility === 'hidden') return false;
        var r = el.getBoundingClientRect();
        return r.width > 4 && r.height > 4;
    }
    function dedupeTitle() {
        var mine = document.querySelector('.ipm-page-title');
        if (!mine) return;
        var norm = function (s) { return (s || '').replace(/\s+/g, ' ').trim(); };
        var t = norm(mine.textContent);
        if (!t) return;
        var heads = document.querySelectorAll('h1');
        for (var i = 0; i < heads.length; i++) {
            var h = heads[i];
            if (h === mine) continue;
            if (h.closest && h.closest('.ipm-page')) continue;   // ignore our own subtree
            if (norm(h.textContent) === t && isVisible(h)) { mine.style.display = 'none'; return; }
        }
        mine.style.display = '';   // no visible theme title found → ensure ours shows
    }

    /* (2) Match the active theme's content-column width (page LAYOUT follows
       the theme); the grid/cards inside are ours. */
    function vw() { return document.documentElement.clientWidth || window.innerWidth || 0; }
    function applyWidth() {
        var page = document.querySelector('.ipm-page');
        if (!page) return;
        var v = vw(); if (!v) return;
        var nodes = document.querySelectorAll(
            'header, footer, .pkp_structure_head, .pkp_structure_footer,' +
            '[class*="masthead"], [class*="header"], [class*="footer"],' +
            '[class*="container"], [class*="wrap"], [class*="inner"]'
        );
        var best = 0;
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.closest && el.closest('.ipm-page')) continue;
            var w = el.getBoundingClientRect().width;
            if (w >= 480 && w <= v - 8 && w > best) best = w;
        }
        if (best) page.style.setProperty('--ipm-max-width', Math.round(best) + 'px');
    }

    function run() { dedupeTitle(); applyWidth(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
    var t;
    window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(applyWidth, 150); });
})();
{/literal}</script>

{include file="frontend/components/footer.tpl"}
