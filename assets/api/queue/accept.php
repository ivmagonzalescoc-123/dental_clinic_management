<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
check_session(['doctor']);
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$doctor_id = $_SESSION['user_id'] ?? 0;
$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if (!$doctor_id || !$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn->begin_transaction();

    // If appointment is assigned to someone else, claim it for this doctor
    $claimStmt = $conn->prepare('UPDATE appointments SET user_id = ? WHERE appointment_id = ?');
    if (!$claimStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $claimStmt->bind_param('ii', $doctor_id, $appointment_id);
    $claimStmt->execute();
    $claimStmt->close();

    // Mark queue entry as in_progress and record served_time
    $queueStatus = 'in_progress';
    $stmt = $conn->prepare('UPDATE check_in_queue SET status = ?, served_time = NOW() WHERE appointment_id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('si', $queueStatus, $appointment_id);
    $stmt->execute();
    $stmt->close();

    // Update appointment status
    $apptStatus = 'in_progress';
    $ust = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
    if (!$ust) throw new Exception('Prepare failed: ' . $conn->error);
    $ust->bind_param('si', $apptStatus, $appointment_id);
    $ust->execute();
    $ust->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Patient accepted, visit started']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
