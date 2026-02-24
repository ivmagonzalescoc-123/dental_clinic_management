<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// set session as secretary
$_SESSION['role'] = 'secretary';

// set POST data
$sid = isset($argv[1]) ? (int)$argv[1] : 11; // default to schedule_id 11 from previous test
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['schedule_id'] = $sid;

require __DIR__ . '/../assets/api/appointments/mark_schedule_arrived.php';
