<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Buffer output to prevent accidental HTML output from breaking JSON responses
ob_start();

include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

// Convert PHP errors to exceptions so we can reliably return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline){
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function($e){
    if (ob_get_length()) @ob_end_clean();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
});

// helper to always return clean JSON
function send_json($payload, $code = 200) {
    if (ob_get_length()) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary','doctor','admin'])) {
    http_response_code(403);
    send_json(['success'=>false,'message'=>'Forbidden'], 403);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : 'accept'; // accept|decline
$book_start = isset($_POST['book_start']) ? trim($_POST['book_start']) : ''; // optional direct booking
$book_end = isset($_POST['book_end']) ? trim($_POST['book_end']) : '';
$duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 30;

if ($appointment_id <= 0) {
    send_json(['success'=>false,'message'=>'Missing appointment_id'], 400);
    exit;
}

// Load appointment
$a_stmt = $conn->prepare('SELECT appointment_id, user_id, patient_id, appointment_date, status FROM appointments WHERE appointment_id = ? LIMIT 1');
$a_stmt->bind_param('i', $appointment_id);
$a_stmt->execute();
$a_res = $a_stmt->get_result();
$appointment = $a_res ? $a_res->fetch_assoc() : null;
$a_stmt->close();

if (!$appointment) {
    send_json(['success'=>false,'message'=>'Appointment not found'], 404);
    exit;
}

if ($action === 'decline') {
    $u = $_SESSION['user_id'] ?? null;
    $upd = $conn->prepare("UPDATE appointments SET status = ?, notes = CONCAT(IFNULL(notes,''), '\\n', ?) WHERE appointment_id = ?");
    $reason = 'declined by ' . ($u ?: 'system');
    $upd->bind_param('ssi', $action, $reason, $appointment_id);
    $upd->execute();
    $upd->close();
    send_json(['success'=>true,'message'=>'Appointment declined']);
    exit;
}

// Accept: set appointment status to confirmed
$conf_stmt = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
$status_val = 'confirmed';
$conf_stmt->bind_param('si', $status_val, $appointment_id);
$conf_stmt->execute();
$conf_stmt->close();

// If the requester is a secretary and no explicit booking times provided, confirm without auto-scheduling
if ((isset($_SESSION['role']) && $_SESSION['role'] === 'secretary') && empty($book_start)) {
    send_json(['success' => true, 'message' => 'Appointment confirmed']);
    exit;
}

// If book_start provided, create schedule directly
if (!empty($book_start)) {
    if (empty($book_end)) {
        $book_end = (new DateTime($book_start))->modify("+{$duration} minutes")->format('Y-m-d H:i:s');
    }

    $ins = $conn->prepare('INSERT INTO schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, "scheduled")');
    $doc_id = (int)$appointment['user_id'];
    $ins->bind_param('iiss', $appointment_id, $doc_id, $book_start, $book_end);
    if (!$ins->execute()) {
        send_json(['success'=>false,'message'=>'Failed to create schedule: '.$ins->error], 500);
        exit;
    }
    $schedule_id = $conn->insert_id;
    $ins->close();

    // create default reminder 24 hours before
    $remind_at = (new DateTime($book_start))->modify('-24 hours')->format('Y-m-d H:i:s');
    $payload = json_encode(['appointment_id'=>$appointment_id,'schedule_id'=>$schedule_id]);
    $r = $conn->prepare('INSERT INTO schedule_reminders (schedule_id, remind_at, channel, payload) VALUES (?, ?, "email", ?)');
    $r->bind_param('iss', $schedule_id, $remind_at, $payload);
    $r->execute();
    $r->close();

    send_json(['success'=>true,'message'=>'Appointment confirmed and scheduled','schedule_id'=>$schedule_id]);
    exit;
}

// Otherwise try to find earliest available slot (simple heuristics reusing doctor_availability)
$doc_id = (int)$appointment['user_id'];
$days_ahead = 14;
$found = null;

$avail_stmt = $conn->prepare('SELECT weekday, start_time, end_time FROM doctor_availability WHERE user_id = ?');
$avail_stmt->bind_param('i', $doc_id);
$avail_stmt->execute();
$avail_res = $avail_stmt->get_result();
$availability = [];
while ($r = $avail_res->fetch_assoc()) {
    $availability[(int)$r['weekday']][] = $r;
}
$avail_stmt->close();

$now = new DateTimeImmutable('now');
for ($d=0;$d<=$days_ahead;$d++) {
    $day = $now->modify("+{$d} days");
    $weekday = (int)$day->format('w');
    if (empty($availability[$weekday])) continue;
    foreach ($availability[$weekday] as $block) {
        $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d') . ' ' . $block['start_time']);
        $end_dt = DateTime::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d') . ' ' . $block['end_time']);
        if (!$start_dt || !$end_dt) continue;
        $cursor = clone $start_dt;
        while ($cursor->getTimestamp() + ($duration*60) <= $end_dt->getTimestamp()) {
            $candidate_start = $cursor->format('Y-m-d H:i:s');
            $candidate_end = $cursor->modify("+{$duration} minutes")->format('Y-m-d H:i:s');

            $ov = $conn->prepare("SELECT COUNT(*) AS cnt FROM schedules WHERE user_id = ? AND status IN ('scheduled','rescheduled') AND NOT (scheduled_end <= ? OR scheduled_start >= ?)");
            $ov->bind_param('iss', $doc_id, $candidate_start, $candidate_end);
            $ov->execute();
            $ovres = $ov->get_result();
            $ovrow = $ovres ? $ovres->fetch_assoc() : null;
            $ov->close();

            if ((int)($ovrow['cnt'] ?? 0) === 0) {
                $found = ['start'=>$candidate_start,'end'=>$candidate_end];
                break 3;
            }

            $cursor = $cursor->modify('+10 minutes');
        }
    }
}

if ($found) {
    $ins2 = $conn->prepare('INSERT INTO schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, "scheduled")');
    $ins2->bind_param('iiss', $appointment_id, $doc_id, $found['start'], $found['end']);
    $ins2->execute();
    $schedule_id = $conn->insert_id;
    $ins2->close();

    $remind_at = (new DateTime($found['start']))->modify('-24 hours')->format('Y-m-d H:i:s');
    $payload = json_encode(['appointment_id'=>$appointment_id,'schedule_id'=>$schedule_id]);
    $r2 = $conn->prepare('INSERT INTO schedule_reminders (schedule_id, remind_at, channel, payload) VALUES (?, ?, "email", ?)');
    $r2->bind_param('iss', $schedule_id, $remind_at, $payload);
    $r2->execute();
    $r2->close();

    send_json(['success'=>true,'message'=>'Appointment confirmed and earliest slot scheduled','schedule_id'=>$schedule_id,'start'=>$found['start'],'end'=>$found['end']]);
    exit;
}

// No slot found - return suggestion link to smart_schedule
send_json(['success'=>false,'message'=>'No available slot found. Please call smart_schedule to get suggestions.','suggest_url'=>'assets/api/appointments/smart_schedule.php'], 200);

?>
