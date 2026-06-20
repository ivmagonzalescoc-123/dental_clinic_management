<?php
include '../../../config/database.php';
include_once '../../../config/session_check.php';
header('Content-Type: application/json');
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','secretary','admin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if (!$patient_id) { echo json_encode(['success'=>false,'message'=>'Missing patient_id']); exit; }
$stmt = $conn->prepare('SELECT patient_id, first_name, last_name, date_of_birth, phone_number, email FROM patients WHERE patient_id = ? LIMIT 1');
$stmt->bind_param('i', $patient_id);
$stmt->execute();
$res = $stmt->get_result();
$pat = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$pat) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

$pfStmt = $conn->prepare('SELECT race, occupation, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, asa_classification, pregnancy_status, previous_dental_treatment, dental_anxiety, emergency_contact, emergency_phone, background_notes FROM patient_profiling WHERE patient_id = ? ORDER BY profiling_id DESC LIMIT 1');
if ($pfStmt) {
    $pfStmt->bind_param('i', $patient_id);
    $pfStmt->execute();
    $pfRes = $pfStmt->get_result();
    $pfRow = $pfRes ? $pfRes->fetch_assoc() : null;
    $pfStmt->close();
    if ($pfRow) {
        $pat['profiling'] = $pfRow;
    }
}

echo json_encode(['success'=>true,'patient'=>$pat]);
