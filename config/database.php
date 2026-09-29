<?php
ob_start();

require_once __DIR__ . '/env.php';

if (!function_exists('load_env')) {
    die('Connection Error: Environment loader is unavailable.');
}

// Ensure .env is loaded (no-op if already loaded by env.php)
load_env();

require_once __DIR__ . '/security_headers.php';

if (!defined('DB_HOST')) define('DB_HOST', (string)env('DB_HOST', 'localhost'));
if (!defined('DB_USER')) define('DB_USER', (string)env('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', (string)env('DB_PASS', ''));
if (!defined('DB_NAME')) define('DB_NAME', (string)env('DB_NAME', 'capstone_dental'));
if (!defined('DB_PORT')) define('DB_PORT', (int)env('DB_PORT', 3306));

// Create connection using .env credentials
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

// Check connection
if ($conn->connect_error) {
    $dbErr = $conn->connect_error;
    error_log("Database Connection Failed: " . $dbErr);
    // Return JSON if this is an AJAX/fetch request, plain text otherwise
    $isAjax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (!empty($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] === 'cors')
    );
    if ($isAjax) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database connection failed. Check DB environment variables.', 'db_error' => $dbErr]));
    }
    die("<h2>Database Connection Error</h2><p>Unable to connect. Check environment variables.<br><code>" . htmlspecialchars($dbErr) . "</code></p>");
}

// Set charset to utf8mb4
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error loading character set utf8mb4: " . $conn->error);
}

// Set timezone from environment (default: Asia/Manila)
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Manila'));

// Set MySQL session timezone to match PHP timezone so current_timestamp() stores local time
$tzOffset = date('P');
$conn->query("SET time_zone = '" . $conn->real_escape_string($tzOffset) . "'");

// Disable mysqli throwing exceptions by default here (we will use explicit checks)
if (function_exists('mysqli_report')) {
    // Turn off automatic reporting to avoid uncaught mysqli_sql_exception
    mysqli_report(MYSQLI_REPORT_OFF);
}

// Helper: check if a table exists in the current database
if (!function_exists('table_exists')) {
    function table_exists($conn, $tableName) {
        if (!($conn instanceof mysqli)) return false;
        try {
            $tn = $conn->real_escape_string($tableName);
            $sql = "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $tn . "'";
            $res = $conn->query($sql);
            if (!$res) return false;
            $row = $res->fetch_assoc();
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            error_log('table_exists check failed: ' . $e->getMessage());
            return false;
        }
    }
}

// Auto-cron: trigger reminders in development mode (every 5 minutes)
if (PHP_SAPI !== 'cli' && env('APP_ENV', 'development') === 'development') {
    include_once __DIR__ . '/reminders.php';
    if (function_exists('reminder_auto_cron')) {
        reminder_auto_cron($conn);
    }
}

ob_end_clean();
