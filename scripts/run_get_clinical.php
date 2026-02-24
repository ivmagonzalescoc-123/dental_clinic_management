<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// set session as doctor
$_SESSION['role'] = 'doctor';

// build GET
$appt = isset($argv[1]) ? (int)$argv[1] : 1;
$_GET['appointment_id'] = $appt;

require __DIR__ . '/../assets/api/ehr/get_clinical_record.php';
