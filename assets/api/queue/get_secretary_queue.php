<?php
header('Content-Type: application/json');
include '../../../config/database.php';

$today = date('Y-m-d');

// Get waiting count
$waitingCount = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM check_in_queue WHERE DATE(checkin_time) = ? AND status = 'waiting'");
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        $waitingCount = (int)($r['c'] ?? 0);
    }
    $stmt->close();
}

// Get full queue for today
$sql = "SELECT q.checkin_id, q.que_number, q.status AS queue_status, q.checkin_time,
               a.appointment_id, a.appointment_date,
               p.first_name AS patient_first, p.last_name AS patient_last,
               u.first_name AS doctor_first, u.last_name AS doctor_last
        FROM check_in_queue q
        JOIN appointments a ON q.appointment_id = a.appointment_id
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE DATE(q.checkin_time) = ?
        ORDER BY q.que_number ASC";
    // Normalize fields: ensure que_number present and format checkin_time on PHP side

$queue = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            // normalize values
            $row['que_number'] = isset($row['que_number']) ? (int)$row['que_number'] : 0;
            $row['patient_name'] = trim((string)($row['patient_first'] ?? '') . ' ' . ($row['patient_last'] ?? ''));
            $row['doctor_name'] = trim((string)($row['doctor_first'] ?? '') . ' ' . ($row['doctor_last'] ?? ''));
            // format checkin_time to readable string (if present)
            $row['checkin_time'] = $row['checkin_time'] ? date('Y-m-d H:i:s', strtotime($row['checkin_time'])) : '';
            $queue[] = $row;
        }
    }
    $stmt->close();
}

echo json_encode(['success' => true, 'count' => $waitingCount, 'queue' => $queue]);
