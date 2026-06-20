<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include_once '../../../config/session_check.php';
include_once '../../../config/patient_profiling_helpers.php';

security_start_session();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor', 'secretary', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$patient_id = (int)($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT patient_id, first_name, last_name, middle_name, date_of_birth, gender, phone_number, email, address, profile_image, last_login_at, created_at FROM patients WHERE patient_id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }

    $profiling = null;
    $pfStmt = $conn->prepare('SELECT race, occupation, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, asa_classification, pregnancy_status, previous_dental_treatment, dental_anxiety, emergency_contact, emergency_phone, background_notes FROM patient_profiling WHERE patient_id = ? ORDER BY profiling_id DESC LIMIT 1');
    if ($pfStmt) {
        $pfStmt->bind_param('i', $patient_id);
        $pfStmt->execute();
        $pfRes = $pfStmt->get_result();
        $pfRow = $pfRes ? $pfRes->fetch_assoc() : null;
        $pfStmt->close();
        if ($pfRow) {
            $profiling = [
                'race' => $pfRow['race'] ?? null,
                'occupation' => $pfRow['occupation'] ?? null,
                'medical_history' => $pfRow['medical_history'] ?? null,
                'medications' => $pfRow['medications'] ?? null,
                'allergies' => $pfRow['allergies'] ?? null,
                'smoking' => $pfRow['smoking'] ?? null,
                'alcohol' => $pfRow['alcohol'] ?? null,
                'hypertensive' => (int)($pfRow['is_hypertensive'] ?? 0),
                'diabetic' => (int)($pfRow['is_diabetic'] ?? 0),
                'asthmatic' => (int)($pfRow['is_asthmatic'] ?? 0),
                'heart_disease' => (int)($pfRow['has_heart_disease'] ?? 0),
                'hiv_positive' => (int)($pfRow['is_hiv_positive'] ?? 0),
                'bleeding_disorder' => $pfRow['bleeding_disorder'] ?? null,
                'asa' => $pfRow['asa_classification'] ?? null,
                'pregnancy_status' => $pfRow['pregnancy_status'] ?? null,
                'previous_dental_treatment' => $pfRow['previous_dental_treatment'] ?? null,
                'dental_anxiety' => $pfRow['dental_anxiety'] ?? null,
                'emergency_contact' => $pfRow['emergency_contact'] ?? null,
                'emergency_phone' => $pfRow['emergency_phone'] ?? null,
                'background_notes' => $pfRow['background_notes'] ?? null,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'patient' => [
            'patient_id' => (int)$row['patient_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'middle_name' => $row['middle_name'],
            'date_of_birth' => $row['date_of_birth'],
            'gender' => $row['gender'],
            'phone_number' => $row['phone_number'],
            'email' => $row['email'],
            'address' => $row['address'],
            'profile_image' => $row['profile_image'],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
        ],
        'profiling' => $profiling
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
