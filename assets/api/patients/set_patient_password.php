<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
include '../../../config/capabilities.php';

security_start_session();
check_session(['admin', 'secretary']);

$role = strtolower((string)($_SESSION['role'] ?? ''));
if ($role === 'admin') {
    capabilities_require_web('manage_users', '../../admin.php?page=manage_users&type=patients');
} elseif ($role === 'secretary') {
    capabilities_require_web('patient.manage', '../../secretary/secretary.php?page=manage_patients');
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = security_json_input();
$csrf = (string)($input['csrf_token'] ?? '');
if (!security_validate_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$patientId = (int)($input['patient_id'] ?? 0);
$newPassword = (string)($input['new_password'] ?? '');
$confirmPassword = (string)($input['confirm_password'] ?? '');

if ($patientId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient selected.']);
    exit;
}

if ($newPassword === '' || $confirmPassword === '') {
    echo json_encode(['success' => false, 'message' => 'Enter and confirm the new password.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

$policyErrors = security_password_policy_errors($newPassword);
if (!empty($policyErrors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $policyErrors)]);
    exit;
}

$findStmt = $conn->prepare('SELECT patient_id, email FROM patients WHERE patient_id = ? LIMIT 1');
if (!$findStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
$findStmt->bind_param('i', $patientId);
$findStmt->execute();
$found = $findStmt->get_result();
$patient = $found ? $found->fetch_assoc() : null;
$findStmt->close();

if (!$patient) {
    echo json_encode(['success' => false, 'message' => 'Patient not found.']);
    exit;
}

$hash = security_hash_password($newPassword);
$updStmt = $conn->prepare(
    'UPDATE patients
     SET password_hash = ?,
         must_change_password = 0,
         password_updated_at = NOW(),
         failed_login_attempts = 0,
         locked_until = NULL
     WHERE patient_id = ?'
);
if (!$updStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
$updStmt->bind_param('si', $hash, $patientId);
$ok = $updStmt->execute();
$updStmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Unable to update password.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Patient password updated successfully.']);
