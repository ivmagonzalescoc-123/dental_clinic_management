<?php
include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
include '../../../config/capabilities.php';
include_once '../../../config/remember_me.php';

security_start_session();
check_session(['admin', 'secretary', 'doctor']);

$role = strtolower((string)($_SESSION['role'] ?? ''));
$portalPaths = [
    'admin' => '../../../admin/admin.php?page=patients',
    'secretary' => '../../../secretary/secretary.php?page=patients',
    'doctor' => '../../../doctor/doctor.php?page=patients',
];
$redirectUrl = $portalPaths[$role] ?? null;

// The Patients page posts with format=json and shows the password in its own modal;
// a plain form post falls back to the redirect + session modal flow.
$wantsJson = ($_POST['format'] ?? '') === 'json';

function reset_patient_password_fail(string $message, string $type = 'danger', int $status = 400): void
{
    global $wantsJson, $redirectUrl;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header('Location: ' . $redirectUrl);
    exit;
}

if ($redirectUrl === null) {
    http_response_code(403);
    exit;
}

$capability = $role === 'admin' ? 'manage_users' : 'patient.manage';
if ($wantsJson) {
    capabilities_require_json($capability);
} else {
    capabilities_require_web($capability, $redirectUrl);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectUrl);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    reset_patient_password_fail('Please refresh the page and try again.');
}

$patientId = (int)($_POST['patient_id'] ?? $_POST['id'] ?? 0);
if ($patientId <= 0) {
    reset_patient_password_fail('Invalid patient selected for password reset.');
}

$findStmt = $conn->prepare('SELECT patient_id, first_name, last_name, email FROM patients WHERE patient_id = ? LIMIT 1');
if (!$findStmt) {
    reset_patient_password_fail('An error occurred. Please try again.', 'danger', 500);
}
$findStmt->bind_param('i', $patientId);
$findStmt->execute();
$found = $findStmt->get_result();
$targetPatient = $found ? $found->fetch_assoc() : null;
$findStmt->close();

if (!$targetPatient) {
    reset_patient_password_fail('Patient account not found.', 'danger', 404);
}

$patientEmail = trim((string)($targetPatient['email'] ?? ''));
if ($patientEmail === '') {
    reset_patient_password_fail('This patient has no email on file. Add an email before generating a login password.', 'warning');
}

$temporaryPassword = security_generate_default_password();
$temporaryHash = security_hash_password($temporaryPassword);

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
    reset_patient_password_fail('An error occurred. Please try again.', 'danger', 500);
}
$updStmt->bind_param('si', $temporaryHash, $patientId);
$ok = $updStmt->execute();
$updStmt->close();

if (!$ok) {
    reset_patient_password_fail('Failed to reset patient password.', 'danger', 500);
}

remember_me_forget_all($conn, 'patient', (int)$patientId);
$displayName = trim((string)($targetPatient['first_name'] ?? '') . ' ' . (string)($targetPatient['last_name'] ?? ''));

if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Temporary password generated for patient.',
        'password' => $temporaryPassword,
        'account_label' => $displayName,
    ]);
    exit;
}

security_set_generated_password_modal(
    $temporaryPassword,
    $displayName . ' (patient)',
    'Temporary password generated for patient.',
    false
);
header('Location: ' . $redirectUrl);
exit;
