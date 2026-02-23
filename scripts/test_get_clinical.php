<?php
// CLI smoke test for get_clinical_record.php
// Usage: php scripts/test_get_clinical.php

if (session_status() === PHP_SESSION_NONE) session_start();
// set session as doctor
$_SESSION['role'] = 'doctor';
// set GET parameter
$_GET['appointment_id'] = isset($argv[1]) ? (int)$argv[1] : 1;

// include the API (will echo JSON)
require __DIR__ . '/../assets/api/ehr/get_clinical_record.php';
