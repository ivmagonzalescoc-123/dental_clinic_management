<?php
/**
 * The findings that stay the same between visits (occlusal classification, standing medical
 * history), taken from the patient's most recent earlier visit so a new session doesn't
 * start blank. Each visit still keeps its own findings row — this only supplies starting
 * values; everything else is recorded fresh.
 */
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
header('Content-Type: application/json');

check_session(['doctor']);

$appointment_id = (int)($_GET['appointment_id'] ?? 0);
if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

$stmt = $conn->prepare('SELECT patient_id FROM appointments WHERE appointment_id = ? LIMIT 1');
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$appt) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Appointment not found']);
    exit;
}

$previous = get_previous_clinical_findings($conn, (int)$appt['patient_id'], $appointment_id);
echo json_encode([
    'success' => true,
    'findings' => $previous ? $previous['findings'] : null,
    'visit_date' => $previous ? $previous['visit_date'] : null,
]);
