<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Clinic log of every doctor's completed visits: services availed, treatments done and
// payments. A plain summary table (no modal) — same view as the doctor's Clinical Records.
$visitLogDoctorId = null;
$visitLogPatientLink = null;
$visitLogSubtitle = 'A log of completed visits across all doctors: what was availed, treatments done and payments.';
include dirname(dirname(__DIR__)) . '/includes/clinic_visit_log.php';
