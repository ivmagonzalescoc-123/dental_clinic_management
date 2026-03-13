<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor', 'secretary', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
if (!$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

$doctor_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $conn->begin_transaction();

    // Verify patient has arrived/check-in before starting session
    $paStmt = $conn->prepare(
        "SELECT a.status AS appointment_status, pa.arrival_id, pa.status AS arrival_status
         FROM appointments a
         LEFT JOIN patient_arrivals pa ON pa.appointment_id = a.appointment_id
         WHERE a.appointment_id = ?
         LIMIT 1"
    );
    if (!$paStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $paStmt->bind_param('i', $appointment_id);
    $paStmt->execute();
    $paRow = $paStmt->get_result()->fetch_assoc();
    $paStmt->close();

    if (!$paRow) {
        throw new Exception('Appointment not found.');
    }

    $arrivalStatus = $paRow['arrival_status'] ?? null;
    $appointmentStatus = $paRow['appointment_status'] ?? '';
    $allowed = in_array($arrivalStatus, ['arrived', 'late'], true) || $appointmentStatus === 'checked_in';

    if (!$allowed) {
        throw new Exception('Patient has not been marked as arrived yet.');
    }

    // Normalize arrival row/status for checked-in appointments to keep queue/session consistent.
    if (!$arrivalStatus && $appointmentStatus === 'checked_in') {
        $mkArr = $conn->prepare("INSERT INTO patient_arrivals (appointment_id, timeslot_id, expected_time, arrived_at, status, marked_by)
                                 SELECT a.appointment_id, a.timeslot_id, t.start_time, NOW(), 'arrived', ?
                                 FROM appointments a
                                 INNER JOIN timeslot t ON t.timeslot_id = a.timeslot_id
                                 WHERE a.appointment_id = ?");
        if ($mkArr) {
            $mkArr->bind_param('ii', $doctor_id, $appointment_id);
            $mkArr->execute();
            $mkArr->close();
        }
    } elseif ($arrivalStatus === 'checked_in') {
        $fixArr = $conn->prepare("UPDATE patient_arrivals SET status = 'arrived' WHERE appointment_id = ?");
        if ($fixArr) {
            $fixArr->bind_param('i', $appointment_id);
            $fixArr->execute();
            $fixArr->close();
        }
    }

    // Assign doctor and mark appointment in_progress
    $updA = $conn->prepare("UPDATE appointments SET user_id = ?, status = 'in_progress' WHERE appointment_id = ?");
    $updA->bind_param('ii', $doctor_id, $appointment_id);
    $updA->execute();
    $updA->close();

    // Find or create a schedules row (for EHR linkage)
    $sStmt = $conn->prepare("SELECT schedule_id FROM schedules WHERE appointment_id = ? LIMIT 1");
    $sStmt->bind_param('i', $appointment_id);
    $sStmt->execute();
    $sRow = $sStmt->get_result()->fetch_assoc();
    $sStmt->close();

    if ($sRow) {
        $schedule_id = (int)$sRow['schedule_id'];
        $updS = $conn->prepare(
            "UPDATE schedules SET status = 'scheduled', user_id = ? WHERE schedule_id = ?"
        );
        $updS->bind_param('ii', $doctor_id, $schedule_id);
        $updS->execute();
        $updS->close();
    } else {
        // Pull times from timeslot via appointments.timeslot_id
        $tsStmt = $conn->prepare("
            SELECT t.slot_date, t.start_time, t.end_time
            FROM timeslot t
            INNER JOIN appointments a ON a.timeslot_id = t.timeslot_id
            WHERE a.appointment_id = ?
            LIMIT 1
        ");
        $tsStmt->bind_param('i', $appointment_id);
        $tsStmt->execute();
        $tsRow = $tsStmt->get_result()->fetch_assoc();
        $tsStmt->close();

        $schStart = $tsRow ? ($tsRow['slot_date'] . ' ' . $tsRow['start_time']) : date('Y-m-d H:i:s');
        $schEnd   = $tsRow ? ($tsRow['slot_date'] . ' ' . $tsRow['end_time'])   : date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $insS = $conn->prepare(
            "INSERT INTO schedules (appointment_id, user_id, scheduled_start, scheduled_end, status)
             VALUES (?, ?, ?, ?, 'scheduled')"
        );
        $insS->bind_param('iiss', $appointment_id, $doctor_id, $schStart, $schEnd);
        $insS->execute();
        $schedule_id = (int)$conn->insert_id;
        $insS->close();
    }

    $conn->commit();
    echo json_encode([
        'success'        => true,
        'message'        => 'Session started',
        'schedule_id'    => $schedule_id,
        'appointment_id' => $appointment_id,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
