<?php
/**
 * @file classes/IndexingPageManagerSmartyHelper.php
 *
 * Indexing Page Manager — Smarty helper registry.
 *
 * Wires plugin-specific filters/functions into the active TemplateManager so
 * the admin, frontend and (optionally) the active host theme can call:
 *
 *   {ipm_logo_url path=$index->getLogoPath() context_id=$ipmContextId}
 *   {$index->getUrl()|ipm_safe_url}
 *   {ipm_blocks assign="ipmBlocks"}      ← theme embed: structured data
 *   {ipm_blocks}                          ← theme embed: ready-made logo strip
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use APP\core\Application;
use PKP\db\DAORegistry;

class IndexingPageManagerSmartyHelper
{
    /** @var bool */
    private static $registered = false;

    public static function register($templateMgr, $plugin)
    {
        if (self::$registered) return;
        self::$registered = true;

        $templateMgr->registerPlugin('modifier', 'ipm_safe_url',  [self::class, 'safeUrl']);
        $templateMgr->registerPlugin('function', 'ipm_logo_url',  [self::class, 'logoUrlFunction']);
        $templateMgr->registerPlugin('function', 'ipm_blocks',    [self::class, 'blocksFunction']);
    }

    /** Test seam — used by tests to reset between assertions. */
    public static function reset()
    {
        self::$registered = false;
    }

    /**
     * Smarty modifier facade for IndexingPageManagerUrlSanitizer::sanitize().
     * Returns the URL when the scheme is acceptable, or null so `{if $u}`
     * guards in templates skip rendering the link.
     */
    public static function safeUrl($url)
    {
        return IndexingPageManagerUrlSanitizer::sanitize($url);
    }

    public static function logoUrlFunction($params)
    {
        $path = isset($params['path']) ? (string) $params['path'] : '';
        $contextId = isset($params['context_id']) ? (int) $params['context_id'] : 0;
        if (!$path) return '';
        $request = Application::get()->getRequest();
        return IpmLogoStore::buildUrl($request, $contextId, $path);
    }

    /**
     * {ipm_blocks} — theme-embed entry point.
     *
     * Optional params:
     *   assign   When set, assigns the structured payload to this Smarty
     *            variable instead of rendering HTML. The payload is:
     *              ['sections' => [['section'=>Section, 'indexes'=>Index[]], …],
     *               'indexes'  => Index[] (flat, deduped, active)]
     *   section  Slug filter — restrict to a single section.
     *   limit    Cap the flat index list to N entries (0 = no cap).
     *   target   When rendering the default strip, the link target attribute
     *            ("_blank" default).
     *
     * With no `assign`, renders a self-contained, lightly-styled logo strip so
     * a theme can drop {ipm_blocks} on its homepage without any extra CSS.
     */
    public static function blocksFunction($params, $smarty)
    {
        $request = Application::get()->getRequest();
        $context = $request ? $request->getContext() : null;
        if (!$context) return '';
        $contextId = (int) $context->getId();

        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        $indexDao   = DAORegistry::getDAO('IpmIndexDAO');
        if (!$sectionDao || !$indexDao) return '';

        $onlySlug = isset($params['section']) ? (string) $params['section'] : null;
        $limit    = isset($params['limit']) ? (int) $params['limit'] : 0;

        $blocks = [];
        $flat   = [];
        $seen   = [];
        foreach ($sectionDao->getByJournalId($contextId, true) as $section) {
            if ($onlySlug && $section->getSlug() !== $onlySlug) continue;
            $indexes = $indexDao->getBySectionId($section->getId(), true);
            if (empty($indexes)) continue;
            $blocks[] = ['section' => $section, 'indexes' => $indexes];
            foreach ($indexes as $idx) {
                if (isset($seen[$idx->getId()])) continue;
                $seen[$idx->getId()] = true;
                $flat[] = $idx;
            }
        }
        if ($limit > 0) {
            $flat = array_slice($flat, 0, $limit);
        }

        $payload = ['sections' => $blocks, 'indexes' => $flat];

        if (!empty($params['assign'])) {
            $smarty->assign((string) $params['assign'], $payload);
            return '';
        }

        $target = isset($params['target']) ? (string) $params['target'] : '_blank';
        return self::_renderStrip($flat, $contextId, $request, $target);
    }

    /**
     * Render a minimal, self-styled horizontal logo strip. Used as the
     * convenience default of {ipm_blocks} when no `assign` is given.
     */
    private static function _renderStrip(array $indexes, $contextId, $request, $target)
    {
        if (empty($indexes)) return '';

        $esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $targetAttr = ($target === '_blank')
            ? ' target="_blank" rel="noopener noreferrer"'
            : '';

        $out  = '<div class="ipm-embed-strip">';
        $out .= '<style>'
              . '.ipm-embed-strip{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:28px;padding:20px 0}'
              . '.ipm-embed-item{display:inline-flex;align-items:center;justify-content:center}'
              . '.ipm-embed-item img{max-height:46px;max-width:150px;width:auto;height:auto;object-fit:contain;'
              . 'filter:grayscale(100%);opacity:.72;transition:filter .2s ease,opacity .2s ease}'
              . '.ipm-embed-item:hover img{filter:grayscale(0);opacity:1}'
              . '</style>';

        foreach ($indexes as $index) {
            $logo = $index->getLogoPath()
                ? IpmLogoStore::buildUrl($request, $contextId, $index->getLogoPath())
                : null;
            if (!$logo) continue;
            $name = $esc($index->getName());
            $url  = IndexingPageManagerUrlSanitizer::sanitize($index->getUrl());
            $img  = '<img src="' . $esc($logo) . '" alt="' . $name . '" loading="lazy">';
            if ($url) {
                $out .= '<a class="ipm-embed-item" href="' . $esc($url) . '"' . $targetAttr
                      . ' title="' . $name . '">' . $img . '</a>';
            } else {
                $out .= '<span class="ipm-embed-item" title="' . $name . '">' . $img . '</span>';
            }
        }
        $out .= '</div>';
        return $out;
    }
}
