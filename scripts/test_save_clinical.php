<?php
// CLI smoke test for save_clinical_record.php
// Usage: php scripts/test_save_clinical.php [appointment_id]

if (session_status() === PHP_SESSION_NONE) session_start();
// set session as doctor
$_SESSION['role'] = 'doctor';
$_SESSION['user_id'] = 1; // adjust as needed for your DB

// set POST data
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['appointment_id'] = $appt;
$_POST['recommended_services'] = json_encode([1]);
$_POST['treatment_plan'] = json_encode(['plan' => 'CLI test']);
$_POST['materials_used'] = json_encode([['name'=>'Test Material','qty'=>1,'price'=>10]]);
$_POST['odontogram'] = '';
$_POST['doctor_notes'] = 'CLI smoke test';
$_POST['follow_up_date'] = '';
$_POST['finalize'] = '0';

// include the API (will echo JSON)
require __DIR__ . '/../assets/api/ehr/save_clinical_record.php';
