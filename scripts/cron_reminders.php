<?php
// Web-accessible cron endpoint for processing reminders
// Trigger this via external cron service (e.g., cron-job.org, easycron.com)
// Security: requires CRON_SECRET_KEY from .env

header('Content-Type: application/json');

// Load environment
require_once __DIR__ . '/../config/env.php';
load_env();

// Security check
$secretKey = trim((string)env('CRON_SECRET_KEY', ''));
$providedKey = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));

if ($secretKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'CRON_SECRET_KEY not configured']);
    exit;
}

if ($providedKey === '' || !hash_equals($secretKey, $providedKey)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid cron key']);
    exit;
}

// Rate limit: max once per 5 minutes
$lockFile = __DIR__ . '/../scripts/.cron_lock';
if (is_file($lockFile) && (time() - filemtime($lockFile)) < 300) {
    echo json_encode(['status' => 'skipped', 'message' => 'Ran recently, skipping']);
    exit;
}
@touch($lockFile);

// Run the reminder worker
ob_start();
require __DIR__ . '/../scripts/send_reminders.php';
$output = ob_get_clean();

echo json_encode(['status' => 'ok', 'output' => trim($output)]);
