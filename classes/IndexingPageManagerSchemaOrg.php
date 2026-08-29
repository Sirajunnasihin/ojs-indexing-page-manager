<?php
/**
 * @file classes/IndexingPageManagerSchemaOrg.php
 *
 * Indexing Page Manager — Schema.org JSON-LD generator.
 *
 * Emits a single CollectionPage whose mainEntity lists each index as an
 * Organization with name, url and logo. The resulting JSON is embedded in the
 * page by IndexingPageManagerHandler when enableSchemaOrg is on.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

class IndexingPageManagerSchemaOrg
{
    public static function renderForBlocks(array $blocks, $request, $contextId, $pageTitle)
    {
        $orgs = [];
        $seen = [];

        foreach ($blocks as $block) {
            foreach ($block['indexes'] as $index) {
                $key = $index->getId();
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $orgs[] = self::_organizationFor($index, $request, $contextId);
            }
        }

        $payload = [
            '@context'   => 'https://schema.org',
            '@type'      => 'CollectionPage',
            'name'       => (string) $pageTitle,
            'mainEntity' => $orgs,
        ];

        // JSON_HEX_* flags encode <, >, &, ', " as \uXXXX so a malicious index
        // name can't break out of the surrounding
        // <script type="application/ld+json"> tag. Crawlers parse \u-escapes
        // back to text so SEO is unaffected.
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        return $json !== false ? $json : '';
    }

    private static function _organizationFor($index, $request, $contextId)
    {
        $org = [
            '@type' => 'Organization',
            'name'  => strip_tags((string) $index->getName()),
        ];

        $url = IndexingPageManagerUrlSanitizer::sanitize($index->getUrl());
        if ($url) $org['url'] = $url;

        if ($index->getLogoPath()) {
            $logo = IpmLogoStore::buildUrl($request, $contextId, $index->getLogoPath());
            if ($logo) $org['logo'] = $logo;
        }

        $description = strip_tags((string) $index->getDescription());
        if ($description !== '') $org['description'] = $description;

        return $org;
    }
}
