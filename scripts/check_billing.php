<?php
// Check billing for an appointment via CLI
if (session_status() === PHP_SESSION_NONE) session_start();

require __DIR__ . '/../config/database.php';
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$stmt = $conn->prepare('SELECT billing_id, appointment_id, user_id, total_amount, created_at FROM billing WHERE appointment_id = ?');
$stmt->bind_param('i', $appt);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
echo json_encode(['appointment_id'=>$appt,'rows'=>$rows], JSON_PRETTY_PRINT);
