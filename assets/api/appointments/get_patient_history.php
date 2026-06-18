<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';

security_start_session();

$patientId = (int)($_SESSION['patient_id'] ?? 0);
if ($patientId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_arr_status_created (status, created_at),
    KEY idx_arr_appointment_status (appointment_id, status),
    KEY idx_arr_patient_status (patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$conn->query("ALTER TABLE appointments ADD INDEX IF NOT EXISTS idx_patient_date (patient_id, appointment_date DESC)");

$historySql = "SELECT a.appointment_id, a.appointment_date, a.reason, a.status, a.created_at,
                      a.user_id, a.timeslot_id,
                      u.first_name AS doctor_first_name, u.last_name AS doctor_last_name,
                      t.start_time, t.end_time,
                      (SELECT COUNT(*) FROM appointment_reschedule_requests rr
                       WHERE rr.appointment_id = a.appointment_id AND rr.status = 'pending') AS pending_reschedule_count
               FROM appointments a
               LEFT JOIN users u ON u.user_id = a.user_id
               LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
               WHERE a.patient_id = ?
               ORDER BY a.appointment_date DESC, a.appointment_id DESC
               LIMIT 100";

$stmt = $conn->prepare($historySql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed']);
    exit;
}
$stmt->bind_param('i', $patientId);
$stmt->execute();
$res = $stmt->get_result();

$appointments = [];
while ($row = $res->fetch_assoc()) {
    $appointments[] = [
        'appointment_id' => (int)$row['appointment_id'],
        'appointment_date' => $row['appointment_date'],
        'reason' => $row['reason'],
        'status' => $row['status'],
        'user_id' => (int)($row['user_id'] ?? 0),
        'doctor_first_name' => $row['doctor_first_name'],
        'doctor_last_name' => $row['doctor_last_name'],
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time'],
        'pending_reschedule_count' => (int)($row['pending_reschedule_count'] ?? 0),
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'appointments' => $appointments]);
