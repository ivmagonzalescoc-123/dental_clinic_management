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

    // Load appointment + timeslot info
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.status, a.is_emergency,
               t.timeslot_id, t.start_time, t.slot_date
        FROM appointments a
        INNER JOIN timeslot t ON t.timeslot_id = a.timeslot_id
        WHERE a.appointment_id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) throw new Exception('Appointment not found or has no timeslot assigned');

    // Determine if arriving late (15-min grace period)
    $now          = new DateTime();
    $expectedTs   = strtotime($appt['slot_date'] . ' ' . $appt['start_time']);
    $isLate       = ($expectedTs && (time() - $expectedTs) > 900);
    $newStatus    = $isLate ? 'late' : 'arrived';
    $arrivedAt    = $now->format('Y-m-d H:i:s');
    $timeslotId   = (int)$appt['timeslot_id'];
    $expectedTime = $appt['start_time'];

    // Check if patient_arrivals row already exists
    $cqStmt = $conn->prepare("SELECT arrival_id FROM patient_arrivals WHERE appointment_id = ? LIMIT 1");
    $cqStmt->bind_param('i', $appointment_id);
    $cqStmt->execute();
    $cqRow = $cqStmt->get_result()->fetch_assoc();
    $cqStmt->close();

    if ($cqRow) {
        $upd = $conn->prepare(
            "UPDATE patient_arrivals SET status = ?, arrived_at = ?, marked_by = ? WHERE appointment_id = ?"
        );
        $upd->bind_param('ssii', $newStatus, $arrivedAt, $marked_by, $appointment_id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("
            INSERT INTO patient_arrivals
                (appointment_id, timeslot_id, expected_time, arrived_at, status, marked_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param('iisssi', $appointment_id, $timeslotId, $expectedTime, $arrivedAt, $newStatus, $marked_by);
        $ins->execute();
        $ins->close();
    }

    // Mark appointment as checked_in
    $updA = $conn->prepare("UPDATE appointments SET status = 'checked_in' WHERE appointment_id = ?");
    $updA->bind_param('i', $appointment_id);
    $updA->execute();
    $updA->close();

    $conn->commit();

    echo json_encode([
        'success'        => true,
        'message'        => $isLate ? 'Marked late.' : 'Marked arrived.',
        'arrival_status' => $newStatus,
        'is_late'        => $isLate,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
