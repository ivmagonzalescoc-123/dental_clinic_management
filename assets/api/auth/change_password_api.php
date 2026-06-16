<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';

security_start_session();
security_ensure_auth_columns($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = security_json_input();
$csrfToken = $input['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$currentPassword  = (string)($input['current_password'] ?? '');
$newPassword      = (string)($input['new_password'] ?? '');
$confirmPassword  = (string)($input['confirm_password'] ?? '');
$clientSaysGoogle = !empty($input['is_google_account']);

if ($newPassword === '' || $confirmPassword === '') {
    echo json_encode(['success' => false, 'message' => 'New password fields are required']);
    exit;
}

if (!hash_equals($newPassword, $confirmPassword)) {
    echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match']);
    exit;
}

$passwordErrors = security_password_policy_errors($newPassword);
if (!empty($passwordErrors)) {
    echo json_encode(['success' => false, 'message' => $passwordErrors[0]]);
    exit;
}

$isPatient = isset($_SESSION['patient_id']) || (($_SESSION['role'] ?? '') === 'patient');
if ($isPatient) {
    $patientId = (int)($_SESSION['patient_id'] ?? 0);
    if ($patientId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $stmt = $conn->prepare('SELECT password_hash, google_id, password_updated_at FROM patients WHERE patient_id = ? LIMIT 1');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Account not found']);
        exit;
    }

    // Skip current password only for Google accounts that have never set their own password
    $isGoogleAccount = !empty($row['google_id']) && $row['password_updated_at'] === null;
    if (!$isGoogleAccount) {
        if ($currentPassword === '') {
            echo json_encode(['success' => false, 'message' => 'Current password is required']);
            exit;
        }
        $stored = (string)($row['password_hash'] ?? '');
        $ok = false;
        if (security_is_password_hash($stored)) {
            $ok = password_verify($currentPassword, $stored);
        } else {
            $ok = hash_equals($stored, $currentPassword);
        }
        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit;
        }
    }

    $newHash = security_hash_password($newPassword);
    $upd = $conn->prepare('UPDATE patients SET password_hash = ?, must_change_password = 0, password_updated_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE patient_id = ?');
    if (!$upd) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
    $upd->bind_param('si', $newHash, $patientId);
    $upd->execute();
    $upd->close();

    $_SESSION['must_change_password'] = false;
    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Account not found']);
    exit;
}

$stored = (string)($row['password_hash'] ?? '');
$ok = false;
if (security_is_password_hash($stored)) {
    $ok = password_verify($currentPassword, $stored);
} else {
    $ok = hash_equals($stored, $currentPassword);
}

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
    exit;
}

$newHash = security_hash_password($newPassword);
$upd = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, password_updated_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?');
if (!$upd) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$upd->bind_param('si', $newHash, $userId);
$upd->execute();
$upd->close();

$_SESSION['must_change_password'] = false;
echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
?>
