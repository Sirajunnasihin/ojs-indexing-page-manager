<?php
/**
 * @file classes/IpmLogoStore.php
 *
 * Indexing Page Manager — logo file helper.
 *
 * Stores index logos under the journal's public files area:
 *
 *   public/journals/{contextId}/indexingPageManager/logos/{index_id}-{hash}.{ext}
 *
 * Resolves to a public URL via the journal public files path. Logos are
 * accessed without auth on the frontend, matching OJS' standard public-files
 * behaviour (journal logos, favicons, etc.). Security: extension allow-list +
 * magic-byte content check + derived filename + a defensive .htaccess that
 * blocks PHP execution (Apache). On nginx/IIS add an equivalent rule denying
 * script execution under this directory.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use APP\file\PublicFileManager;
use PKP\config\Config;

class IpmLogoStore
{
    private const SUBDIR = 'indexingPageManager/logos';

    /** Allow-list of file extensions (lowercase). */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Save a logo straight from a $_FILES[...] entry (the FormData multipart
     * upload path). Moves the upload directly into the journal's public dir.
     *
     * @param int   $contextId
     * @param int   $indexId
     * @param array $upload    $_FILES entry: name, tmp_name, error, size, type
     * @return string|null     Relative path under journal public dir, or null.
     */
    public static function saveFromUpload($contextId, $indexId, $upload)
    {
        if (empty($upload) || !isset($upload['tmp_name'], $upload['name'], $upload['error'])) {
            return null;
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            return null;
        }

        // Magic-bytes content check — defends against `<?php …` files renamed
        // `evil.png`. Reject when the declared extension doesn't match the
        // detected image type.
        $validatedExt = self::_validateImageContent($upload['tmp_name'], $extension);
        if (!$validatedExt) {
            return null;
        }
        $extension = $validatedExt;

        $targetDir = self::_targetDir($contextId);
        if (!$targetDir) {
            return null;
        }
        self::_writeLogoDirHtaccess($targetDir);

        $filename = self::_filename($indexId, $extension);
        $absoluteTarget = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!@move_uploaded_file($upload['tmp_name'], $absoluteTarget)) {
            if (!@copy($upload['tmp_name'], $absoluteTarget)) {
                return null;
            }
        }
        @chmod($absoluteTarget, 0644);

        return self::SUBDIR . '/' . $filename;
    }

    /**
     * Save a logo from an OJS TemporaryFile (legacy upload path). Returns the
     * relative path to persist as Index::logoPath, or null on failure.
     *
     * @param int           $contextId
     * @param int           $indexId
     * @param TemporaryFile $tempFile
     * @return string|null
     */
    public static function saveFromTempFile($contextId, $indexId, $tempFile)
    {
        $originalName = method_exists($tempFile, 'getOriginalFileName')
            ? $tempFile->getOriginalFileName()
            : $tempFile->getOriginalFilename();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        $sourcePath = method_exists($tempFile, 'getFilePath') ? $tempFile->getFilePath() : null;
        if (!$sourcePath || !is_readable($sourcePath)) {
            return null;
        }

        $validatedExt = self::_validateImageContent($sourcePath, $extension);
        if (!$validatedExt) {
            return null;
        }
        $extension = $validatedExt;

        $targetDir = self::_targetDir($contextId);
        if (!$targetDir) {
            return null;
        }
        self::_writeLogoDirHtaccess($targetDir);

        $filename = self::_filename($indexId, $extension);
        $absoluteTarget = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($sourcePath, $absoluteTarget)) {
            return null;
        }
        @chmod($absoluteTarget, 0644);

        return self::SUBDIR . '/' . $filename;
    }

    private static function _targetDir($contextId)
    {
        $publicFileManager = new PublicFileManager();
        $contextDir = $publicFileManager->getContextFilesPath((int) $contextId);
        $targetDir  = $contextDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::SUBDIR);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            return null;
        }
        return is_dir($targetDir) ? $targetDir : null;
    }

    private static function _filename($indexId, $extension)
    {
        return sprintf('index-%d-%s.%s',
            (int) $indexId,
            substr(md5($indexId . microtime(true)), 0, 8),
            $extension);
    }

    /**
     * Validate that the file content matches a supported raster image type by
     * inspecting the actual bytes via getimagesize(). Returns the canonical
     * lowercase extension on success, null on mismatch/failure.
     */
    private static function _validateImageContent($absolutePath, $declaredExtension)
    {
        if (!is_readable($absolutePath)) return null;

        $info = @getimagesize($absolutePath);
        if (!is_array($info) || !isset($info[2])) return null;

        $typeMap = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
        ];
        if (!isset($typeMap[$info[2]])) return null;

        $detected = $typeMap[$info[2]];
        $declared = strtolower((string) $declaredExtension);
        if ($declared === 'jpeg') $declared = 'jpg';

        return ($detected === $declared) ? $detected : null;
    }

    /**
     * Marker bumped whenever the .htaccess template changes — lets us detect
     * & rewrite stale copies on existing installs.
     */
    private const HTACCESS_VERSION = 'ipm-htaccess-v1';

    /**
     * Drop a defensive .htaccess into the logo directory. Blocks PHP execution
     * and forces image MIME types. The mod_headers directives are wrapped in
     * <IfModule mod_headers.c> so installs without that module (default WAMP,
     * some shared hosts) don't 500 on every logo URL.
     */
    private static function _writeLogoDirHtaccess($targetDir)
    {
        $htaccessPath = $targetDir . DIRECTORY_SEPARATOR . '.htaccess';

        if (is_file($htaccessPath)) {
            $existing = @file_get_contents($htaccessPath);
            if ($existing !== false && strpos($existing, self::HTACCESS_VERSION) !== false) {
                return;
            }
        }

        $contents =
            "# Auto-generated by indexingPageManager plugin — do not edit.\n" .
            "# " . self::HTACCESS_VERSION . "\n" .
            "# Block PHP execution in this directory.\n" .
            "<FilesMatch \"\\.(php|phtml|phar|php3|php4|php5|php7|pht|inc)\$\">\n" .
            "  Require all denied\n" .
            "</FilesMatch>\n" .
            "# Force image-only handling so MIME-confusion can't trick browsers.\n" .
            "<IfModule mod_headers.c>\n" .
            "  <FilesMatch \"\\.(jpe?g|png|webp)\$\">\n" .
            "    Header set Content-Disposition \"inline\"\n" .
            "    Header set X-Content-Type-Options \"nosniff\"\n" .
            "  </FilesMatch>\n" .
            "</IfModule>\n";

        if (@file_put_contents($htaccessPath, $contents) === false) {
            // The .htaccess is the only server-side PHP-execution guard on
            // Apache; surface its absence so an operator can fix permissions
            // (or add an equivalent rule) instead of leaving it silently off.
            error_log('[indexingPageManager] could not write logo .htaccess guard at ' . $targetDir);
        }
    }

    /**
     * Build a public, anonymous-accessible URL for a stored logo path.
     *
     * @param Request $request
     * @param int     $contextId
     * @param string  $relativePath  As returned by ::saveFromUpload().
     */
    public static function buildUrl($request, $contextId, $relativePath)
    {
        if (!$relativePath) {
            return null;
        }
        $publicDir = trim((string) Config::getVar('files', 'public_files_dir'), '/');
        if ($publicDir === '') $publicDir = 'public';
        return rtrim($request->getBaseUrl(), '/')
             . '/' . $publicDir
             . '/journals/' . (int) $contextId
             . '/' . ltrim($relativePath, '/');
    }

    /**
     * Remove a stored logo file. Best-effort — swallows errors so a missing
     * file never blocks an index save.
     */
    public static function deleteByPath($contextId, $relativePath)
    {
        if (!$relativePath) return;

        $publicFileManager = new PublicFileManager();
        $absolute = $publicFileManager->getContextFilesPath((int) $contextId)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
