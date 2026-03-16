<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

ob_start();
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include __DIR__ . '/availability_guard.php';
header('Content-Type: application/json');

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

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$action         = isset($_POST['action'])         ? trim($_POST['action'])         : 'accept';
$timeslot_id    = isset($_POST['timeslot_id'])    ? (int)$_POST['timeslot_id']    : 0;
$book_start     = isset($_POST['book_start'])     ? trim($_POST['book_start'])     : '';
$book_end       = isset($_POST['book_end'])       ? trim($_POST['book_end'])       : '';
$duration       = isset($_POST['duration'])       ? (int)$_POST['duration']       : 30;

if ($appointment_id <= 0) {
    send_json(['success' => false, 'message' => 'Missing appointment_id'], 400);
}

// Load appointment
$a_stmt = $conn->prepare('SELECT appointment_id, user_id, patient_id, appointment_date, status FROM appointments WHERE appointment_id = ? LIMIT 1');
$a_stmt->bind_param('i', $appointment_id);
$a_stmt->execute();
$appointment = $a_stmt->get_result()->fetch_assoc();
$a_stmt->close();

if (!$appointment) {
    send_json(['success' => false, 'message' => 'Appointment not found'], 404);
}

// DECLINE
if ($action === 'decline') {
    $reason = 'declined by ' . ($_SESSION['user_id'] ?? 'system');
    $upd = $conn->prepare("UPDATE appointments SET status = 'cancelled', notes = CONCAT(IFNULL(notes,''), '\n', ?) WHERE appointment_id = ?");
    $upd->bind_param('si', $reason, $appointment_id);
    $upd->execute();
    $upd->close();
    send_json(['success' => true, 'message' => 'Appointment declined']);
}

// ACCEPT — set confirmed
$conf = $conn->prepare("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = ?");
$conf->bind_param('i', $appointment_id);
$conf->execute();
$conf->close();

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

        // Reminder 24 hours before
        $remind_at = (new DateTime($schStart))->modify('-24 hours')->format('Y-m-d H:i:s');
        $payload   = json_encode(['appointment_id' => $appointment_id, 'schedule_id' => $schedule_id, 'patient_schedule_id' => $schedule_id]);
        $ins_r = $conn->prepare("INSERT INTO schedule_reminders (patient_schedule_id, remind_at, channel, payload) VALUES (?, ?, 'email', ?)");
        $ins_r->bind_param('iss', $schedule_id, $remind_at, $payload);
        $ins_r->execute();
        $ins_r->close();

        $conn->commit();
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
        send_json(['success' => false, 'message' => 'Failed to create schedule: ' . $ins->error], 500);
    }
    $schedule_id = $conn->insert_id;
    $ins->close();

    $remind_at = (new DateTime($book_start))->modify('-24 hours')->format('Y-m-d H:i:s');
    $payload   = json_encode(['appointment_id' => $appointment_id, 'schedule_id' => $schedule_id, 'patient_schedule_id' => $schedule_id]);
    $r = $conn->prepare("INSERT INTO schedule_reminders (patient_schedule_id, remind_at, channel, payload) VALUES (?, ?, 'email', ?)");
    $r->bind_param('iss', $schedule_id, $remind_at, $payload);
    $r->execute();
    $r->close();

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

        $remind_at = (new DateTime($found['start']))->modify('-24 hours')->format('Y-m-d H:i:s');
        $payload   = json_encode(['appointment_id' => $appointment_id, 'schedule_id' => $schedule_id, 'patient_schedule_id' => $schedule_id]);
        $r2 = $conn->prepare("INSERT INTO schedule_reminders (patient_schedule_id, remind_at, channel, payload) VALUES (?, ?, 'email', ?)");
        $r2->bind_param('iss', $schedule_id, $remind_at, $payload);
        $r2->execute();
        $r2->close();

        send_json(['success' => true, 'message' => 'Appointment confirmed and earliest slot scheduled', 'schedule_id' => $schedule_id]);
    }
}

send_json(['success' => true, 'message' => 'Appointment confirmed. Please assign a timeslot to complete scheduling.']);

