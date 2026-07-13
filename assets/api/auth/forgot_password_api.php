<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/env.php';
include '../../../config/reminders.php';
include '../../../config/email_templates.php';

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
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$email = security_clean_string($input['email'] ?? '', 150);
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit;
}

function auth_ensure_password_reset_table(mysqli $conn)
{
    $sql = "CREATE TABLE IF NOT EXISTS password_resets (
        reset_id INT AUTO_INCREMENT PRIMARY KEY,
        user_type ENUM('user','patient') NOT NULL,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        otp_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_resets_email (email),
        INDEX idx_resets_token (token_hash),
        INDEX idx_resets_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $conn->query($sql);
}

function auth_lookup_account(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare('SELECT user_id AS id, email, "user" AS user_type FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    $stmt = $conn->prepare('SELECT patient_id AS id, email, "patient" AS user_type FROM patients WHERE LOWER(email) = LOWER(?) LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    return null;
}

$account = auth_lookup_account($conn, $email);
if (!$account) {
    echo json_encode([
        'success' => true,
        'message' => 'If that email exists, reset instructions will be sent shortly.'
    ]);
    exit;
}

auth_ensure_password_reset_table($conn);

$token = bin2hex(random_bytes(32));
$otp = (string)random_int(100000, 999999);
$tokenHash = hash('sha256', $token);
$otpHash = hash('sha256', $otp);
$expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
$createdAt = date('Y-m-d H:i:s');
$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');

$stmt = $conn->prepare('INSERT INTO password_resets (user_type, user_id, email, token_hash, otp_hash, expires_at, used_at, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$stmt->bind_param(
    'sissssss',
    $account['user_type'],
    $account['id'],
    $email,
    $tokenHash,
    $otpHash,
    $expiresAt,
    $ipAddress,
    $createdAt
);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create reset request']);
    exit;
}
$stmt->close();

$emailMode = app_email_mode();
$isDev = strtolower((string)env('APP_ENV', 'development')) === 'development';
if ($emailMode === 'smtp' && !smtp_is_configured() && !$isDev) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => smtp_missing_message()]);
    exit;
} elseif ($emailMode === 'brevo') {
    if (trim((string)env('BREVO_API_KEY', '')) === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Brevo API key is missing. Set BREVO_API_KEY or switch EMAIL_MODE to smtp.']);
        exit;
    }
}

$expiresMinutes = 15;
$subject = 'Password Reset - ' . email_clinic_name();
$message = 'Your password reset code is: ' . $otp . '. It expires in ' . $expiresMinutes . ' minutes.';
$htmlMessage = email_password_reset_otp_html($otp, $expiresMinutes);
$sent = reminder_send_email($email, $subject, $message, $htmlMessage);

// If Brevo API fails (common on free hosts), try SMTP when credentials exist.
if (!$sent && $emailMode === 'brevo' && function_exists('reminder_send_email_smtp')) {
    $smtpHost = trim((string)env('SMTP_HOST', ''));
    $smtpUser = trim((string)env('SMTP_USER', ''));
    $smtpPass = (string)env('SMTP_PASS', '');
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        $from = trim((string)env('BREVO_EMAIL_FROM', env('SMTP_FROM_EMAIL', '')));
        $fromName = trim((string)env('BREVO_EMAIL_FROM_NAME', env('SMTP_FROM_NAME', "Azucena's Dental Clinic")));
        $sent = reminder_send_email_smtp($email, $subject, $message, $from, $fromName, $htmlMessage);
    }
}

if (!$sent) {
    http_response_code(500);
    $errorMessage = 'Unable to send reset email. Check email configuration in .env.';
    if ($emailMode === 'smtp') {
        $errorMessage = 'Unable to send reset email. Check SMTP settings in .env.';
    } elseif ($emailMode === 'brevo') {
        $brevoError = function_exists('reminder_brevo_last_error') ? reminder_brevo_last_error() : '';
        if ($brevoError !== '' && stripos($brevoError, 'unrecognised IP') !== false) {
            $errorMessage = 'Brevo blocked this server IP. Add your InfinityFree server IP in Brevo Authorized IPs, or disable "Block unknown IPs".';
        } elseif ($brevoError !== '' && (stripos($brevoError, 'SSL') !== false || stripos($brevoError, 'certificate') !== false)) {
            $errorMessage = 'Brevo API SSL connection failed on this host. Set BREVO_SSL_VERIFY=false in .env, or use SMTP fallback.';
        } elseif ($brevoError !== '') {
            $errorMessage = 'Unable to send reset email via Brevo API. Check BREVO_API_KEY, BREVO_EMAIL_FROM, and Brevo authorized IPs.';
        } else {
            $errorMessage = 'Unable to send reset email via Brevo API. Verify BREVO_API_KEY and BREVO_EMAIL_FROM in .env.';
        }
    }
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'If that email exists, reset instructions will be sent shortly.'
]);
