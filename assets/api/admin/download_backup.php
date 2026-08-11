<?php
/**
 * Authenticated backup download. The /backups folder itself is blocked from
 * direct web access (see backups/.htaccess and the root .htaccess) — this is
 * the only way a backup file (full DB dump: patient PHI + password hashes)
 * should ever leave the server.
 */
include_once dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';

check_session(['admin']);

$backupId = (int)($_GET['backup_id'] ?? 0);
if ($backupId <= 0) {
    http_response_code(400);
    exit('Invalid backup ID');
}

$stmt = $conn->prepare('SELECT backup_id, file_name, file_path FROM backup WHERE backup_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('i', $backupId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Backup not found');
}

// Resolve strictly inside the backups/ directory — basename() strips any
// "../" a tampered file_path or file_name could otherwise smuggle in.
$backupsDir = realpath(dirname(dirname(dirname(__DIR__))) . '/backups');
$fileName = basename((string)($row['file_name'] ?? ''));
$fullPath = $backupsDir . DIRECTORY_SEPARATOR . $fileName;
$resolvedPath = realpath($fullPath);

if ($fileName === '' || $resolvedPath === false || $backupsDir === false || strpos($resolvedPath, $backupsDir) !== 0 || !is_file($resolvedPath)) {
    http_response_code(404);
    exit('Backup file not found on disk');
}

log_system_event($conn, 'backup.download', 'backup', $backupId, 'Backup file downloaded: ' . $fileName, (int)($_SESSION['user_id'] ?? 0));

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($resolvedPath));
header('X-Content-Type-Options: nosniff');
readfile($resolvedPath);
exit;
