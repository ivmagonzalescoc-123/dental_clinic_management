<?php
/**
 * Environment Variables Loader
 * Loads variables from .env file or system environment
 */

if (!function_exists('load_env')) {
    function load_env($path = null) {
        if ($path === null) {
            $path = dirname(dirname(__FILE__)) . '/.env';
        }

        if (!file_exists($path)) {
            // If .env doesn't exist, rely on system environment variables
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = rtrim(trim($value), "\r");

            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            // Set in $_ENV if not already set
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } else {
            $value = getenv($key);
            if ($value === false) {
                return $default;
            }
        }

        // Handle boolean conversions
        if ($value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === '0') {
            return false;
        }

        return $value;
    }
}

// Load .env file on first include
load_env();

require_once __DIR__ . '/app.php';
app_bootstrap();
