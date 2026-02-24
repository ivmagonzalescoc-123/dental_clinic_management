<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
check_session(['doctor']);
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$doctor_id = $_SESSION['user_id'] ?? 0;
$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if (!$doctor_id || !$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn->begin_transaction();

    // If appointment is assigned to someone else, claim it for this doctor
    $claimStmt = $conn->prepare('UPDATE appointments SET user_id = ? WHERE appointment_id = ?');
    if (!$claimStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $claimStmt->bind_param('ii', $doctor_id, $appointment_id);
    $claimStmt->execute();
    $claimStmt->close();

    // Mark queue entry as in_progress and record served_time
    $queueStatus = 'in_progress';
    $stmt = $conn->prepare('UPDATE check_in_queue SET status = ?, served_time = NOW() WHERE appointment_id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('si', $queueStatus, $appointment_id);
    $stmt->execute();
    $stmt->close();

    // Update appointment status
    $apptStatus = 'in_progress';
    $ust = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
    if (!$ust) throw new Exception('Prepare failed: ' . $conn->error);
    $ust->bind_param('si', $apptStatus, $appointment_id);
    $ust->execute();
    $ust->close();

    // Ensure there is a schedule for this appointment and mark it in_progress
    $schStmt = $conn->prepare('SELECT schedule_id FROM schedules WHERE appointment_id = ? AND status IN ("scheduled","rescheduled") ORDER BY scheduled_start LIMIT 1');
    if ($schStmt) {
        $schStmt->bind_param('i', $appointment_id);
        $schStmt->execute();
        $sres = $schStmt->get_result();
        $srow = $sres ? $sres->fetch_assoc() : null;
        $schStmt->close();

            if ($srow && isset($srow['schedule_id'])) {
                $schedule_id = (int)$srow['schedule_id'];
                $updSch = $conn->prepare('UPDATE schedules SET status = "in_progress" WHERE schedule_id = ?');
                $updSch->bind_param('i', $schedule_id);
                $updSch->execute();
                $updSch->close();
            } else {
            // create an ad-hoc schedule starting now
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $insSch = $conn->prepare('INSERT INTO schedules (appointment_id, user_id, scheduled_start, status) VALUES (?, ?, ?, "in_progress")');
            $doc_id = $doctor_id;
            $insSch->bind_param('iis', $appointment_id, $doc_id, $now);
            $insSch->execute();
                $newScheduleId = $conn->insert_id;
            $insSch->close();
        }
    }

    $conn->commit();
        // return schedule_id when available
        $sid = $schedule_id ?? ($newScheduleId ?? null);
        echo json_encode(['success' => true, 'message' => 'Patient accepted, visit started', 'schedule_id' => $sid]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
