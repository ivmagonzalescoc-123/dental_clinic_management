<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($role, ['admin','secretary','doctor'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$schedule_id = (int)($_POST['schedule_id'] ?? 0);
$start = $_POST['scheduled_start'] ?? '';
$end = $_POST['scheduled_end'] ?? '';

if ($schedule_id <= 0 || !$start) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing parameters']);
    exit;
}

try {
    // Fetch schedule
    $stmt = $conn->prepare('SELECT schedule_id, user_id, appointment_id FROM schedules WHERE schedule_id = ? LIMIT 1');
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $sch = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$sch) throw new Exception('Schedule not found');

    // Role checks: doctors may only reschedule their own schedules
    if ($role === 'doctor' && (int)$sch['user_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Forbidden']);
        exit;
    }

    // Basic overlap check: reuse smart_schedule overlap query
    $ov = $conn->prepare("SELECT COUNT(*) AS cnt FROM schedules WHERE user_id = ? AND status IN ('scheduled','rescheduled') AND NOT (scheduled_end <= ? OR scheduled_start >= ?) AND schedule_id != ?");
    $ov->bind_param('issi', $sch['user_id'], $start, $end, $schedule_id);
    $ov->execute();
    $ores = $ov->get_result();
    $oc = $ores ? $ores->fetch_assoc() : null;
    $ov->close();
    if ($oc && (int)$oc['cnt'] > 0) {
        echo json_encode(['success'=>false,'message'=>'Requested time overlaps with existing schedule']);
        exit;
    }

    // Update schedule
    $ust = $conn->prepare('UPDATE schedules SET scheduled_start = ?, scheduled_end = ?, status = ?, updated_at = NOW() WHERE schedule_id = ?');
    $status = 'rescheduled';
    $ust->bind_param('sssi', $start, $end, $status, $schedule_id);
    $ust->execute();
    $affected = $ust->affected_rows;
    $ust->close();

    // Optionally create a reminder row
    $rins = $conn->prepare('INSERT INTO schedule_reminders (schedule_id, remind_at, channel, payload) VALUES (?, DATE_SUB(?, INTERVAL 1 DAY), "in_app", ?)');
    $payload = json_encode(['schedule_id'=>$schedule_id,'appointment_id'=>$sch['appointment_id']]);
    $rins->bind_param('iss', $schedule_id, $start, $payload);
    $rins->execute();
    if ($rins) $rins->close();

    echo json_encode(['success'=>true,'message'=>'Schedule rescheduled','schedule_id'=>$schedule_id,'affected'=>$affected]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

?>
