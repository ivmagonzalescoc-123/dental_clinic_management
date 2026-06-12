<?php
/**
 * Application URL helpers and production HTTPS bootstrap.
 */

if (!function_exists('app_request_is_https')) {
    function app_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        return false;
    }
}

if (!function_exists('app_is_localhost')) {
    function app_is_localhost(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return PHP_SAPI === 'cli';
        }
        $host = preg_replace('/:\d+$/', '', $host);
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}

if (!function_exists('app_detect_path')) {
    function app_detect_path(): string
    {
        if (PHP_SAPI === 'cli') {
            return rtrim(trim((string)env('APP_PATH', '/capstone_dental')), '/');
        }

        $configuredPath = trim((string)env('APP_PATH', ''));
        if ($configuredPath !== '') {
            return $configuredPath === '/' ? '' : rtrim($configuredPath, '/');
        }

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && preg_match('#^/([^/]+)/#', $script, $matches)) {
            $candidate = '/' . $matches[1];
            $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
            if ($docRoot !== '' && is_file($docRoot . $candidate . '/index.php')) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $configured = rtrim(trim((string)env('APP_URL', '')), '/');
        if ($configured !== '') {
            $cached = $configured;
            return $cached;
        }

        if (PHP_SAPI === 'cli') {
            $path = app_detect_path();
            $cached = 'http://localhost' . ($path !== '' ? $path : '/capstone_dental');
            return $cached;
        }

        $scheme = app_request_is_https() || env('FORCE_HTTPS', false) ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = app_detect_path();
        $cached = $scheme . '://' . $host . $path;

        return $cached;
    }
}

if (!function_exists('app_reset_password_url')) {
    function app_reset_password_url(): string
    {
        $configured = rtrim(trim((string)env('RESET_PASSWORD_URL', '')), '/');
        if ($configured !== '') {
            return $configured;
        }

        return app_base_url() . '/index.php';
    }
}

if (!function_exists('app_bootstrap')) {
    function app_bootstrap(): void
    {
        static $done = false;
        if ($done || PHP_SAPI === 'cli') {
            return;
        }
        $done = true;

        if (!env('FORCE_HTTPS', false) || app_request_is_https() || app_is_localhost()) {
            return;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return;
        }

        $path = app_detect_path();
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: https://' . $host . $path . $requestUri, true, 301);
        exit;
    }
}
