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
        $conn->query("CREATE TABLE IF NOT EXISTS patient_notifications (
            patient_notification_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_patient_notifications_patient (patient_id),
            KEY idx_patient_notifications_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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

echo json_encode(['success'=>true,'notifications'=>$rows,'unread'=>$unread]);

?>
