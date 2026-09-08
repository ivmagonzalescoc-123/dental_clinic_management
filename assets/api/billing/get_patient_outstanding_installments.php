<?php
/**
 * Outstanding installment terms for a patient, across every billing they have — not scoped
 * to a single appointment. Installment due dates are fixed regardless of whether the patient
 * shows up each month, so this exists to surface "they still owe for an earlier term" at the
 * point of contact (e.g. opening Create Record for a follow-up visit) instead of relying on
 * someone remembering to check the Billing screen separately.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// JSON-only responses: buffer everything and guarantee clean JSON even if a stray PHP
// notice/warning fires (see finish_appointment.php / get_timeslots.php for the same pattern).
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // respect @-suppressed errors
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function ($e) {
    error_log('get_patient_outstanding_installments.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code(500);
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
});

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';

// Discard any warnings/notices buffered while loading the includes above.
if (ob_get_level()) ob_end_clean();

if (!in_array($_SESSION['role'] ?? '', ['secretary', 'admin', 'doctor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
    exit;
}

billing_ensure_tables($conn);

$outstanding = billing_get_patient_outstanding_installments($conn, $patientId);

// Optional: is THIS specific appointment a follow-up visit of an existing installment plan
// (e.g. one of the monthly braces-adjustment visits)? Lets the caller distinguish "patient
// has some unrelated outstanding balance" from "this exact visit is already covered by a
// plan and its next term amount is due now."
$currentVisitInstallment = null;
$appointmentId = (int)($_GET['appointment_id'] ?? 0);
if ($appointmentId > 0) {
    $currentVisitInstallment = billing_get_installment_plan_for_followup($conn, $appointmentId);
}

echo json_encode(['success' => true, 'current_visit_installment' => $currentVisitInstallment] + $outstanding);
