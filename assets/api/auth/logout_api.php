<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/system_logs.php';
include '../../../config/security.php';
include_once '../../../config/remember_me.php';
security_start_session();
remember_me_forget_current($conn);

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$currentPatientId = isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : null;
$currentUserLogId = isset($_SESSION['user_log_id']) ? (int)$_SESSION['user_log_id'] : null;
$currentPatientLogId = isset($_SESSION['patient_log_id']) ? (int)$_SESSION['patient_log_id'] : null;

if ($currentUserId !== null && $currentUserLogId !== null && function_exists('table_exists') && table_exists($conn, 'user_logs')) {
    $stmt = $conn->prepare('UPDATE user_logs SET logout_time = NOW() WHERE user_log_id = ? AND user_id = ? AND logout_time IS NULL LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $currentUserLogId, $currentUserId);
        $stmt->execute();
        $stmt->close();
    }
}

if ($currentPatientId !== null && $currentPatientLogId !== null && function_exists('table_exists') && table_exists($conn, 'patient_logs')) {
    $stmt = $conn->prepare('UPDATE patient_logs SET logout_time = NOW() WHERE patient_log_id = ? AND patient_id = ? AND logout_time IS NULL LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $currentPatientLogId, $currentPatientId);
        $stmt->execute();
        $stmt->close();
    }
}

if ($currentUserId !== null) {
    log_system_event($conn, 'auth.logout', 'users', $currentUserId, 'User logout successful', $currentUserId);
} elseif ($currentPatientId !== null) {
    log_system_event($conn, 'auth.logout', 'patients', $currentPatientId, 'Patient logout successful', null);
}

// Clear all session variables
$_SESSION = array();

// Destroy the session
if (session_id() != "") {
    setcookie(session_name(), '', time() - 2592000, '/');
}
session_destroy();

// Return success
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
exit;
?>
