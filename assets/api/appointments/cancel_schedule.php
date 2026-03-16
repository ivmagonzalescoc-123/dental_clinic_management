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
if ($schedule_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing schedule_id']);
    exit;
}

try {
    // Fetch schedule
    $stmt = $conn->prepare('SELECT schedule_id, user_id, appointment_id, status FROM patient_schedules WHERE schedule_id = ? LIMIT 1');
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $sch = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$sch) throw new Exception('Schedule not found');

    // Role checks: doctors may only cancel their own schedules
    if ($role === 'doctor' && (int)$sch['user_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Forbidden']);
        exit;
    }

    // Perform cancellation
    $ust = $conn->prepare('UPDATE patient_schedules SET status = ?, updated_at = NOW() WHERE schedule_id = ?');
    $status = 'cancelled';
    $ust->bind_param('si', $status, $schedule_id);
    $ust->execute();
    $affected = $ust->affected_rows;
    $ust->close();

    echo json_encode(['success'=>true,'message'=>'Schedule cancelled','schedule_id'=>$schedule_id,'affected'=>$affected]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

?>
