<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$patient_id = isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : 0;
$isPatient = (strtolower((string)($_SESSION['role'] ?? '')) === 'patient');
$nid = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
if ($user_id <= 0 && $patient_id <= 0) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

if ($nid <= 0) {
    echo json_encode(['success'=>false,'message'=>'Missing notification_id']);
    exit;
}

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

        if (!function_exists('table_exists') || !table_exists($conn, 'patient_notifications')) {
            error_log('mark_read: patient_notifications table missing');
            echo json_encode(['success'=>false,'message'=>'Notifications not available']);
            exit;
        }

        $stmt = $conn->prepare('UPDATE patient_notifications SET is_read = 1 WHERE patient_notification_id = ? AND patient_id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $nid, $patient_id);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        if (!function_exists('table_exists') || !table_exists($conn, 'notifications')) {
            error_log('mark_read: notifications table missing');
            echo json_encode(['success'=>false,'message'=>'Notifications not available']);
            exit;
        }

        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $nid, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    error_log('mark_read error: ' . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Internal error']);
}

?>
