<?php
header('Content-Type: application/json');

require_once '../../../config/database.php';
require_once '../../../config/security.php';
require_once '../../../config/system_logs.php';
require_once '../../../config/login_activity_helpers.php';
require_once '../../../config/remember_me.php';

security_start_session();

// The `patients` table never had google_id/google_picture columns added, so the lookup
// query below failed outright (Unknown column) and surfaced as a raw 500 on every Google
// sign-in attempt, regardless of whether the email matched anything. Self-heal them here,
// matching the ALTER-TABLE-IF-NOT-EXISTS pattern used elsewhere in this app.
function google_oauth_ensure_columns(mysqli $conn): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $conn->query("ALTER TABLE patients ADD COLUMN IF NOT EXISTS google_id VARCHAR(64) NULL DEFAULT NULL AFTER password_hash");
    $conn->query("ALTER TABLE patients ADD COLUMN IF NOT EXISTS google_picture VARCHAR(500) NULL DEFAULT NULL AFTER google_id");
    $idxCheck = $conn->query("SHOW INDEX FROM patients WHERE Key_name = 'uq_patients_google_id'");
    if ($idxCheck && $idxCheck->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD UNIQUE INDEX uq_patients_google_id (google_id)");
    }
}
google_oauth_ensure_columns($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = security_json_input();
$accessToken = trim((string)($input['access_token'] ?? ''));
$rememberMe = !empty($input['remember']);
// Identity (email, sub) is taken ONLY from Google's verified response below - never
// from the request body, which the browser (or an attacker) fully controls.

if ($accessToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing Google access token']);
    exit;
}

/**
 * GET a Google endpoint and decode the JSON body.
 * Returns ['status' => int, 'data' => ?array, 'error' => string].
 */
function google_http_get_json(string $url, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body !== false) {
            return ['status' => $status, 'data' => json_decode((string)$body, true) ?: null, 'error' => ''];
        }
        // cURL itself failed (DNS, TLS, blocked outbound) - try streams before giving up.
        $curlError = $err;
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['status' => 0, 'data' => null, 'error' => $curlError ?? 'request failed'];
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    return ['status' => $status, 'data' => json_decode($body, true) ?: null, 'error' => ''];
}

/**
 * Verify the access token with Google and return the signed-in identity, or null.
 * tokeninfo proves the token is genuine, unexpired and was issued to THIS app's
 * client ID (so a token obtained by some other site can't be replayed here);
 * userinfo then supplies the profile fields.
 */
function google_verify_access_token(string $accessToken, string $clientId, ?string &$reason = null): ?array
{
    $info = google_http_get_json('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($accessToken));
    if ($info['status'] === 0) {
        $reason = 'Could not reach Google to verify sign-in (' . $info['error'] . ')';
        return null;
    }
    $tok = $info['data'];
    if ($info['status'] !== 200 || !is_array($tok) || isset($tok['error']) || isset($tok['error_description'])) {
        $reason = 'Google rejected the sign-in token';
        return null;
    }
    $aud = (string)($tok['aud'] ?? $tok['azp'] ?? '');
    if ($clientId === '' || !hash_equals($clientId, $aud)) {
        $reason = 'Sign-in token was not issued for this site';
        return null;
    }

    $sub = trim((string)($tok['sub'] ?? ''));
    $email = strtolower(trim((string)($tok['email'] ?? '')));
    $verified = ($tok['email_verified'] ?? false) === true || ($tok['email_verified'] ?? '') === 'true';

    $profile = google_http_get_json('https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $accessToken]);
    $p = ($profile['status'] === 200 && is_array($profile['data'])) ? $profile['data'] : [];
    // Profile must describe the same account the token belongs to.
    if ($p && trim((string)($p['sub'] ?? '')) !== $sub) {
        $p = [];
    }

    return [
        'sub'            => $sub,
        'email'          => $email,
        'email_verified' => $verified,
        'picture'        => security_clean_string($p['picture'] ?? '', 500),
    ];
}

// Record a patient login row
function google_record_patient_login(mysqli $conn, int $patientId): ?int
{
    $check = $conn->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'patient_logs'"
    );
    if (!$check) return null;
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if ((int)($row['c'] ?? 0) === 0) return null;

    if (function_exists('login_activity_ensure_columns')) {
        login_activity_ensure_columns($conn);
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt = $conn->prepare(
        'INSERT INTO patient_logs (patient_id, login_time, last_activity, ip_address, user_agent)
         VALUES (?, NOW(), NOW(), ?, ?)'
    );
    if (!$stmt) return null;
    $stmt->bind_param('iss', $patientId, $ip, $userAgent);
    $ok       = $stmt->execute();
    $insertId = $ok ? (int)$conn->insert_id : 0;
    $stmt->close();
    return $insertId > 0 ? $insertId : null;
}

// No fallback to browser-sent data: if Google can't confirm the token, sign-in fails.
// (A fallback here let anyone log in as any patient by posting their email.)
$verifyReason = '';
$identity = google_verify_access_token($accessToken, (string)env('GOOGLE_CLIENT_ID', ''), $verifyReason);
if ($identity === null) {
    error_log('Google sign-in verification failed: ' . $verifyReason);
    // The reason is safe to show (no account data) and tells staff what to fix.
    echo json_encode(['success' => false, 'message' => 'We could not verify your Google sign-in: ' . $verifyReason . '.']);
    exit;
}

$googleId = $identity['sub'];
$email = $identity['email'];
$emailVerified = $identity['email_verified'];
$picture = $identity['picture'];

if ($googleId === '' || $email === '') {
    echo json_encode(['success' => false, 'message' => 'Could not retrieve your Google account information. Please try again or contact the clinic.']);
    exit;
}

if (!$emailVerified) {
    echo json_encode(['success' => false, 'message' => 'Your Google email address is not verified.']);
    exit;
}

// Look up patient by google_id OR email
$stmt = $conn->prepare(
    "SELECT patient_id, first_name, last_name, email, profile_image
     FROM patients
     WHERE google_id = ? OR LOWER(email) = ?
     LIMIT 1"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to verify your account. Contact the clinic.']);
    exit;
}
$stmt->bind_param('ss', $googleId, $email);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($patient) {
    // Existing patient — log in
    $patientId = (int)$patient['patient_id'];

    $upd = $conn->prepare(
        "UPDATE patients
         SET google_id       = IF(google_id IS NULL OR google_id = '', ?, google_id),
             google_picture  = ?,
             last_login_at   = NOW(),
             failed_login_attempts = 0,
             locked_until    = NULL
         WHERE patient_id = ?"
    );
    if ($upd) {
        $upd->bind_param('ssi', $googleId, $picture, $patientId);
        $upd->execute();
        $upd->close();
    }

    session_regenerate_id(true);
    $_SESSION['patient_id']           = $patientId;
    $_SESSION['email']                = $patient['email'];
    $_SESSION['first_name']           = $patient['first_name'];
    $_SESSION['last_name']            = $patient['last_name'];
    $_SESSION['role']                 = 'patient';
    $_SESSION['user_type']            = 'patient';
    $_SESSION['must_change_password'] = false;
    $_SESSION['profile_image']        = $patient['profile_image'] ?? null;
    $_SESSION['last_activity']        = time();

    $logId = google_record_patient_login($conn, $patientId);
    if ($logId !== null) {
        $_SESSION['patient_log_id'] = $logId;
    }

    log_system_event($conn, 'auth.login', 'patients', $patientId, 'Patient Google login', null);
    if ($rememberMe) {
        remember_me_issue($conn, 'patient', $patientId);
    }

    echo json_encode([
        'success'  => true,
        'message'  => 'Signed in with Google successfully.',
        'redirect' => 'patient/patient.php',
    ]);
    exit;
}

// ---- No matching patient — check whether this email belongs to a staff account instead ----
$staffStmt = $conn->prepare('SELECT user_id FROM users WHERE LOWER(email) = ? LIMIT 1');
if ($staffStmt) {
    $staffStmt->bind_param('s', $email);
    $staffStmt->execute();
    $isStaffEmail = (bool)$staffStmt->get_result()->fetch_assoc();
    $staffStmt->close();
    if ($isStaffEmail) {
        echo json_encode([
            'success' => false,
            'message' => 'This email is registered as a staff account. Please use the staff login instead of Google sign-in.',
        ]);
        exit;
    }
}

echo json_encode([
    'success' => false,
    'message' => 'No account found for this email. Please visit the clinic for appointment and account creation, or use the Book Appointment form for first-time visits.',
]);
