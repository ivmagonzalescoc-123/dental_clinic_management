<?php
/*
 * Simple seed script to create a test appointment and check-in queue entry
 * Usage (CLI): php seed_queue.php [patient_id]
 * If patient_id is omitted it defaults to 1.
 */

require_once __DIR__ . '/../config/database.php';

$patient_id = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 1;
$out = ['success' => false];

if (!$conn) {
    $out['error'] = 'No DB connection';
    echo json_encode($out) . PHP_EOL;
    exit(1);
}

try {
    // Create an appointment for today
    $today = date('Y-m-d');
    $reason = 'Seed appointment for queue testing';
    $status = 'scheduled';

    $stmt = $conn->prepare('INSERT INTO appointments (appointment_date, reason, status, created_at, patient_id) VALUES (?, ?, ?, NOW(), ?)');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('sssi', $today, $reason, $status, $patient_id);
    $stmt->execute();
    $appointment_id = $conn->insert_id;
    $stmt->close();

    // compute next queue number for today
    $qr = $conn->prepare('SELECT COALESCE(MAX(que_number),0) + 1 AS next_q FROM check_in_queue WHERE DATE(checkin_time) = CURDATE()');
    if (!$qr) throw new Exception('Prepare failed: ' . $conn->error);
    $qr->execute();
    $rs = $qr->get_result();
    $row = $rs->fetch_assoc();
    $next_q = $row['next_q'] ?? 1;
    $qr->close();

    // insert into check_in_queue
    $qst = $conn->prepare('INSERT INTO check_in_queue (status, checkin_time, que_number, appointment_id) VALUES (?, NOW(), ?, ?)');
    if (!$qst) throw new Exception('Prepare failed: ' . $conn->error);
    $statusQueue = 'waiting';
    $qst->bind_param('sii', $statusQueue, $next_q, $appointment_id);
    $qst->execute();
    $checkin_id = $conn->insert_id;
    $qst->close();

    $out['success'] = true;
    $out['appointment_id'] = $appointment_id;
    $out['checkin_id'] = $checkin_id;
    $out['que_number'] = $next_q;
    echo json_encode($out) . PHP_EOL;
} catch (Exception $e) {
    $out['error'] = $e->getMessage();
    echo json_encode($out) . PHP_EOL;
    exit(1);
}

?>