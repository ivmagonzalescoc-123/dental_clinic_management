<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';
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
    echo json_encode(['success'=>false,'message'=>'Please refresh the page and try again.']);
    exit;
}

$schedule_id = (int)($_POST['schedule_id'] ?? 0);
if ($schedule_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing schedule_id']);
    exit;
}

$reason = trim((string)($_POST['reason'] ?? ''));
if ($reason === '') {
    $reason = 'Cancelled by ' . ($role === 'doctor' ? 'doctor' : ($role === 'secretary' ? 'secretary' : 'admin'));
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

    $appointmentId = (int)($sch['appointment_id'] ?? 0);

    // Perform cancellation
    $ust = $conn->prepare('UPDATE patient_schedules SET status = ?, updated_at = NOW() WHERE schedule_id = ?');
    $status = 'cancelled';
    $ust->bind_param('si', $status, $schedule_id);
    $ust->execute();
    $affected = $ust->affected_rows;
    $ust->close();

    // Cascade to the appointment itself and free its timeslot back up — cancelling
    // just the patient_schedules row (the old behavior) left the appointment showing
    // "confirmed" forever with the reason nowhere recorded, and the slot permanently
    // stuck as "booked" so no other patient could ever rebook it.
    $apptRow = null;
    if ($appointmentId > 0) {
        $apptStmt = $conn->prepare('SELECT appointment_id, patient_id, user_id, appointment_date, timeslot_id FROM appointments WHERE appointment_id = ? LIMIT 1');
        $apptStmt->bind_param('i', $appointmentId);
        $apptStmt->execute();
        $apptRow = $apptStmt->get_result()->fetch_assoc();
        $apptStmt->close();
    }

    if ($apptRow) {
        $updAppt = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE appointment_id = ?");
        $updAppt->bind_param('si', $reason, $appointmentId);
        $updAppt->execute();
        $updAppt->close();

        $timeslotId = (int)($apptRow['timeslot_id'] ?? 0);
        if ($timeslotId > 0) {
            $freeSlot = $conn->prepare("UPDATE timeslot SET status = 'available', appointment_id = NULL WHERE timeslot_id = ? AND status = 'booked'");
            $freeSlot->bind_param('i', $timeslotId);
            $freeSlot->execute();
            $freeSlot->close();
        }

        if (function_exists('wf_notify_appointment_cancelled')) {
            wf_notify_appointment_cancelled(
                $conn,
                $appointmentId,
                (int)($apptRow['patient_id'] ?? 0),
                (int)($apptRow['user_id'] ?? 0),
                (string)($apptRow['appointment_date'] ?? ''),
                $reason
            );
        }
    }

    if ($affected > 0 && function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'schedule.cancel',
            'patient_schedules',
            $schedule_id,
            'Cancelled schedule for appointment_id=' . $appointmentId . '. Reason: ' . $reason,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    echo json_encode(['success'=>true,'message'=>'Schedule cancelled','schedule_id'=>$schedule_id,'affected'=>$affected]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

?>
