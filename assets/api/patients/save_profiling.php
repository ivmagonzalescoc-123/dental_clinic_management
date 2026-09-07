<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json');
include '../../../config/database.php';
include_once '../../../config/session_check.php';
include_once '../../../config/patient_profiling_helpers.php';
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

    // Use N/A for empty text fields to avoid NULLs in database
    $na = 'N/A';

    // Needed to decide the pregnancy_status exception below (male patients never see
    // that field in the form — see comment further down).
    $patientGender = '';
    $genderStmt = $conn->prepare('SELECT gender FROM patients WHERE patient_id = ? LIMIT 1');
    if ($genderStmt) {
        $genderStmt->bind_param('i', $patient_id);
        $genderStmt->execute();
        $genderRow = $genderStmt->get_result()->fetch_assoc();
        $genderStmt->close();
        $patientGender = (string)($genderRow['gender'] ?? '');
    }

    if (!empty($profiling)) {
        $p_race = $profiling['race'] ?: $na;
        $p_medical_history = $profiling['medical_history'] ?: $na;
        $p_medications = $profiling['medications'] ?: $na;
        $p_allergies = $profiling['allergies'] ?: $na;
        $p_smoking = $profiling['smoking'] ?: $na;
        $p_alcohol = $profiling['alcohol'] ?: $na;
        $p_hypertensive = (int)($profiling['hypertensive'] ?? 0);
        $p_diabetic = (int)($profiling['diabetic'] ?? 0);
        $p_asthmatic = (int)($profiling['asthmatic'] ?? 0);
        $p_heart_disease = (int)($profiling['heart_disease'] ?? 0);
        $p_hiv_positive = (int)($profiling['hiv_positive'] ?? 0);
        $p_bleeding_disorder = $profiling['bleeding_disorder'] ?: $na;
        // Male patients never see the pregnancy field in the form — don't backfill it
        // with a meaningless "N/A" default; NULL correctly represents "not applicable"
        // here (matches the same exception in create_walkin.php).
        $p_pregnancy_status = (strcasecmp($patientGender, 'Male') === 0) ? null : ($profiling['pregnancy_status'] ?: $na);
        $p_previous_dental_treatment = $profiling['previous_dental_treatment'] ?: $na;
        $p_dental_anxiety = $profiling['dental_anxiety'] ?: $na;
        $p_background_notes = $profiling['background_notes'] ?: $na;

        $stmt = $conn->prepare('INSERT INTO patient_profiling (patient_id, race, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, pregnancy_status, previous_dental_treatment, dental_anxiety, background_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new Exception('An error occurred. Please try again.
        $stmt->bind_param(
            'issssssiiiiisssss',
            $patient_id,
            $p_race,
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
            $p_pregnancy_status,
            $p_previous_dental_treatment,
            $p_dental_anxiety,
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
