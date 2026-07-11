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
$assign_doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
$selected_service_ids = isset($_POST['selected_service_ids']) ? $_POST['selected_service_ids'] : [];
$move_timeslot_id = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;

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

    // ── Doctor assignment ──────────────────────────────────────────────────
    $finalDoctorId = (int)($appt['doctor_id'] ?? 0);
    if ($assign_doctor_id > 0 && $assign_doctor_id !== $finalDoctorId) {
        $ndoc = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role_id = 2 AND is_active = 1 LIMIT 1");
        $ndoc->bind_param('i', $assign_doctor_id);
        $ndoc->execute();
        $ndocRes = $ndoc->get_result()->fetch_assoc();
        $ndoc->close();
        if ($ndocRes) {
            $updDoc = $conn->prepare("UPDATE appointments SET user_id = ? WHERE appointment_id = ?");
            $updDoc->bind_param('ii', $assign_doctor_id, $appointment_id);
            $updDoc->execute();
            $updDoc->close();
            $finalDoctorId = $assign_doctor_id;
        }
    }

    // ── Services linking ───────────────────────────────────────────────────
    if (is_array($selected_service_ids) && count($selected_service_ids) > 0) {
        $conn->query("DELETE FROM selected_services WHERE appointment_id = " . (int)$appointment_id);
        $ssStmt = $conn->prepare("INSERT INTO selected_services (appointment_id, service_id) VALUES (?, ?)");
        if ($ssStmt) {
            foreach ($selected_service_ids as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) {
                    $ssStmt->bind_param('ii', $appointment_id, $sid);
                    $ssStmt->execute();
                }
            }
            $ssStmt->close();
        }
    }

    // ── Timeslot move ──────────────────────────────────────────────────────
    $finalTimeslotId = (int)($appt['timeslot_id'] ?? 0);
    $newTs = null;
    if ($move_timeslot_id > 0 && $move_timeslot_id !== $finalTimeslotId) {
        $tsCheck = $conn->prepare("SELECT timeslot_id, status, doctor_id, slot_date, start_time, end_time FROM timeslot WHERE timeslot_id = ? LIMIT 1");
        $tsCheck->bind_param('i', $move_timeslot_id);
        $tsCheck->execute();
        $newTs = $tsCheck->get_result()->fetch_assoc();
        $tsCheck->close();

        if ($newTs && $newTs['status'] === 'available') {
            if ($finalTimeslotId > 0) {
                $freeOld = $conn->prepare("UPDATE timeslot SET status = 'available' WHERE timeslot_id = ? AND status = 'booked'");
                $freeOld->bind_param('i', $finalTimeslotId);
                $freeOld->execute();
                $freeOld->close();
            }
            $bookNew = $conn->prepare("UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'");
            $bookNew->bind_param('i', $move_timeslot_id);
            $bookNew->execute();
            if ($bookNew->affected_rows === 0) {
                $bookNew->close();
                throw new Exception('Selected time slot was just taken.');
            }
            $bookNew->close();

            $updTs = $conn->prepare("UPDATE appointments SET timeslot_id = ?, appointment_date = ? WHERE appointment_id = ?");
            $updTs->bind_param('isi', $move_timeslot_id, $newTs['slot_date'], $appointment_id);
            $updTs->execute();
            $updTs->close();

            $conn->query("DELETE FROM patient_schedules WHERE appointment_id = " . (int)$appointment_id);
            $schStart = $newTs['slot_date'] . ' ' . $newTs['start_time'];
            $schEnd = $newTs['slot_date'] . ' ' . $newTs['end_time'];
            $insSch = $conn->prepare("INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, 'scheduled')");
            $insSch->bind_param('iiss', $appointment_id, $finalDoctorId, $schStart, $schEnd);
            $insSch->execute();
            $insSch->close();

            $finalTimeslotId = $move_timeslot_id;
        }
    }

    // Determine if arriving late (15-min grace period)
    $slotDate = $appt['slot_date'];
    $slotStart = $appt['start_time'];
    if ($newTs) {
        $slotDate = $newTs['slot_date'];
        $slotStart = $newTs['start_time'];
    }
    $expectedTs = strtotime($slotDate . ' ' . $slotStart);
    $isLate = ($expectedTs && (time() - $expectedTs) > 900);
    $newStatus = $isLate ? 'late' : 'arrived';

    // Mark checked_in
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
        $finalDoctorId
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
