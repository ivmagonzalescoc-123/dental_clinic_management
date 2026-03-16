<?php
// CLI helper: invoke an API endpoint with synthetic session + POST payload.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

$endpointRel = $argv[1] ?? '';
$role = $argv[2] ?? 'admin';
$userId = isset($argv[3]) ? (int)$argv[3] : 0;
$postArg = $argv[4] ?? '{}';

if ($endpointRel === '' || $userId <= 0) {
    fwrite(STDERR, "Usage: php scripts/smoke_invoke_endpoint.php <endpoint-rel-path> <role> <user_id> <post-json>\n");
    exit(2);
}

$decodedArg = base64_decode($postArg, true);
$postJson = ($decodedArg !== false) ? $decodedArg : $postArg;
$payload = json_decode($postJson, true);
if (!is_array($payload)) {
    $payload = [];
}

$root = dirname(__DIR__);
$endpointPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $endpointRel);
if (!is_file($endpointPath)) {
    fwrite(STDERR, "Endpoint file not found: {$endpointPath}\n");
    exit(3);
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_FILENAME'] = $endpointPath;
$_SERVER['SCRIPT_NAME'] = '/' . str_replace('\\', '/', $endpointRel);
$_GET = [];
$_POST = $payload;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sid = 'smoke' . substr(hash('sha256', $endpointRel . '|' . $role . '|' . $userId . '|' . microtime(true)), 0, 24);
    session_id($sid);
    session_start();
}

$_SESSION['user_id'] = $userId;
$_SESSION['role'] = $role;
$_SESSION['email'] = 'smoke.runner@example.test';
$_SESSION['first_name'] = 'Smoke';
$_SESSION['last_name'] = 'Runner';
$_SESSION['last_activity'] = time();

include $endpointPath;
