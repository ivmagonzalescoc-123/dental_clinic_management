<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// set session as doctor
$_SESSION['role'] = 'doctor';
$_SESSION['user_id'] = 1; // adjust as needed

// set POST data
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['appointment_id'] = $appt;
$_POST['recommended_services'] = json_encode([1]);
$_POST['treatment_plan'] = json_encode(['plan' => 'E2E CLI finalize']);
$_POST['materials_used'] = json_encode([['name'=>'Test Material','qty'=>1,'price'=>10]]);
$_POST['odontogram'] = json_encode([]);
$_POST['doctor_notes'] = 'E2E CLI finalize test';
// Use same follow_up_date used earlier
$_POST['follow_up_date'] = date('Y-m-d', strtotime('+1 day'));
$_POST['finalize'] = '1';

require __DIR__ . '/../assets/api/ehr/save_clinical_record.php';
