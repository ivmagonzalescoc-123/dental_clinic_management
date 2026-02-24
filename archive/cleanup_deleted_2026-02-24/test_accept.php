<?php
// CLI test for accept.php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['role'] = 'doctor';
$_SESSION['user_id'] = 1; // adjust if needed

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['appointment_id'] = isset($argv[1]) ? (int)$argv[1] : 1;

require __DIR__ . '/../assets/api/queue/accept.php';
