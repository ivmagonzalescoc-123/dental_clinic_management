<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/security.php';

security_start_session();

$patientId = (int)($_SESSION['patient_id'] ?? 0);
if ($patientId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

$lastKnown = trim((string)($_GET['last'] ?? ''));

// This endpoint is polled every few seconds — table creation is owned by the
// write path (request_reschedule.php) instead of re-running DDL on every poll.
// If the table doesn't exist yet, prepare() below returns false and the
// existing fallback (changed => true) handles it gracefully.
$stmt = $conn->prepare(
    "SELECT CONCAT(
        COALESCE((SELECT MAX(appointment_id) FROM appointments WHERE patient_id = ?), 0), ':',
        COALESCE((SELECT MAX(request_id) FROM appointment_reschedule_requests WHERE patient_id = ?), 0), ':',
        COALESCE((SELECT COUNT(*) FROM appointment_reschedule_requests WHERE patient_id = ? AND status = 'pending'), 0)
     ) AS sig"
);
if (!$stmt) {
    echo json_encode(['changed' => true]);
    exit;
}
$stmt->bind_param('iii', $patientId, $patientId, $patientId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$current = (string)($row['sig'] ?? '');
$changed = ($lastKnown === '' || $current !== $lastKnown);

echo json_encode(['changed' => $changed, 'sig' => $current]);
