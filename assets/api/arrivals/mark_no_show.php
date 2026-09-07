<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}
if (!capabilities_can('arrivals.manage')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: missing arrival management permission.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if (!$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    $apptStmt = $conn->prepare('SELECT status FROM appointments WHERE appointment_id = ? LIMIT 1');
    if (!$apptStmt) {
        throw new Exception('Unable to validate appointment status.');
    }
    $apptStmt->bind_param('i', $appointment_id);
    $apptStmt->execute();
    $appt = $apptStmt->get_result()->fetch_assoc();
    $apptStmt->close();

    if (!$appt) {
        throw new Exception('Appointment not found.');
    }

    $currentStatus = strtolower((string)($appt['status'] ?? ''));
    if (!in_array($currentStatus, ['confirmed', 'scheduled', 'checked_in'], true)) {
        throw new Exception('Cannot mark no-show from current status: ' . $currentStatus);
    }

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

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'arrival.mark_no_show',
            'appointments',
            $appointment_id,
            'Marked appointment as no_show and freed timeslot.',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    echo json_encode(['success' => true, 'message' => 'Patient marked as no-show. Timeslot freed.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
