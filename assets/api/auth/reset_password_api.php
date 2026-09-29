<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include_once '../../../config/rate_limit_helpers.php';

security_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = security_json_input();
$csrfToken = $input['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$email = security_clean_string($input['email'] ?? '', 150);
$otp = security_clean_string($input['otp'] ?? '', 20);
$newPassword = (string)($input['new_password'] ?? '');
$confirmPassword = (string)($input['confirm_password'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit;
}

if ($otp === '') {
    echo json_encode(['success' => false, 'message' => 'OTP code is required']);
    exit;
}

// Cap OTP guesses: a 6-digit code is brute-forceable within its 15-minute lifetime
// without this. The per-email bucket covers attackers rotating IPs.
if (!rate_limit_check($conn, 'password_reset_verify_ip', rate_limit_client_ip(), 20, 900)) {
    rate_limit_reject('Too many attempts from this device. Please try again later.');
}
if (!rate_limit_check($conn, 'password_reset_verify_email', $email, 5, 900)) {
    rate_limit_reject('Too many incorrect codes. Please request a new reset code in 15 minutes.');
}

if ($newPassword === '' || $newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
    exit;
}

$policyErrors = security_password_policy_errors($newPassword);
if (!empty($policyErrors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $policyErrors)]);
    exit;
}

$otpHash = hash('sha256', $otp);

$stmt = $conn->prepare('SELECT reset_id, user_type, user_id, expires_at, used_at, otp_hash FROM password_resets WHERE email = ? ORDER BY reset_id DESC LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    exit;
}

$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$reset = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$reset) {
    echo json_encode(['success' => false, 'message' => 'Reset request not found or expired']);
    exit;
}

if (!empty($reset['used_at'])) {
    echo json_encode(['success' => false, 'message' => 'Reset request already used']);
    exit;
}

if (strtotime((string)$reset['expires_at']) < time()) {
    echo json_encode(['success' => false, 'message' => 'Reset request expired']);
    exit;
}

if (!hash_equals((string)$reset['otp_hash'], $otpHash)) {
    echo json_encode(['success' => false, 'message' => 'Invalid OTP code']);
    exit;
}

$passwordHash = security_hash_password($newPassword);
$now = date('Y-m-d H:i:s');

if ($reset['user_type'] === 'user') {
    $updateStmt = $conn->prepare('UPDATE users SET password_hash = ?, password_updated_at = ?, must_change_password = 0, failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?');
} else {
    $updateStmt = $conn->prepare('UPDATE patients SET password_hash = ?, password_updated_at = ?, must_change_password = 0, failed_login_attempts = 0, locked_until = NULL WHERE patient_id = ?');
}

if (!$updateStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    exit;
}

$updateStmt->bind_param('ssi', $passwordHash, $now, $reset['user_id']);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update password']);
    exit;
}
$updateStmt->close();

$markStmt = $conn->prepare('UPDATE password_resets SET used_at = ? WHERE reset_id = ?');
if ($markStmt) {
    $markStmt->bind_param('si', $now, $reset['reset_id']);
    $markStmt->execute();
    $markStmt->close();
}

echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
?>
