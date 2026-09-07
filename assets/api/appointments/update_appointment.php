<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
    include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include __DIR__ . '/availability_guard.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';

    if (!in_array($_SESSION['role'] ?? '', ['admin', 'secretary', 'doctor'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    if (!capabilities_can('appointment.manage')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: missing appointment management permission.']);
        exit;
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
        exit;
    }
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $appointment_date = isset($_POST['appointment_date']) ? trim($_POST['appointment_date']) : '';
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : null;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $timeslot_id = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;

    if (empty($appointment_id)) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID is required!']);
        exit;
    }

    $curStmt = $conn->prepare('SELECT appointment_id, patient_id, user_id, appointment_date, reason, is_online_appointment, notes, status, timeslot_id FROM appointments WHERE appointment_id = ? LIMIT 1');
    if (!$curStmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $curStmt->bind_param('i', $appointment_id);
    $curStmt->execute();
    $current = $curStmt->get_result()->fetch_assoc();
    $curStmt->close();

    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
        exit;
    }

    if (($_SESSION['role'] ?? '') === 'doctor' && (int)$current['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Doctors can only update their own appointments.']);
        exit;
    }

    if ($user_id <= 0) {
        $user_id = (int)$current['user_id'];
    }
    if ($appointment_date === '') {
        $appointment_date = (string)$current['appointment_date'];
    }
    if ($reason === '') {
        $reason = (string)$current['reason'];
    }
    if ($notes === null) {
        $notes = (string)$current['notes'];
    }
    if ($is_online_appointment === null) {
        $is_online_appointment = (int)$current['is_online_appointment'];
    }

    $allowedStatus = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'];
    if ($status === '') {
        $status = (string)$current['status'];
    } elseif (!in_array($status, $allowedStatus, true)) {
        $status = (string)$current['status'];
    }

    $currentStatus = (string)($current['status'] ?? 'scheduled');
    $transitionMatrix = [
        'scheduled' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['checked_in', 'cancelled', 'no_show', 'scheduled'],
        'checked_in' => ['in_progress', 'no_show'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];
    if ($status !== $currentStatus && !in_array($status, $transitionMatrix[$currentStatus] ?? [], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment status transition: ' . $currentStatus . ' -> ' . $status . '.']);
        exit;
    }

    if ($timeslot_id <= 0) {
        $timeslot_id = (int)($current['timeslot_id'] ?? 0);
    }

    if (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Doctor is required!']);
        exit;
    }

    if (!appt_validate_appointment_date($appointment_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment date. Use YYYY-MM-DD format.']);
        exit;
    }

    [$reasonOk, $reasonResult] = appt_validate_reason($reason);
    if (!$reasonOk) {
        echo json_encode(['success' => false, 'message' => $reasonResult]);
        exit;
    }
    $reason = $reasonResult;

    [$notesOk, $notesResult] = appt_validate_notes($notes);
    if (!$notesOk) {
        echo json_encode(['success' => false, 'message' => $notesResult]);
        exit;
    }
    $notes = $notesResult;

    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $user_id, $appointment_date);
    if (!$bookable) {
        echo json_encode(['success' => false, 'message' => $bookableMsg]);
        exit;
    }

    appt_ensure_standard_timeslots_for_doctor_date($conn, $user_id, $appointment_date);

    $currentTimeslotId = (int)($current['timeslot_id'] ?? 0);
    $timeslotChanged = ($timeslot_id > 0 && $timeslot_id !== $currentTimeslotId);
    $doctorChanged = ((int)$current['user_id'] !== (int)$user_id);
    $dateChanged = ((string)$current['appointment_date'] !== (string)$appointment_date);

    if (($doctorChanged || $dateChanged) && !$timeslotChanged) {
        echo json_encode(['success' => false, 'message' => 'Please select a new available timeslot for the new date/doctor.']);
        exit;
    }

    if ($timeslot_id > 0) {
        $tsStmt = $conn->prepare('SELECT timeslot_id, doctor_id, slot_date, status FROM timeslot WHERE timeslot_id = ? LIMIT 1');
        if (!$tsStmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $tsStmt->bind_param('i', $timeslot_id);
        $tsStmt->execute();
        $ts = $tsStmt->get_result()->fetch_assoc();
        $tsStmt->close();

        if (!$ts) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot not found.']);
            exit;
        }
        if ((int)$ts['doctor_id'] !== (int)$user_id) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot does not belong to this doctor.']);
            exit;
        }
        if ((string)$ts['slot_date'] !== (string)$appointment_date) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot date does not match appointment date.']);
            exit;
        }

        if ($timeslotChanged && (string)$ts['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot is already taken. Please choose another slot.']);
            exit;
        }
    }

    // Cancelling (or marking no-show) frees the slot even when the timeslot_id
    // itself isn't changing — otherwise the timeslot stays stuck as "booked"
    // forever and blocks every future patient from booking that time.
    $statusBecameTerminal = ($status !== $currentStatus) && in_array($status, ['cancelled', 'no_show'], true);

    $conn->begin_transaction();

    try {
        if (($timeslotChanged || $statusBecameTerminal) && $currentTimeslotId > 0) {
            $freeOld = $conn->prepare("UPDATE timeslot SET status = 'available' WHERE timeslot_id = ? AND status = 'booked'");
            if (!$freeOld) throw new Exception('Prepare free-old failed: ' . $conn->error);
            $freeOld->bind_param('i', $currentTimeslotId);
            $freeOld->execute();
            $freeOld->close();
        }

        if ($timeslotChanged && $timeslot_id > 0 && !$statusBecameTerminal) {
            $bookNew = $conn->prepare("UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'");
            if (!$bookNew) throw new Exception('Prepare book-new failed: ' . $conn->error);
            $bookNew->bind_param('i', $timeslot_id);
            $bookNew->execute();
            if ($bookNew->affected_rows === 0) {
                $bookNew->close();
                throw new Exception('Selected timeslot is already taken. Please choose another slot.');
            }
            $bookNew->close();
        }

        $update_sql = "UPDATE appointments SET user_id = ?, appointment_date = ?, reason = ?, is_online_appointment = ?, notes = ?, status = ?, timeslot_id = ? WHERE appointment_id = ?";
        $update_stmt = $conn->prepare($update_sql);

        if (!$update_stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        if (!$update_stmt->bind_param("ississii", $user_id, $appointment_date, $reason, $is_online_appointment, $notes, $status, $timeslot_id, $appointment_id)) {
            throw new Exception('Bind failed: ' . $update_stmt->error);
        }

        if (!$update_stmt->execute()) {
            throw new Exception('Execute failed: ' . $update_stmt->error);
        }
        $update_stmt->close();

        if (function_exists('appt_ensure_needs_reassignment_column')) {
            appt_ensure_needs_reassignment_column($conn);
            $conn->query('UPDATE appointments SET needs_reassignment = 0 WHERE appointment_id = ' . (int)$appointment_id);
        }

        $conn->commit();

        if (($doctorChanged || $dateChanged) && function_exists('wf_notify_reschedule_decision')) {
            wf_notify_reschedule_decision($conn, $appointment_id, (int)$current['patient_id'], $user_id, true, $appointment_date);
        }

        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.update',
                'appointments',
                $appointment_id,
                'Updated appointment to status=' . $status . ', doctor_id=' . $user_id . ', timeslot_id=' . $timeslot_id,
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
        }
    } catch (Exception $txe) {
        $conn->rollback();
        throw $txe;
    }

    echo json_encode(['success' => true, 'message' => 'Appointment updated successfully!', 'appointment_id' => $appointment_id, 'timeslot_id' => $timeslot_id]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
