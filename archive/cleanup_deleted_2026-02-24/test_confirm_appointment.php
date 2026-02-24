<?php
// CLI test for confirm_appointment.php
// Usage: php scripts/test_confirm_appointment.php

// Simulate POST + session for a secretary approving appointment_id=4
if (php_sapi_name() !== 'cli') {
    echo "This script is intended to be run from CLI.\n";
    exit(1);
}

// ensure superglobals exist
$_SERVER['REQUEST_METHOD'] = 'POST';
session_start();
$_SESSION['role'] = 'secretary';
$_SESSION['user_id'] = 1;
// choose an appointment id present in sample DB
$_POST['appointment_id'] = 4;

require __DIR__ . '/../assets/api/appointments/confirm_appointment.php';

// confirm_appointment.php will output JSON and exit
