<?php
/**
 * "Create Backup Now" on the admin Backups page. Runs on the admin's own session, on the
 * same address the page was opened from — so it does not depend on APP_URL or on the cron
 * key (scripts/cron_backup.php stays the entry point for the scheduled job).
 */
include_once dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/backup_helpers.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'admin' || (int)($_SESSION['user_id'] ?? 0) <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = security_json_input();
if (!security_validate_csrf_token($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$result = db_backup_run($conn, (int)$_SESSION['user_id']);
// The server's folder layout is of no use to the browser.
unset($result['path']);

if (!$result['success']) {
    http_response_code(500);
}
echo json_encode($result);
