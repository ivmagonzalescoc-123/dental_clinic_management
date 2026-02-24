<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','secretary','admin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$schedule_id = (int)($_GET['schedule_id'] ?? 0);
if ($schedule_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing schedule_id']);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT schedule_id, appointment_id, user_id, scheduled_start, scheduled_end, status FROM schedules WHERE schedule_id = ? LIMIT 1');
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Schedule not found']);
        exit;
    }

    // derive duration in minutes if start/end present
    $duration = null;
    if (!empty($row['scheduled_start']) && !empty($row['scheduled_end'])) {
        $s = new DateTime($row['scheduled_start']);
        $e = new DateTime($row['scheduled_end']);
        $duration = (int) (($e->getTimestamp() - $s->getTimestamp()) / 60);
    }

    // fallback default duration
    if (!$duration) $duration = 30;

    echo json_encode(['success'=>true,'schedule'=>[ 'schedule_id'=>(int)$row['schedule_id'], 'appointment_id'=>(int)$row['appointment_id'], 'user_id'=>(int)$row['user_id'], 'scheduled_start'=>$row['scheduled_start'], 'scheduled_end'=>$row['scheduled_end'], 'status'=>$row['status'], 'duration'=>$duration ]]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

?>
