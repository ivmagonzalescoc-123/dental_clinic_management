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
    $stmt = $conn->prepare('UPDATE appointments SET user_id = ? WHERE appointment_id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('ii', $doctor_id, $appointment_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        echo json_encode(['success' => true, 'message' => 'Appointment claimed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes made (maybe already claimed)']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
