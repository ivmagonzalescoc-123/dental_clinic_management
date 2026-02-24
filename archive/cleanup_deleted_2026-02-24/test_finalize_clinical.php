<?php
// CLI test to finalize clinical record (creates billing)
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['role'] = 'doctor';
$_SESSION['user_id'] = 1; // doctor id

// set POST data
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['appointment_id'] = $appt;
$_POST['recommended_services'] = json_encode([1]);
$_POST['treatment_plan'] = json_encode(['plan' => 'CLI finalize test']);
$_POST['materials_used'] = json_encode([['name'=>'Material A','qty'=>2,'price'=>50]]);
$_POST['odontogram'] = '';
$_POST['doctor_notes'] = 'Finalize via CLI';
$_POST['follow_up_date'] = '';
$_POST['finalize'] = '1';

require __DIR__ . '/../assets/api/ehr/save_clinical_record.php';
