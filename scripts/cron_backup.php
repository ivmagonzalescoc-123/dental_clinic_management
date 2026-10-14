<?php
/**
 * Database Backup Script
 * Trigger via external cron (e.g., cron-job.org)
 * URL: https://yourdomain.com/scripts/cron_backup.php?key=YOUR_CRON_SECRET_KEY
 *
 *也可 via admin UI: admin.php?page=backups&action=run
 */

header('Content-Type: application/json');
@set_time_limit(300);

require_once __DIR__ . '/../config/env.php';
load_env();

$secretKey = trim((string)env('CRON_SECRET_KEY', ''));
$providedKey = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));

if ($secretKey === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'CRON_SECRET_KEY not configured in .env']);
    exit;
}

if ($providedKey === '' || !hash_equals($secretKey, $providedKey)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid key']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/system_logs.php';
require_once __DIR__ . '/../config/backup_helpers.php';

$result = db_backup_run($conn, null);
if (!$result['success']) {
    http_response_code(500);
}
echo json_encode($result);
