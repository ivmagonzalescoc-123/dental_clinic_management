<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if (!$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    // Free timeslot back to available
    $freeSlot = $conn->prepare(
        "UPDATE timeslot SET status = 'available'
         WHERE timeslot_id IN (SELECT timeslot_id FROM appointments WHERE appointment_id = ?)"
    );
    $freeSlot->bind_param('i', $appointment_id);
    $freeSlot->execute();
    $freeSlot->close();

    // Mark appointment as no_show
    $updA = $conn->prepare("UPDATE appointments SET status = 'no_show' WHERE appointment_id = ?");
    $updA->bind_param('i', $appointment_id);
    $updA->execute();
    $updA->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Patient marked as no-show. Timeslot freed.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
