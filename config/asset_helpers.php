<?php
/**
 * Cache busting for local CSS/JS/HTML assets.
 *
 * asset_url('../assets/css/main.css') => '../assets/css/main.css?v=1727600000'
 *
 * The version is the file's modification time, so re-uploading a file (FTP to
 * InfinityFree, etc.) automatically gives it a new URL and browsers drop the old copy.
 * No more hand-editing ?v= strings.
 */

if (!function_exists('asset_version')) {
    /** Version token for a path relative to the project root (e.g. 'assets/js/main.js'). */
    function asset_version(string $rootRelativePath): string
    {
        static $cache = [];
        $rootRelativePath = ltrim(str_replace('\\', '/', $rootRelativePath), '/');
        if (isset($cache[$rootRelativePath])) {
            return $cache[$rootRelativePath];
        }
        $file = dirname(__DIR__) . '/' . $rootRelativePath;
        $mtime = is_file($file) ? @filemtime($file) : false;
        // Fallback keeps the URL stable if the file is missing or stat fails.
        return $cache[$rootRelativePath] = $mtime ? (string)$mtime : '1';
    }
}

if (!function_exists('asset_url')) {
    /**
     * Append ?v=<mtime> to a local asset URL as written in the page
     * ('../assets/..', './assets/..' or 'assets/..'). Output is HTML-escaped.
     */
    function asset_url(string $path): string
    {
        $clean = strtok($path, '?#');
        $rootRelative = preg_replace('#^(\./|\.\./)+#', '', $clean);
        $url = $clean . '?v=' . asset_version($rootRelative);
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('asset_dir_version')) {
    /** Newest mtime among files in a project directory (non-recursive), for bundles like odontogram/. */
    function asset_dir_version(string $rootRelativeDir, array $extensions = ['js', 'css', 'html']): string
    {
        $dir = dirname(__DIR__) . '/' . trim(str_replace('\\', '/', $rootRelativeDir), '/');
        $latest = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) {
                $latest = max($latest, (int)@filemtime($file));
            }
        }
        return $latest > 0 ? (string)$latest : '1';
    }
}
