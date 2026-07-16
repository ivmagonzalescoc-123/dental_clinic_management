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
if (!in_array($role, ['doctor', 'secretary', 'admin'])) {
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

$doctor_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $conn->begin_transaction();

    // Verify patient is checked-in before starting a session.
    $paStmt = $conn->prepare(
        "SELECT a.status AS appointment_status, a.user_id AS assigned_doctor_id
         FROM appointments a
         WHERE a.appointment_id = ?
         LIMIT 1"
    );
    if (!$paStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $paStmt->bind_param('i', $appointment_id);
    $paStmt->execute();
    $paRow = $paStmt->get_result()->fetch_assoc();
    $paStmt->close();

    if (!$paRow) {
        throw new Exception('Appointment not found.');
    }

    $appointmentStatus = $paRow['appointment_status'] ?? '';
    if (!in_array($appointmentStatus, ['checked_in', 'in_progress'], true)) {
        throw new Exception('Patient has not been checked in yet.');
    }

    $assignedDoctorId = isset($paRow['assigned_doctor_id']) ? (int)$paRow['assigned_doctor_id'] : 0;
    if ($role === 'doctor' && $assignedDoctorId > 0 && $assignedDoctorId !== $doctor_id) {
        throw new Exception('Doctors can only start sessions for their own appointments.');
    }

    // Assign doctor and mark appointment in_progress
    $updA = $conn->prepare("UPDATE appointments SET user_id = ?, status = 'in_progress', session_started_at = COALESCE(session_started_at, NOW()) WHERE appointment_id = ?");
    $updA->bind_param('ii', $doctor_id, $appointment_id);
    $updA->execute();
    $updA->close();

    // Find or create a patient_schedules row (for EHR linkage)
    $sStmt = $conn->prepare("SELECT schedule_id FROM patient_schedules WHERE appointment_id = ? LIMIT 1");
    $sStmt->bind_param('i', $appointment_id);
    $sStmt->execute();
    $sRow = $sStmt->get_result()->fetch_assoc();
    $sStmt->close();

    if ($sRow) {
        $schedule_id = (int)$sRow['schedule_id'];
        $updS = $conn->prepare(
            "UPDATE patient_schedules SET status = 'scheduled', user_id = ? WHERE schedule_id = ?"
        );
        $updS->bind_param('ii', $doctor_id, $schedule_id);
        $updS->execute();
        $updS->close();
    } else {
        // Pull times from timeslot via appointments.timeslot_id
        $tsStmt = $conn->prepare("
            SELECT t.slot_date, t.start_time, t.end_time
            FROM timeslot t
            INNER JOIN appointments a ON a.timeslot_id = t.timeslot_id
            WHERE a.appointment_id = ?
            LIMIT 1
        ");
        $tsStmt->bind_param('i', $appointment_id);
        $tsStmt->execute();
        $tsRow = $tsStmt->get_result()->fetch_assoc();
        $tsStmt->close();

        $schStart = $tsRow ? ($tsRow['slot_date'] . ' ' . $tsRow['start_time']) : date('Y-m-d H:i:s');
        $schEnd   = $tsRow ? ($tsRow['slot_date'] . ' ' . $tsRow['end_time'])   : date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $insS = $conn->prepare(
            "INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status)
             VALUES (?, ?, ?, ?, 'scheduled')"
        );
        $insS->bind_param('iiss', $appointment_id, $doctor_id, $schStart, $schEnd);
        $insS->execute();
        $schedule_id = (int)$conn->insert_id;
        $insS->close();
    }

    $conn->commit();

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'arrival.start_session',
            'appointments',
            $appointment_id,
            'Started session with schedule_id=' . $schedule_id,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    echo json_encode([
        'success'        => true,
        'message'        => 'Session started',
        'schedule_id'    => $schedule_id,
        'appointment_id' => $appointment_id,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
