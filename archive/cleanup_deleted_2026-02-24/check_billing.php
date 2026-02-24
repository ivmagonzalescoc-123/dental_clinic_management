<?php
// CLI helper to query billing rows for an appointment
require __DIR__ . '/../config/database.php';
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$stmt = $conn->prepare('SELECT * FROM billing WHERE appointment_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $appt);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

echo json_encode(['appointment_id'=>$appt,'rows'=>$rows], JSON_PRETTY_PRINT);
