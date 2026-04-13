<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
include __DIR__ . '/availability_guard.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']);
    exit;
}

$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($role, ['admin','secretary','doctor'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}
if (!capabilities_can('appointment.manage')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied: missing appointment management permission.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

$schedule_id = (int)($_POST['schedule_id'] ?? 0);
$start = $_POST['scheduled_start'] ?? '';
$end = $_POST['scheduled_end'] ?? '';
$requestedDoctorId = (int)($_POST['user_id'] ?? 0);

if ($schedule_id <= 0 || !$start) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing parameters']);
    exit;
}

try {
    // Fetch schedule
    $stmt = $conn->prepare('SELECT schedule_id, user_id, appointment_id FROM patient_schedules WHERE schedule_id = ? LIMIT 1');
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $sch = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$sch) throw new Exception('Schedule not found');

    // Role checks: doctors may only reschedule their own schedules and cannot reassign doctor.
    if ($role === 'doctor' && (int)$sch['user_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Forbidden']);
        exit;
    }

    $targetDoctorId = (int)$sch['user_id'];
    if ($requestedDoctorId > 0) {
        if ($role === 'doctor' && $requestedDoctorId !== $targetDoctorId) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Doctors cannot reassign schedules to another doctor']);
            exit;
        }
        $targetDoctorId = $requestedDoctorId;
    }

    $docStmt = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1');
    $docStmt->bind_param('i', $targetDoctorId);
    $docStmt->execute();
    $docRow = $docStmt->get_result()->fetch_assoc();
    $docStmt->close();
    if (!$docRow) {
        echo json_encode(['success'=>false,'message'=>'Selected doctor does not exist']);
        exit;
    }

    $reschedDate = date('Y-m-d', strtotime($start));
    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $targetDoctorId, $reschedDate);
    if (!$bookable) {
        echo json_encode(['success'=>false,'message'=>$bookableMsg]);
        exit;
    }

    // Basic overlap check: reuse smart_schedule overlap query
    $ov = $conn->prepare("SELECT COUNT(*) AS cnt FROM patient_schedules WHERE user_id = ? AND status IN ('scheduled','rescheduled') AND NOT (scheduled_end <= ? OR scheduled_start >= ?) AND schedule_id != ?");
    $ov->bind_param('issi', $targetDoctorId, $start, $end, $schedule_id);
    $ov->execute();
    $ores = $ov->get_result();
    $oc = $ores ? $ores->fetch_assoc() : null;
    $ov->close();
    if ($oc && (int)$oc['cnt'] > 0) {
        echo json_encode(['success'=>false,'message'=>'Requested time overlaps with existing schedule']);
        exit;
    }

    // Update schedule
    $ust = $conn->prepare('UPDATE patient_schedules SET user_id = ?, scheduled_start = ?, scheduled_end = ?, status = ?, updated_at = NOW() WHERE schedule_id = ?');
    $status = 'rescheduled';
    $ust->bind_param('isssi', $targetDoctorId, $start, $end, $status, $schedule_id);
    $ust->execute();
    $affected = $ust->affected_rows;
    $ust->close();

    if ($affected > 0 && function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'schedule.reschedule',
            'patient_schedules',
            $schedule_id,
            'Rescheduled schedule to doctor_id=' . $targetDoctorId . ', start=' . $start . ', end=' . $end,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    reminder_queue_default_pack($conn, $schedule_id, $start, (int)$sch['appointment_id']);

    echo json_encode([
        'success'=>true,
        'message'=>'Schedule updated',
        'schedule_id'=>$schedule_id,
        'doctor_id'=>$targetDoctorId,
        'affected'=>$affected
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

?>
