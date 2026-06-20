<?php
include '../../../config/database.php';
include_once '../../../config/session_check.php';
include_once '../../../config/patient_profiling_helpers.php';
header('Content-Type: application/json');
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','admin','secretary'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}
$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!$data || !isset($data['patient_id'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Invalid payload']);
    exit;
}
$patient_id = (int)$data['patient_id'];
$profiling = isset($data['profiling']) ? $data['profiling'] : null;
try {
    if ($profiling !== null && !is_array($profiling)) {
        throw new Exception('Profiling must be an object');
    }
    $profiling = filter_background_profiling_data(is_array($profiling) ? $profiling : []);

    $conn->query("DELETE FROM patient_profiling WHERE patient_id = $patient_id AND record_id IS NULL");

    if (!empty($profiling)) {
        $p_race = $profiling['race'] ?? null;
        $p_occupation = $profiling['occupation'] ?? null;
        $p_medical_history = $profiling['medical_history'] ?? null;
        $p_medications = $profiling['medications'] ?? null;
        $p_allergies = $profiling['allergies'] ?? null;
        $p_smoking = $profiling['smoking'] ?? null;
        $p_alcohol = $profiling['alcohol'] ?? null;
        $p_hypertensive = (int)($profiling['hypertensive'] ?? 0);
        $p_diabetic = (int)($profiling['diabetic'] ?? 0);
        $p_asthmatic = (int)($profiling['asthmatic'] ?? 0);
        $p_heart_disease = (int)($profiling['heart_disease'] ?? 0);
        $p_hiv_positive = (int)($profiling['hiv_positive'] ?? 0);
        $p_bleeding_disorder = $profiling['bleeding_disorder'] ?? null;
        $p_asa = $profiling['asa'] ?? null;
        $p_pregnancy_status = $profiling['pregnancy_status'] ?? null;
        $p_previous_dental_treatment = $profiling['previous_dental_treatment'] ?? null;
        $p_dental_anxiety = $profiling['dental_anxiety'] ?? null;
        $p_emergency_contact = $profiling['emergency_contact'] ?? null;
        $p_emergency_phone = $profiling['emergency_phone'] ?? null;
        $p_background_notes = $profiling['background_notes'] ?? null;

        $stmt = $conn->prepare('INSERT INTO patient_profiling (patient_id, race, occupation, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, asa_classification, pregnancy_status, previous_dental_treatment, dental_anxiety, emergency_contact, emergency_phone, background_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new Exception('Insert prepare failed: ' . $conn->error);
        $stmt->bind_param(
            'isssssssiiiiissssssss',
            $patient_id,
            $p_race,
            $p_occupation,
            $p_medical_history,
            $p_medications,
            $p_allergies,
            $p_smoking,
            $p_alcohol,
            $p_hypertensive,
            $p_diabetic,
            $p_asthmatic,
            $p_heart_disease,
            $p_hiv_positive,
            $p_bleeding_disorder,
            $p_asa,
            $p_pregnancy_status,
            $p_previous_dental_treatment,
            $p_dental_anxiety,
            $p_emergency_contact,
            $p_emergency_phone,
            $p_background_notes
        );
        if (!$stmt->execute()) throw new Exception('Insert failed: ' . $stmt->error);
        $stmt->close();
    }

    echo json_encode(['success'=>true,'message'=>'Profiling saved']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
