<?php
/**
 * @file classes/IndexingPageManagerUrlSanitizer.php
 *
 * Indexing Page Manager — URL scheme allow-list.
 *
 * Defends against javascript:, data:, vbscript: and similar XSS-via-href
 * vectors. Every external index URL the plugin emits to a public page MUST be
 * funneled through ::sanitize() before reaching the template — `|escape` alone
 * is NOT enough because HTML-entity-encoding `javascript:alert(1)` still
 * produces a working scheme the browser will execute on click.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

class IndexingPageManagerUrlSanitizer
{
    /** Schemes accepted for hyperlinks rendered into the public page. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Return the URL if it has an acceptable scheme, otherwise null. Empty /
     * whitespace-only inputs return null so callers can conditionally skip
     * rendering the link entirely.
     *
     * Bare domains without scheme ("scopus.com/...") are coerced to https://.
     */
    public static function sanitize($url)
    {
        if ($url === null) return null;
        $trimmed = trim((string) $url);
        if ($trimmed === '') return null;

        // Reject control characters / NUL bytes used in bypass attempts.
        if (preg_match('/[\x00-\x1f\x7f]/', $trimmed)) return null;

        // Protocol-relative //host/path — pin it to https rather than emitting
        // a scheme-less link that inherits whatever the page was loaded over.
        if (strpos($trimmed, '//') === 0) {
            return 'https:' . $trimmed;
        }

        // Bare domain like "scopus.com/foo" → coerce to https://.
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $trimmed)) {
            return 'https://' . ltrim($trimmed, '/');
        }

        // Has a scheme — must be in the allow-list.
        $scheme = strtolower(substr($trimmed, 0, strpos($trimmed, ':')));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        return $trimmed;
    }
}
