<?php
/**
 * Lightweight per-visit summary list for a patient, used to populate the date sub-nav
 * in the consolidated (one-row-per-patient) Records view for both the doctor and
 * secretary portals. Full detail for a chosen visit is fetched separately via
 * get_records_by_appointment_detailed.php?record_id=... to avoid duplicating that logic.
 */
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor', 'secretary', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing patient_id']);
    exit;
}

$sql = "
    SELECT cr.record_id, cr.appointment_id, cr.status, cr.submitted_at, a.appointment_date,
           u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
    FROM clinical_records cr
    INNER JOIN appointments a ON cr.appointment_id = a.appointment_id
    LEFT JOIN users u ON u.user_id = cr.doctor_id
    WHERE cr.patient_id = ?
    ORDER BY COALESCE(cr.submitted_at, a.appointment_date) DESC
";
$stmt = $conn->prepare($sql);
$visits = [];
if ($stmt) {
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $visits[] = [
            'record_id' => (int)$r['record_id'],
            'appointment_id' => (int)$r['appointment_id'],
            'status' => $r['status'],
            'submitted_at' => $r['submitted_at'],
            'appointment_date' => $r['appointment_date'],
            'doctor_name' => trim((string)($r['doctor_first_name'] ?? '') . ' ' . (string)($r['doctor_last_name'] ?? '')),
        ];
    }
    $stmt->close();
}

echo json_encode(['success' => true, 'visits' => $visits]);
