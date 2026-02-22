<?php
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
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

    // Mark queue item cancelled
    $stmt = $conn->prepare('UPDATE check_in_queue SET status = ? WHERE appointment_id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $status = 'cancelled';
    $stmt->bind_param('si', $status, $appointment_id);
    $stmt->execute();
    $stmt->close();

    // Update appointment status as cancelled
    $ust = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
    if (!$ust) throw new Exception('Prepare failed: ' . $conn->error);
    $ust->bind_param('si', $status, $appointment_id);
    $ust->execute();
    $ust->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Patient declined and appointment cancelled']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
