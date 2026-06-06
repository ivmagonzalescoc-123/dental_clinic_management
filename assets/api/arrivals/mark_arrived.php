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
include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';

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
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if (!$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    // Load appointment + timeslot info
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.status, a.is_emergency, a.patient_id, a.user_id AS doctor_id,
               t.timeslot_id, t.start_time, t.slot_date
        FROM appointments a
        INNER JOIN timeslot t ON t.timeslot_id = a.timeslot_id
        WHERE a.appointment_id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) throw new Exception('Appointment not found or has no timeslot assigned');

    $currentStatus = strtolower((string)($appt['status'] ?? ''));
    if (!in_array($currentStatus, ['confirmed', 'scheduled', 'checked_in'], true)) {
        throw new Exception('Cannot mark arrived from current status: ' . $currentStatus);
    }

    // Determine if arriving late (15-min grace period)
    $expectedTs = strtotime($appt['slot_date'] . ' ' . $appt['start_time']);
    $isLate = ($expectedTs && (time() - $expectedTs) > 900);
    $newStatus = $isLate ? 'late' : 'arrived';

    // Arrivals/check-in queue is now represented by appointments.status
    $updA = $conn->prepare("UPDATE appointments SET status = 'checked_in' WHERE appointment_id = ?");
    $updA->bind_param('i', $appointment_id);
    $updA->execute();
    $updA->close();

    $conn->commit();

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'arrival.mark_arrived',
            'appointments',
            $appointment_id,
            'Marked checked_in; arrival_status=' . $newStatus,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    wf_notify_patient_arrived(
        $conn,
        $appointment_id,
        (int)($appt['patient_id'] ?? 0),
        (int)($appt['doctor_id'] ?? 0)
    );

    echo json_encode([
        'success' => true,
        'message' => $isLate ? 'Marked late.' : 'Marked arrived.',
        'arrival_status' => $newStatus,
        'is_late' => $isLate,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
