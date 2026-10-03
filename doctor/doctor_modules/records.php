<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

// Clinic log of this doctor's completed visits. Everything about a single patient
// (records, odontogram, treatment plan, profiling) lives in My Patients — the
// patient's name links there — so this page stays a plain summary table.
$visitLogDoctorId = (int)($_SESSION['user_id'] ?? 0);
$visitLogPatientLink = 'doctor.php?page=patients&patient_id=%d';
$visitLogSubtitle = 'A log of completed visits: what was availed, treatments done and payments. Click a patient to open their full chart.';
include dirname(dirname(__DIR__)) . '/includes/clinic_visit_log.php';
