<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$marked_by      = (int)($_SESSION['user_id'] ?? 0);

if (!$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    // Load timeslot info
    $tsStmt = $conn->prepare(
        "SELECT t.timeslot_id, t.start_time FROM timeslot t
         INNER JOIN appointments a ON a.timeslot_id = t.timeslot_id
         WHERE a.appointment_id = ? LIMIT 1"
    );
    if (!$tsStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $tsStmt->bind_param('i', $appointment_id);
    $tsStmt->execute();
    $tsRow = $tsStmt->get_result()->fetch_assoc();
    $tsStmt->close();

    $noShow = 'no_show';

    // Upsert patient_arrivals as no_show
    $cqStmt = $conn->prepare("SELECT arrival_id FROM patient_arrivals WHERE appointment_id = ? LIMIT 1");
    $cqStmt->bind_param('i', $appointment_id);
    $cqStmt->execute();
    $existing = $cqStmt->get_result()->fetch_assoc();
    $cqStmt->close();

    if ($existing) {
        $upd = $conn->prepare(
            "UPDATE patient_arrivals SET status = ?, arrived_at = NULL, marked_by = ? WHERE appointment_id = ?"
        );
        $upd->bind_param('sii', $noShow, $marked_by, $appointment_id);
        $upd->execute();
        $upd->close();
    } else {
        $timeslotId   = $tsRow ? (int)$tsRow['timeslot_id'] : 0;
        $expectedTime = $tsRow ? $tsRow['start_time'] : '00:00:00';
        $ins = $conn->prepare("
            INSERT INTO patient_arrivals
                (appointment_id, timeslot_id, expected_time, arrived_at, status, marked_by)
            VALUES (?, ?, ?, NULL, ?, ?)
        ");
        $ins->bind_param('iissi', $appointment_id, $timeslotId, $expectedTime, $noShow, $marked_by);
        $ins->execute();
        $ins->close();
    }

    // Free timeslot back to available
    $freeSlot = $conn->prepare(
        "UPDATE timeslot SET status = 'available', appointment_id = NULL
         WHERE timeslot_id IN (SELECT timeslot_id FROM appointments WHERE appointment_id = ?)"
    );
    $freeSlot->bind_param('i', $appointment_id);
    $freeSlot->execute();
    $freeSlot->close();

    // Mark appointment as no_show
    $updA = $conn->prepare("UPDATE appointments SET status = 'no_show' WHERE appointment_id = ?");
    $updA->bind_param('i', $appointment_id);
    $updA->execute();
    $updA->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Patient marked as no-show. Timeslot freed.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
