<?php
/**
 * A patient's transaction ledger (charges, discounts, payments, running balance).
 *   Patient:            their own ledger.
 *   Secretary / admin:  ?patient_id=N
 */
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';

security_start_session();

$role = (string)($_SESSION['role'] ?? '');
if ($role === 'patient' && !empty($_SESSION['patient_id'])) {
    $patientId = (int)$_SESSION['patient_id'];
} elseif (in_array($role, ['secretary', 'admin'], true) && !empty($_SESSION['user_id'])) {
    $patientId = (int)($_GET['patient_id'] ?? 0);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Patient is required.']);
    exit;
}

try {
    billing_ensure_tables($conn);
    $ledger = billing_patient_ledger($conn, $patientId);
    echo json_encode(['success' => true] + $ledger);
} catch (Throwable $e) {
    error_log('get_patient_ledger: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load the ledger.']);
}
