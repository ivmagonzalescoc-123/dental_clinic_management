<?php
include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
include '../../../config/capabilities.php';
include_once '../../../config/remember_me.php';

security_start_session();
check_session(['admin', 'secretary']);

$role = strtolower((string)($_SESSION['role'] ?? ''));
if ($role === 'admin') {
    capabilities_require_web('manage_users', '../../../admin.php?page=manage_users&type=patients');
    $redirectUrl = '../../../admin.php?page=manage_users&type=patients';
} elseif ($role === 'secretary') {
    capabilities_require_web('patient.manage', '../../../secretary/secretary.php?page=manage_patients');
    $redirectUrl = '../../../secretary/secretary.php?page=manage_patients';
} else {
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectUrl);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    $_SESSION['message'] = 'Please refresh the page and try again.';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $redirectUrl);
    exit;
}

$patientId = (int)($_POST['patient_id'] ?? $_POST['id'] ?? 0);
if ($patientId <= 0) {
    $_SESSION['message'] = 'Invalid patient selected for password reset.';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $redirectUrl);
    exit;
}

$findStmt = $conn->prepare('SELECT patient_id, first_name, last_name, email FROM patients WHERE patient_id = ? LIMIT 1');
if (!$findStmt) {
    $_SESSION['message'] = 'An error occurred. Please try again.';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $redirectUrl);
    exit;
}
$findStmt->bind_param('i', $patientId);
$findStmt->execute();
$found = $findStmt->get_result();
$targetPatient = $found ? $found->fetch_assoc() : null;
$findStmt->close();

if (!$targetPatient) {
    $_SESSION['message'] = 'Patient account not found.';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $redirectUrl);
    exit;
}

$patientEmail = trim((string)($targetPatient['email'] ?? ''));
if ($patientEmail === '') {
    $_SESSION['message'] = 'This patient has no email on file. Add an email before generating a login password.';
    $_SESSION['message_type'] = 'warning';
    header('Location: ' . $redirectUrl);
    exit;
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
    $_SESSION['message'] = 'An error occurred. Please try again.';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $redirectUrl);
    exit;
}
$updStmt->bind_param('si', $temporaryHash, $patientId);
$ok = $updStmt->execute();
$updStmt->close();
if ($ok) {
    remember_me_forget_all($conn, 'patient', (int)$patientId);
}

if ($ok) {
    $displayName = trim((string)($targetPatient['first_name'] ?? '') . ' ' . (string)($targetPatient['last_name'] ?? ''));
    security_set_generated_password_modal(
        $temporaryPassword,
        $displayName . ' (patient)',
        'Temporary password generated for patient.',
        false
    );
} else {
    $_SESSION['message'] = 'Failed to reset patient password.';
    $_SESSION['message_type'] = 'danger';
}

header('Location: ' . $redirectUrl);
exit;
