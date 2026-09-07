<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/system_logs.php';
include_once '../../../config/login_activity_helpers.php';

security_start_session();
security_ensure_auth_columns($conn);

function auth_generate_login_captcha_payload(): array
{
    $left = random_int(2, 12);
    $right = random_int(1, 9);
    $useSubtraction = (bool)random_int(0, 1);
    if ($useSubtraction && $right > $left) {
        $tmp = $left;
        $left = $right;
        $right = $tmp;
    }

    $operator = $useSubtraction ? '-' : '+';
    $answer = $useSubtraction ? ($left - $right) : ($left + $right);

    $_SESSION['login_captcha_answer'] = (string)$answer;
    $_SESSION['login_captcha_expires_at'] = time() + 300;

    return [
        'question' => $left . ' ' . $operator . ' ' . $right,
        'expires_in' => 300,
    ];
}

function auth_fail($message = 'Invalid email or password', $code = 401, $regenerateCaptcha = false)
{
    http_response_code($code);
    $payload = [
        'success' => false,
        'message' => $message,
    ];
    if ($regenerateCaptcha) {
        $payload['captcha'] = auth_generate_login_captcha_payload();
    }
    echo json_encode($payload);
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

function auth_table_exists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function auth_record_user_login(mysqli $conn, int $userId): ?int
{
    if (!auth_table_exists($conn, 'user_logs')) {
        return null;
    }

    if (function_exists('login_activity_ensure_columns')) {
        login_activity_ensure_columns($conn);
    }

    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt = $conn->prepare('INSERT INTO user_logs (user_id, login_time, last_activity, ip_address, user_agent) VALUES (?, NOW(), NOW(), ?, ?)');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iss', $userId, $ipAddress, $userAgent);
    $ok = $stmt->execute();
    $insertId = $ok ? (int)$conn->insert_id : 0;
    $stmt->close();

    return $insertId > 0 ? $insertId : null;
}

function auth_record_patient_login(mysqli $conn, int $patientId): ?int
{
    if (!auth_table_exists($conn, 'patient_logs')) {
        return null;
    }

    if (function_exists('login_activity_ensure_columns')) {
        login_activity_ensure_columns($conn);
    }

    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt = $conn->prepare('INSERT INTO patient_logs (patient_id, login_time, last_activity, ip_address, user_agent) VALUES (?, NOW(), NOW(), ?, ?)');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iss', $patientId, $ipAddress, $userAgent);
    $ok = $stmt->execute();
    $insertId = $ok ? (int)$conn->insert_id : 0;
    $stmt->close();

    return $insertId > 0 ? $insertId : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_fail('Invalid request method', 405);
}

$input = security_json_input();
$csrfToken = $input['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    auth_fail('Security token is invalid. Please refresh and try again.', 403);
}

$captchaAnswer = trim((string)($input['captcha_answer'] ?? ''));
$captchaExpected = (string)($_SESSION['login_captcha_answer'] ?? '');
$captchaExpiresAt = (int)($_SESSION['login_captcha_expires_at'] ?? 0);

if ($captchaAnswer === '' || $captchaExpected === '' || ($captchaExpiresAt > 0 && time() > $captchaExpiresAt) || !hash_equals($captchaExpected, $captchaAnswer)) {
    auth_fail('Captcha verification failed. Please try again.', 400, true);
}

unset($_SESSION['login_captcha_answer'], $_SESSION['login_captcha_expires_at']);

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
               failed_login_attempts, locked_until, must_change_password, profile_image
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
        $_SESSION['profile_image'] = $user['profile_image'] ?? null;
        $_SESSION['last_activity'] = time();

        $userLogId = auth_record_user_login($conn, (int)$user['user_id']);
        if ($userLogId !== null) {
            $_SESSION['user_log_id'] = $userLogId;
        }
        log_system_event($conn, 'auth.login', 'users', (int)$user['user_id'], 'User login successful', (int)$user['user_id']);

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
                failed_login_attempts, locked_until, must_change_password, profile_image
         FROM patients
         WHERE email = ?
         LIMIT 1";
$pstmt = $conn->prepare($psql);
if (!$pstmt) {
    auth_fail('An error occurred. Please try again.', 500);
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
$_SESSION['must_change_password'] = false;
$_SESSION['profile_image'] = $patient['profile_image'] ?? null;
$_SESSION['last_activity'] = time();

$patientLogId = auth_record_patient_login($conn, (int)$patient['patient_id']);
if ($patientLogId !== null) {
    $_SESSION['patient_log_id'] = $patientLogId;
}

log_system_event($conn, 'auth.login', 'patients', (int)$patient['patient_id'], 'Patient login successful', null);

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
