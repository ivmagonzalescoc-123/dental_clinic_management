<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';

security_start_session();
security_ensure_auth_columns($conn);

function auth_fail($message = 'Invalid email or password', $code = 401)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function auth_is_locked($lockedUntil)
{
    if (empty($lockedUntil)) {
        return false;
    }
    return strtotime((string)$lockedUntil) > time();
}

function auth_record_failed_attempt(mysqli $conn, $table, $pkCol, $pkVal, $attempts)
{
    $attempts = (int)$attempts + 1;
    if ($attempts >= 5) {
        $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $sql = "UPDATE {$table} SET failed_login_attempts = ?, locked_until = ? WHERE {$pkCol} = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('isi', $attempts, $lockUntil, $pkVal);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $sql = "UPDATE {$table} SET failed_login_attempts = ?, locked_until = NULL WHERE {$pkCol} = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $attempts, $pkVal);
        $stmt->execute();
        $stmt->close();
    }
}

function auth_record_success(mysqli $conn, $table, $pkCol, $pkVal, $newHash = null)
{
    if ($newHash !== null) {
        $now = date('Y-m-d H:i:s');
        $sql = "UPDATE {$table} SET password_hash = ?, password_updated_at = ?, failed_login_attempts = 0, locked_until = NULL, last_login_at = ? WHERE {$pkCol} = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sssi', $newHash, $now, $now, $pkVal);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $now = date('Y-m-d H:i:s');
    $sql = "UPDATE {$table} SET failed_login_attempts = 0, locked_until = NULL, last_login_at = ? WHERE {$pkCol} = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('si', $now, $pkVal);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_fail('Invalid request method', 405);
}

$input = security_json_input();
$csrfToken = $input['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    auth_fail('Security token is invalid. Please refresh and try again.', 403);
}

$email = security_clean_string($input['email'] ?? '', 150);
$password = (string)($input['password'] ?? '');

if ($email === '' || $password === '') {
    auth_fail('Email and password are required', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_fail('Please enter a valid email address', 400);
}

// 1) Authenticate system users
$sql = "SELECT user_id, first_name, last_name, password_hash, email, role_id, is_active,
               failed_login_attempts, locked_until, must_change_password
        FROM users
        WHERE email = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($user) {
        if ((int)($user['is_active'] ?? 0) !== 1) {
            auth_fail('Your account is inactive. Please contact admin.', 403);
        }
        if (auth_is_locked($user['locked_until'] ?? null)) {
            auth_fail('Account temporarily locked due to failed attempts. Try again later.', 423);
        }

        $storedHash = (string)($user['password_hash'] ?? '');
        $verified = false;
        $newHash = null;

        if (security_is_password_hash($storedHash)) {
            $verified = password_verify($password, $storedHash);
            if ($verified && security_needs_rehash($storedHash)) {
                $newHash = security_hash_password($password);
            }
        } else {
            // Legacy plaintext migration path
            $verified = hash_equals($storedHash, $password);
            if ($verified) {
                $newHash = security_hash_password($password);
            }
        }

        if (!$verified) {
            auth_record_failed_attempt($conn, 'users', 'user_id', (int)$user['user_id'], (int)($user['failed_login_attempts'] ?? 0));
            auth_fail();
        }

        auth_record_success($conn, 'users', 'user_id', (int)$user['user_id'], $newHash);

        $roleName = 'admin';
        $roleStmt = $conn->prepare('SELECT role_name FROM role WHERE role_id = ? LIMIT 1');
        if ($roleStmt) {
            $roleStmt->bind_param('i', $user['role_id']);
            $roleStmt->execute();
            $rres = $roleStmt->get_result();
            $rrow = $rres ? $rres->fetch_assoc() : null;
            $roleStmt->close();
            $roleName = $rrow['role_name'] ?? 'admin';
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['role'] = $roleName;
        $_SESSION['user_type'] = 'user';
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0) === 1;
        $_SESSION['last_activity'] = time();

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'must_change_password' => $_SESSION['must_change_password'],
            'user' => [
                'user_id' => (int)$user['user_id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $roleName,
            ],
        ]);
        exit;
    }
}

// 2) Authenticate patients
$psql = "SELECT patient_id, first_name, last_name, password_hash, email,
                failed_login_attempts, locked_until, must_change_password
         FROM patients
         WHERE email = ?
         LIMIT 1";
$pstmt = $conn->prepare($psql);
if (!$pstmt) {
    auth_fail('Database error', 500);
}
$pstmt->bind_param('s', $email);
$pstmt->execute();
$pres = $pstmt->get_result();
$patient = $pres ? $pres->fetch_assoc() : null;
$pstmt->close();

if (!$patient) {
    auth_fail();
}

if (auth_is_locked($patient['locked_until'] ?? null)) {
    auth_fail('Account temporarily locked due to failed attempts. Try again later.', 423);
}

$storedHash = (string)($patient['password_hash'] ?? '');
$verified = false;
$newHash = null;

if (security_is_password_hash($storedHash)) {
    $verified = password_verify($password, $storedHash);
    if ($verified && security_needs_rehash($storedHash)) {
        $newHash = security_hash_password($password);
    }
} else {
    $verified = hash_equals($storedHash, $password);
    if ($verified) {
        $newHash = security_hash_password($password);
    }
}

if (!$verified) {
    auth_record_failed_attempt($conn, 'patients', 'patient_id', (int)$patient['patient_id'], (int)($patient['failed_login_attempts'] ?? 0));
    auth_fail();
}

auth_record_success($conn, 'patients', 'patient_id', (int)$patient['patient_id'], $newHash);

session_regenerate_id(true);
$_SESSION['patient_id'] = (int)$patient['patient_id'];
$_SESSION['email'] = $patient['email'];
$_SESSION['first_name'] = $patient['first_name'];
$_SESSION['last_name'] = $patient['last_name'];
$_SESSION['role'] = 'patient';
$_SESSION['user_type'] = 'patient';
$_SESSION['must_change_password'] = (int)($patient['must_change_password'] ?? 0) === 1;
$_SESSION['last_activity'] = time();

echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'must_change_password' => $_SESSION['must_change_password'],
    'user' => [
        'patient_id' => (int)$patient['patient_id'],
        'email' => $patient['email'],
        'first_name' => $patient['first_name'],
        'last_name' => $patient['last_name'],
        'role' => 'patient',
    ],
]);
exit;
?>
