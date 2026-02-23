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
    $stmt = $conn->prepare('UPDATE check_in_queue q INNER JOIN appointments a ON q.appointment_id = a.appointment_id SET q.status = ?, q.called_time = NOW() WHERE q.appointment_id = ? AND a.user_id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $status = 'called';
    $stmt->bind_param('sii', $status, $appointment_id, $doctor_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        echo json_encode(['success' => true, 'message' => 'Patient called']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No matching queue item or permission denied']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
