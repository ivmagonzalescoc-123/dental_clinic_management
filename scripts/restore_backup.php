<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/session_check.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$adminPassword = $_POST['admin_password'] ?? '';
if ($adminPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Admin password required']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$adminId = (int)($_SESSION['user_id'] ?? 0);
$pwStmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ? AND role_id = 1 LIMIT 1');
$pwStmt->bind_param('i', $adminId);
$pwStmt->execute();
$pwRow = $pwStmt->get_result()->fetch_assoc();
$pwStmt->close();

if (!$pwRow || !password_verify($adminPassword, $pwRow['password_hash'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Incorrect admin password']);
    exit;
}

if (empty($_FILES['backup_file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['backup_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload error: ' . $file['error']]);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'sql') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only .sql files are allowed']);
    exit;
}

if ($file['size'] > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File too large (max 50MB)']);
    exit;
}

$content = file_get_contents($file['tmp_name']);
if ($content === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cannot read uploaded file']);
    exit;
}

$conn->begin_transaction();

try {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

    $statements = array_filter(array_map('trim', explode("\n", $content)));
    $merged = [];
    $current = '';
    foreach ($statements as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
        $current .= ' ' . $line;
        if (substr($trimmed, -1) === ';') {
            $merged[] = rtrim($current, '; ');
            $current = '';
        }
    }
    if (trim($current) !== '') $merged[] = rtrim($current, '; ');

    $executed = 0;
    $errors = [];

    foreach ($merged as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;

        if (stripos($stmt, 'SET ') === 0) continue;
        if (stripos($stmt, 'LOCK TABLES') === 0) continue;
        if (stripos($stmt, 'UNLOCK TABLES') === 0) continue;
        if (stripos($stmt, '/*!') === 0) continue;
        if (stripos($stmt, 'CREATE DATABASE') === 0) continue;
        if (stripos($stmt, 'USE ') === 0) continue;

        if (!$conn->query($stmt)) {
            $errors[] = substr($conn->error, 0, 200);
        }
        $executed++;
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->commit();

    $adminId = (int)($_SESSION['user_id'] ?? 0);
    if (function_exists('log_system_event')) {
        log_system_event($conn, 'admin.backup.restore', 'backup', null,
            "Database restored from: {$file['name']} ($executed statements, " . count($errors) . " errors)", $adminId);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Database restored successfully',
        'statements' => $executed,
        'errors' => count($errors),
        'error_details' => array_values(array_unique(array_slice($errors, 0, 10))),
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
}
