<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

ob_start();
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';
include __DIR__ . '/availability_guard.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function ($e) {
    if (ob_get_length()) @ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

function send_json($payload, $code = 200) {
    if (ob_get_length()) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary', 'doctor', 'admin'])) {
    send_json(['success' => false, 'message' => 'Forbidden'], 403);
}
if (!capabilities_can('appointment.manage')) {
    send_json(['success' => false, 'message' => 'Access denied: missing appointment management permission.'], 403);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    send_json(['success' => false, 'message' => 'Please refresh the page and try again.'], 403);
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$action         = isset($_POST['action'])         ? trim($_POST['action'])         : 'accept';
$timeslot_id    = isset($_POST['timeslot_id'])    ? (int)$_POST['timeslot_id']    : 0;
$book_start     = isset($_POST['book_start'])     ? trim($_POST['book_start'])     : '';
$book_end       = isset($_POST['book_end'])       ? trim($_POST['book_end'])       : '';
$duration       = isset($_POST['duration'])       ? (int)$_POST['duration']       : 30;
$assign_doctor_id = isset($_POST['doctor_id'])    ? (int)$_POST['doctor_id']      : 0;
$selected_service_ids = isset($_POST['selected_service_ids']) ? $_POST['selected_service_ids'] : [];

if ($appointment_id <= 0) {
    send_json(['success' => false, 'message' => 'Missing appointment_id'], 400);
}

// Load appointment
$a_stmt = $conn->prepare('SELECT appointment_id, user_id, patient_id, appointment_date, status, timeslot_id FROM appointments WHERE appointment_id = ? LIMIT 1');
$a_stmt->bind_param('i', $appointment_id);
$a_stmt->execute();
$appointment = $a_stmt->get_result()->fetch_assoc();
$a_stmt->close();

if (!$appointment) {
    send_json(['success' => false, 'message' => 'Appointment not found'], 404);
}

$currentStatus = strtolower((string)($appointment['status'] ?? 'scheduled'));

if ($role === 'doctor' && (int)$appointment['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
    send_json(['success' => false, 'message' => 'Doctors can only confirm their own appointments.'], 403);
}

// DECLINE
if ($action === 'decline') {
    if (!in_array($currentStatus, ['scheduled', 'confirmed'], true)) {
        send_json(['success' => false, 'message' => 'Cannot decline appointment from status: ' . $currentStatus], 409);
    }
    $reason = trim($_POST['decline_reason'] ?? '');
    if ($reason === '') {
        $reason = 'Declined by ' . ($role === 'doctor' ? 'doctor' : 'secretary');
    }
    appt_ensure_cancellation_reason_column($conn);
    $upd = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE appointment_id = ?");
    $upd->bind_param('si', $reason, $appointment_id);
    $upd->execute();
    $upd->close();

    // Free the timeslot back up — otherwise it stays stuck as "booked" forever
    // and blocks every future patient from booking that time.
    $declinedTimeslotId = (int)($appointment['timeslot_id'] ?? 0);
    if ($declinedTimeslotId > 0) {
        $freeSlot = $conn->prepare("UPDATE timeslot SET status = 'available' WHERE timeslot_id = ? AND status = 'booked'");
        $freeSlot->bind_param('i', $declinedTimeslotId);
        $freeSlot->execute();
        $freeSlot->close();
    }

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'appointment.decline',
            'appointments',
            $appointment_id,
            'Declined appointment request.',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }
    if (function_exists('wf_notify_appointment_cancelled')) {
        wf_notify_appointment_cancelled(
            $conn,
            $appointment_id,
            (int)$appointment['patient_id'],
            (int)$appointment['user_id'],
            (string)$appointment['appointment_date'],
            $reason
        );
    }
    send_json(['success' => true, 'message' => 'Appointment declined']);
}

if (!in_array($currentStatus, ['scheduled', 'confirmed'], true)) {
    send_json(['success' => false, 'message' => 'Cannot confirm appointment from status: ' . $currentStatus], 409);
}

// ACCEPT — set confirmed
$conf = $conn->prepare("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = ?");
$conf->bind_param('i', $appointment_id);
$conf->execute();
$conf->close();

// ── Doctor assignment (from secretary confirmation modal) ──────────────────
if ($assign_doctor_id > 0 && (int)$appointment['user_id'] !== $assign_doctor_id) {
    // Verify the new doctor is valid
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
        $appointment['user_id'] = $assign_doctor_id;
    }
}

// ── Service linking (from secretary confirmation modal) ────────────────────
if (is_array($selected_service_ids) && count($selected_service_ids) > 0) {
    // Clear any existing selected services for this appointment
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

$doc_id = (int)$appointment['user_id'];

// ── PATH A: Timeslot-based booking ──────────────────────────────────────────
if ($timeslot_id > 0) {
    $ts_stmt = $conn->prepare("SELECT timeslot_id, doctor_id, slot_date, start_time, end_time, status FROM timeslot WHERE timeslot_id = ? LIMIT 1");
    $ts_stmt->bind_param('i', $timeslot_id);
    $ts_stmt->execute();
    $ts = $ts_stmt->get_result()->fetch_assoc();
    $ts_stmt->close();

    if (!$ts) {
        send_json(['success' => false, 'message' => 'Timeslot not found'], 404);
    }
    if ($ts['status'] !== 'available') {
        send_json(['success' => false, 'message' => 'Timeslot is no longer available'], 409);
    }

    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $doc_id, $ts['slot_date']);
    if (!$bookable) {
        send_json(['success' => false, 'message' => $bookableMsg], 409);
    }

    $conn->begin_transaction();
    try {
        // Book the timeslot
        $bts = $conn->prepare("UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'");
        $bts->bind_param('i', $timeslot_id);
        $bts->execute();
        if ($bts->affected_rows === 0) throw new Exception('Timeslot was just taken. Please pick another slot.');
        $bts->close();

        // Link timeslot to appointment
        $uat = $conn->prepare("UPDATE appointments SET timeslot_id = ?, appointment_date = ? WHERE appointment_id = ?");
        $uat->bind_param('isi', $timeslot_id, $ts['slot_date'], $appointment_id);
        $uat->execute();
        $uat->close();
        // Create schedules row from timeslot times
        $schStart = $ts['slot_date'] . ' ' . $ts['start_time'];
        $schEnd   = $ts['slot_date'] . ' ' . $ts['end_time'];
        $ins_s = $conn->prepare("INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, 'scheduled')");
        $ins_s->bind_param('iiss', $appointment_id, $doc_id, $schStart, $schEnd);
        $ins_s->execute();
        $schedule_id = $conn->insert_id;
        $ins_s->close();

        reminder_queue_default_pack($conn, $schedule_id, $schStart, $appointment_id);

        $conn->commit();
        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.confirm',
                'appointments',
                $appointment_id,
                'Confirmed with timeslot_id=' . $timeslot_id . ', schedule_id=' . $schedule_id,
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
        }
        send_json([
            'success'     => true,
            'message'     => 'Appointment confirmed and timeslot booked',
            'schedule_id' => $schedule_id,
            'timeslot_id' => $timeslot_id,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ── SECRETARY FAST CONFIRM (no timeslot given yet) ──────────────────────────
if ($role === 'secretary' && empty($book_start)) {
    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'appointment.confirm',
            'appointments',
            $appointment_id,
            'Confirmed without schedule assignment yet.',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }
    send_json(['success' => true, 'message' => 'Appointment confirmed. Assign a timeslot to complete scheduling.']);
}

// ── PATH B: Legacy datetime booking ─────────────────────────────────────────
if (!empty($book_start)) {
    if (empty($book_end)) {
        $book_end = (new DateTime($book_start))->modify("+{$duration} minutes")->format('Y-m-d H:i:s');
    }
    $ins = $conn->prepare("INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, 'scheduled')");
    $ins->bind_param('iiss', $appointment_id, $doc_id, $book_start, $book_end);
    if (!$ins->execute()) {
        error_log('confirm_appointment.php: schedule insert failed: ' . $ins->error);
        send_json(['success' => false, 'message' => 'Failed to create schedule. Please try again.'], 500);
    }
    $schedule_id = $conn->insert_id;
    $ins->close();

    reminder_queue_default_pack($conn, $schedule_id, $book_start, $appointment_id);

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'appointment.confirm',
            'appointments',
            $appointment_id,
            'Confirmed and legacy schedule created with schedule_id=' . $schedule_id,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    send_json(['success' => true, 'message' => 'Appointment confirmed and scheduled', 'schedule_id' => $schedule_id]);
}

// ── AUTO SLOT ────────────────────────────────────────────────────────────────
    $avail_stmt = $conn->prepare('SELECT weekday, start_time, end_time FROM doctor_availability WHERE user_id = ?');
$avail_stmt->bind_param('i', $doc_id);
$avail_stmt->execute();
$availability = [];
while ($r = $avail_stmt->get_result()->fetch_assoc()) {
    $availability[(int)$r['weekday']][] = $r;
}
$avail_stmt->close();

if (!empty($availability)) {
    $found = null;
    $now   = new DateTimeImmutable('now');
    for ($d = 0; $d <= 14 && !$found; $d++) {
        $day     = $now->modify("+{$d} days");
        $weekday = (int)$day->format('w');
        if (empty($availability[$weekday])) continue;
        foreach ($availability[$weekday] as $block) {
            $cursor = DateTime::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d') . ' ' . $block['start_time']);
            $endDt  = DateTime::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d') . ' ' . $block['end_time']);
            if (!$cursor || !$endDt) continue;
            while ($cursor->getTimestamp() + $duration * 60 <= $endDt->getTimestamp()) {
                $cs = $cursor->format('Y-m-d H:i:s');
                $ce = $cursor->modify("+{$duration} minutes")->format('Y-m-d H:i:s');
                [$bookable] = appt_assert_doctor_bookable($conn, $doc_id, $cursor->format('Y-m-d'));
                if (!$bookable) {
                    $cursor = $cursor->modify('+10 minutes');
                    continue;
                }
                $ov = $conn->prepare("SELECT COUNT(*) AS cnt FROM patient_schedules WHERE user_id = ? AND status IN ('scheduled','rescheduled') AND NOT (scheduled_end <= ? OR scheduled_start >= ?)");
                $ov->bind_param('iss', $doc_id, $cs, $ce);
                $ov->execute();
                $cnt = (int)($ov->get_result()->fetch_assoc()['cnt'] ?? 0);
                $ov->close();
                if ($cnt === 0) { $found = ['start' => $cs, 'end' => $ce]; break 2; }
                $cursor = $cursor->modify('+10 minutes');
            }
        }
    }

    if ($found) {
        $ins2 = $conn->prepare("INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, 'scheduled')");
        $ins2->bind_param('iiss', $appointment_id, $doc_id, $found['start'], $found['end']);
        $ins2->execute();
        $schedule_id = $conn->insert_id;
        $ins2->close();

        reminder_queue_default_pack($conn, $schedule_id, $found['start'], $appointment_id);

        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.confirm',
                'appointments',
                $appointment_id,
                'Confirmed and auto-scheduled with schedule_id=' . $schedule_id,
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
        }

        send_json(['success' => true, 'message' => 'Appointment confirmed and earliest slot scheduled', 'schedule_id' => $schedule_id]);
    }
}

// ── Send confirmation notification ────────────────────────────────────────
if (function_exists('wf_notify_appointment_approved')) {
    wf_notify_appointment_approved(
        $conn,
        $appointment_id,
        (int)$appointment['patient_id'],
        $doc_id,
        (string)$appointment['appointment_date']
    );
}

send_json(['success' => true, 'message' => 'Appointment confirmed. Please assign a timeslot to complete scheduling.']);

