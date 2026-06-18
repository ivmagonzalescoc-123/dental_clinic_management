<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';

security_start_session();

$patientId = (int)($_SESSION['patient_id'] ?? 0);
if ($patientId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

$lastKnown = trim((string)($_GET['last'] ?? ''));

$conn->query("CREATE TABLE IF NOT EXISTS appointment_reschedule_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    requested_doctor_id INT NULL,
    requested_date DATE NOT NULL,
    requested_timeslot_id INT NOT NULL,
    request_note VARCHAR(500) NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    review_note VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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
