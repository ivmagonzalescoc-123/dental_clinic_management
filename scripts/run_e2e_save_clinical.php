<?php
// CLI E2E test runner for save_clinical_record.php
// Usage: php scripts/run_e2e_save_clinical.php [appointment_id]

if (session_status() === PHP_SESSION_NONE) session_start();
// set session as doctor
$_SESSION['role'] = 'doctor';
$_SESSION['user_id'] = 1; // adjust as needed for your DB

// set POST data
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['appointment_id'] = $appt;
// try to use service id 1; adjust if your services differ
$_POST['recommended_services'] = json_encode([1]);
$_POST['treatment_plan'] = json_encode(['plan' => 'E2E CLI test']);
$_POST['materials_used'] = json_encode([['name'=>'Test Material','qty'=>1,'price'=>10]]);
$_POST['odontogram'] = json_encode([]);
$_POST['doctor_notes'] = 'E2E CLI smoke test';
// set follow_up_date to tomorrow to attempt schedule creation
$_POST['follow_up_date'] = date('Y-m-d', strtotime('+1 day'));
$_POST['finalize'] = '0';

// include the API (will echo JSON)
require __DIR__ . '/../assets/api/ehr/save_clinical_record.php';
