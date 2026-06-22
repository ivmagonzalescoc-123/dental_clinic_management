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

$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) {
    $mkdirResult = @mkdir($backupDir, 0755, true);
    if (!$mkdirResult) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cannot create backups directory', 'path' => $backupDir, 'error' => error_get_last()]);
        exit;
    }
}

$date = date('Y-m-d_H-i-s');
$fileName = "capstone_dental_backup_{$date}.sql";
$filePath = $backupDir . '/' . $fileName;

$handle = fopen($filePath, 'w');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cannot create backup file', 'path' => $filePath, 'dir_writable' => is_writable($backupDir), 'error' => error_get_last()]);
    exit;
}

fwrite($handle, "-- Capstone Dental Clinic Database Backup\n");
fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "-- Database: " . DB_NAME . "\n\n");
fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
fwrite($handle, "SET time_zone = \"+00:00\";\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

$tablesRes = $conn->query("SHOW TABLES");
if (!$tablesRes) {
    fclose($handle);
    @unlink($filePath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to list tables']);
    exit;
}

$tableNames = [];
while ($row = $tablesRes->fetch_row()) {
    $tableNames[] = $row[0];
}

$rowCount = 0;
foreach ($tableNames as $table) {
    $tableEsc = $conn->real_escape_string($table);

    $createRes = $conn->query("SHOW CREATE TABLE `{$tableEsc}`");
    if ($createRow = $createRes->fetch_row()) {
        fwrite($handle, "DROP TABLE IF EXISTS `{$tableEsc}`;\n");
        fwrite($handle, $createRow[1] . ";\n\n");
    }

    $dataRes = $conn->query("SELECT * FROM `{$tableEsc}`");
    if (!$dataRes || $dataRes->num_rows === 0) continue;

    $colsRes = $conn->query("SHOW COLUMNS FROM `{$tableEsc}`");
    $columns = [];
    while ($colRow = $colsRes->fetch_row()) {
        $columns[] = $colRow[0];
    }

    while ($row = $dataRes->fetch_assoc()) {
        $values = [];
        foreach ($columns as $col) {
            $val = $row[$col];
            if ($val === null) {
                $values[] = 'NULL';
            } else {
                $values[] = "'" . $conn->real_escape_string($val) . "'";
            }
        }
        fwrite($handle, "INSERT INTO `{$tableEsc}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n");
        $rowCount++;
    }
    fwrite($handle, "\n");
}

fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
fwrite($handle, "-- End of backup\n");
fclose($handle);

$fileSize = filesize($filePath);
$fileSizeKB = round($fileSize / 1024, 2);

$conn->query("INSERT INTO backup (user_id, backup_date, file_name, file_path, file_size) VALUES (" .
    "NULL" .
    ", NOW(), '" . $conn->real_escape_string($fileName) .
    "', '" . $conn->real_escape_string('backups/' . $fileName) .
    "', $fileSizeKB)");

$retentionYears = 10;
$cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));
$oldBackups = $conn->query("SELECT file_path FROM backup WHERE backup_date < '$cutoff'");
if ($oldBackups) {
    while ($oldRow = $oldBackups->fetch_assoc()) {
        $oldFile = dirname(__DIR__) . '/' . $oldRow['file_path'];
        if (is_file($oldFile)) @unlink($oldFile);
    }
    $conn->query("DELETE FROM backup WHERE backup_date < '$cutoff'");
}

if (function_exists('log_system_event')) {
    log_system_event($conn, 'cron.backup.create', 'backup', null,
        "Scheduled backup: $fileName ($fileSizeKB KB, $rowCount rows, " . count($tableNames) . " tables)", null);
}

echo json_encode([
    'success' => true,
    'message' => 'Backup completed',
    'file' => $fileName,
    'path' => $filePath,
    'size_kb' => $fileSizeKB,
    'rows' => $rowCount,
    'tables' => count($tableNames),
]);
