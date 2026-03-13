<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';

security_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userLogId = isset($_SESSION['user_log_id']) ? (int)$_SESSION['user_log_id'] : 0;
$patientId = isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : 0;
$patientLogId = isset($_SESSION['patient_log_id']) ? (int)$_SESSION['patient_log_id'] : 0;

if ($userId <= 0 && $patientId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No active session']);
    exit;
}

$_SESSION['last_activity'] = time();

if ($userId > 0 && $userLogId > 0 && function_exists('table_exists') && table_exists($conn, 'user_logs')) {
    $stmt = $conn->prepare('UPDATE user_logs SET last_activity = NOW() WHERE user_log_id = ? AND user_id = ? AND logout_time IS NULL LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $userLogId, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if ($patientId > 0 && $patientLogId > 0 && function_exists('table_exists') && table_exists($conn, 'patient_logs')) {
    $stmt = $conn->prepare('UPDATE patient_logs SET last_activity = NOW() WHERE patient_log_id = ? AND patient_id = ? AND logout_time IS NULL LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $patientLogId, $patientId);
        $stmt->execute();
        $stmt->close();
    }
}

echo json_encode(['success' => true]);
exit;
?>