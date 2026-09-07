<?php

declare(strict_types=1);

namespace Versandkostenretter;

/**
 * Asset URL helper: deterministic cache busting via the deployed file's
 * modification time.
 *
 * URL form: /assets/css/main.css?v=1717958400
 *   - mtime changes exactly when the deployed file changes -> URL changes ->
 *     browsers fetch the new version; unchanged files keep the same URL and
 *     stay cached (caching itself is untouched).
 *   - missing/unreadable file -> no version parameter (URL still works).
 *   - no sessions, no cookies, no runtime state.
 */
final class Assets
{
    /** Base path of the document root (set once by the entry point). */
    private static string $docRoot = '';

    /** Optional explicit version override (tests). */
    private static ?string $forcedVersion = null;

    public static function setDocRoot(string $path): void
    {
        self::$docRoot = rtrim($path, '/');
    }

    public static function setForcedVersion(?string $version): void
    {
        self::$forcedVersion = $version;
    }

    /**
     * Versioned public URL for an asset under assets/.
     */
    public static function url(string $assetPath): string
    {
        $version = self::versionFor($assetPath);
        return $assetPath . ($version !== null ? '?v=' . $version : '');
    }

    /** Deterministic version for an asset, or null when unknown. */
    public static function versionFor(string $assetPath): ?string
    {
        if (self::$forcedVersion !== null) {
            return self::$forcedVersion;
        }
        $file = self::docRoot() . $assetPath;
        $mtime = @filemtime($file);
        return $mtime !== false ? (string) $mtime : null;
    }

    private static function docRoot(): string
    {
        if (self::$docRoot !== '') {
            return self::$docRoot;
        }
        // Document root = directory containing index.php (fixed Plesk layout).
        return dirname(__DIR__);
    }
}
