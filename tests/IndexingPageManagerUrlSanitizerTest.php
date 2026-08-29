<?php
/**
 * @file tests/IndexingPageManagerUrlSanitizerTest.php
 *
 * Regression coverage for IndexingPageManagerUrlSanitizer — the scheme
 * allow-list that keeps javascript:/data:/vbscript: (and CRLF/NUL bypass
 * attempts) out of every external link the plugin renders on a public page.
 *
 * The class has no OJS dependencies, so this test file is self-contained:
 *   vendor/bin/phpunit tests/IndexingPageManagerUrlSanitizerTest.php
 * (or via OJS' own PHPUnit runner from the ojs root).
 *
 * Distributed under the GNU GPL v2. For full terms see the file LICENSE.
 */

use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerUrlSanitizer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/IndexingPageManagerUrlSanitizer.php';

class IndexingPageManagerUrlSanitizerTest extends TestCase
{
    /** @dataProvider blockedProvider */
    public function testBlockedSchemesReturnNull($input): void
    {
        $this->assertNull(IndexingPageManagerUrlSanitizer::sanitize($input));
    }

    public static function blockedProvider(): array
    {
        return [
            'javascript'            => ['javascript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
            'javascript padded'     => ['  javascript:alert(1)  '],
            'data uri'              => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript'              => ['vbscript:msgbox(1)'],
            'file'                  => ['file:///etc/passwd'],
            'newline in scheme'     => ["java\nscript:alert(1)"],
            'tab in scheme'         => ["java\tscript:alert(1)"],
            'nul byte mid-string'   => ["https://ok.example/\0/x"],
            'null'                  => [null],
            'empty'                 => [''],
            'whitespace only'       => ["  \t "],
        ];
    }

    /** @dataProvider allowedProvider */
    public function testAllowedInputs($input, $expected): void
    {
        $this->assertSame($expected, IndexingPageManagerUrlSanitizer::sanitize($input));
    }

    public static function allowedProvider(): array
    {
        return [
            'https kept'            => ['https://scopus.com/x', 'https://scopus.com/x'],
            'http kept'             => ['http://doaj.org', 'http://doaj.org'],
            'bare domain -> https'  => ['scopus.com/foo', 'https://scopus.com/foo'],
            'bare domain slash'     => ['/scopus.com', 'https://scopus.com'],
            'protocol-relative pinned to https' => ['//cdn.example/logo.png', 'https://cdn.example/logo.png'],
            'surrounding space trimmed' => ['  https://ok.example  ', 'https://ok.example'],
        ];
    }
}
