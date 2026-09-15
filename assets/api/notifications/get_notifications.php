<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$patient_id = isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : 0;
$isPatient = (strtolower((string)($_SESSION['role'] ?? '')) === 'patient');

if ($user_id <= 0 && $patient_id <= 0) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

// Guard: ensure notifications table exists
$rows = [];
$unread = 0;
try {
    if ($isPatient) {
        // This endpoint is polled every 30s site-wide — table creation is owned by
        // the write path (config/reminders.php) instead of re-running DDL on every
        // poll. If the table doesn't exist yet, table_exists() below returns false
        // and notifications simply stay empty until a reminder creates the table.
        if (function_exists('table_exists') && table_exists($conn, 'patient_notifications')) {
            $stmt = $conn->prepare('SELECT patient_notification_id AS notification_id, title, body, link, is_read, created_at FROM patient_notifications WHERE patient_id = ? ORDER BY created_at DESC LIMIT 50');
            if ($stmt) {
                $stmt->bind_param('i', $patient_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $rows[] = $r;
                $stmt->close();
            }

            $uc = $conn->prepare('SELECT COUNT(*) AS cnt FROM patient_notifications WHERE patient_id = ? AND is_read = 0');
            if ($uc) {
                $uc->bind_param('i', $patient_id);
                $uc->execute();
                $ucr = $uc->get_result()->fetch_assoc();
                $unread = (int)($ucr['cnt'] ?? 0);
                $uc->close();
            }
        }
    } elseif (function_exists('table_exists') && table_exists($conn, 'notifications')) {
        $stmt = $conn->prepare('SELECT notification_id, title, body, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            $stmt->close();
        }

        $uc = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0');
        if ($uc) {
            $uc->bind_param('i', $user_id);
            $uc->execute();
            $ucr = $uc->get_result()->fetch_assoc();
            $unread = (int)($ucr['cnt'] ?? 0);
            $uc->close();
        }
    } else {
        error_log('get_notifications: notifications table missing for role context');
    }
} catch (Throwable $e) {
    error_log('get_notifications error: ' . $e->getMessage());
}

// Format timestamps as ISO 8601 with timezone offset
foreach ($rows as &$row) {
    if (!empty($row['created_at'])) {
        $row['created_at'] = date('c', strtotime($row['created_at']));
    }
}
unset($row);

echo json_encode(['success'=>true,'notifications'=>$rows,'unread'=>$unread]);

?>
