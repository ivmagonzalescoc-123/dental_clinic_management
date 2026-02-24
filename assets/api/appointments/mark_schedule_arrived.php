<?php
// Secretary/Staff marks a schedule as arrived; create queue entry for appointment so doctor can accept and open EHR
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

// Only secretary or staff roles may mark arrived
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary','admin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
if ($schedule_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing schedule_id']);
    exit;
}

try {
    $conn->begin_transaction();

    // Fetch schedule
    $stmt = $conn->prepare('SELECT schedule_id, appointment_id, user_id FROM schedules WHERE schedule_id = ? LIMIT 1');
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $sch = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$sch) throw new Exception('Schedule not found');
    $appointment_id = (int)$sch['appointment_id'];
    $doctor_id = (int)$sch['user_id'];

    // Ensure appointment exists
    $ast = $conn->prepare('SELECT appointment_id, patient_id FROM appointments WHERE appointment_id = ? LIMIT 1');
    $ast->bind_param('i', $appointment_id);
    $ast->execute();
    $ares = $ast->get_result();
    $appt = $ares ? $ares->fetch_assoc() : null;
    $ast->close();
    if (!$appt) throw new Exception('Linked appointment not found');

    $patient_id = (int)$appt['patient_id'];

    // Create queue entry for the appointment so doctor can accept it
    // Determine next queue number for today
    $qst = $conn->prepare('SELECT COALESCE(MAX(que_number),0) + 1 AS next_q FROM check_in_queue WHERE DATE(checkin_time) = CURDATE()');
    $qst->execute();
    $qres = $qst->get_result();
    $qrow = $qres ? $qres->fetch_assoc() : null;
    $next_q = (int)($qrow['next_q'] ?? 1);
    $qst->close();

    $ins = $conn->prepare('INSERT INTO check_in_queue (appointment_id, checkin_time, que_number, status) VALUES (?, NOW(), ?, ?)');
    $status = 'waiting';
    $ins->bind_param('iis', $appointment_id, $next_q, $status);
    $ins->execute();
    $ins->close();

    // Optionally mark appointment as confirmed so it shows up in doctor's list
    $ust = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
    $confirmed = 'confirmed';
    $ust->bind_param('si', $confirmed, $appointment_id);
    $ust->execute();
    $ust->close();

    $conn->commit();
    echo json_encode(['success'=>true,'message'=>'Schedule marked arrived and patient added to queue','appointment_id'=>$appointment_id,'queue_number'=>$next_q]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

?>