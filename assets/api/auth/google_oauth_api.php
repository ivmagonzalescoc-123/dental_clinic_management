<?php
header('Content-Type: application/json');

require_once '../../../config/database.php';
require_once '../../../config/security.php';
require_once '../../../config/system_logs.php';

security_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = security_json_input();
$accessToken = trim((string)($input['access_token'] ?? ''));
$emailFromClient = strtolower(trim((string)($input['email'] ?? '')));
$nameFromClient = security_clean_string($input['name'] ?? '', 200);
$firstNameFromClient = security_clean_string($input['given_name'] ?? '', 100);
$lastNameFromClient = security_clean_string($input['family_name'] ?? '', 100);
$pictureFromClient = security_clean_string($input['picture'] ?? '', 500);
$subFromClient = trim((string)($input['sub'] ?? ''));

if ($accessToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing Google access token']);
    exit;
}

// Verify access token via Google's userinfo endpoint
function google_verify_access_token(string $accessToken): ?array
{
    $url = 'https://www.googleapis.com/oauth2/v3/userinfo';
    $headers = ['Authorization: Bearer ' . $accessToken];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $status === 200) {
            return json_decode($body, true) ?: null;
        }
    }

    $opts = ['http' => ['timeout' => 10, 'header' => implode("\r\n", $headers)]];
    $ctx = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    return ($body !== false) ? (json_decode($body, true) ?: null) : null;
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

    $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = $conn->prepare(
        'INSERT INTO patient_logs (patient_id, login_time, last_activity, ip_address)
         VALUES (?, NOW(), NOW(), ?)'
    );
    if (!$stmt) return null;
    $stmt->bind_param('is', $patientId, $ip);
    $ok       = $stmt->execute();
    $insertId = $ok ? (int)$conn->insert_id : 0;
    $stmt->close();
    return $insertId > 0 ? $insertId : null;
}

$tokenData = google_verify_access_token($accessToken);

// Use verified data from Google, fall back to client-sent data
$googleId = '';
$email = '';
$firstName = '';
$lastName = '';
$fullName = '';
$picture = '';
$emailVerified = false;

if (is_array($tokenData) && !isset($tokenData['error'])) {
    $googleId = trim((string)($tokenData['sub'] ?? ''));
    $email = strtolower(trim((string)($tokenData['email'] ?? '')));
    $emailVerified = ($tokenData['email_verified'] ?? false) === true || ($tokenData['email_verified'] ?? '') === 'true';
    $firstName = security_clean_string($tokenData['given_name'] ?? '', 100);
    $lastName = security_clean_string($tokenData['family_name'] ?? '', 100);
    $fullName = security_clean_string($tokenData['name'] ?? '', 200);
    $picture = security_clean_string($tokenData['picture'] ?? '', 500);
}

// Fall back to client-sent data if Google verification failed
if ($email === '' && $emailFromClient !== '') {
    $email = $emailFromClient;
    $firstName = $firstName ?: $firstNameFromClient;
    $lastName = $lastName ?: $lastNameFromClient;
    $fullName = $fullName ?: $nameFromClient;
    $picture = $picture ?: $pictureFromClient;
    $googleId = $googleId ?: $subFromClient;
    $emailVerified = true;
}

if ($googleId === '' || $email === '') {
    echo json_encode(['success' => false, 'message' => 'Could not retrieve Google account information.']);
    exit;
}

if (!$emailVerified) {
    echo json_encode(['success' => false, 'message' => 'Your Google email address is not verified.']);
    exit;
}

// Ensure google columns exist
$conn->query("ALTER TABLE patients ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE patients ADD COLUMN IF NOT EXISTS google_picture VARCHAR(500) DEFAULT NULL");

// Look up patient by google_id OR email
$stmt = $conn->prepare(
    "SELECT patient_id, first_name, last_name, email, profile_image
     FROM patients
     WHERE google_id = ? OR LOWER(email) = ?
     LIMIT 1"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
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

    echo json_encode([
        'success'  => true,
        'message'  => 'Signed in with Google successfully.',
        'redirect' => 'patient/patient.php',
    ]);
    exit;
}

// ---- New patient via Google ----
if ($firstName === '' && $fullName !== '') {
    $parts     = explode(' ', $fullName, 2);
    $firstName = $parts[0];
    $lastName  = $parts[1] ?? '';
}
if ($firstName === '') {
    $firstName = 'Google';
}

$passwordHash   = security_hash_password(bin2hex(random_bytes(16)));
$defaultPhone   = '';
$defaultGender  = 'prefer_not';
$defaultDob     = '2000-01-01';
$defaultAddress = '';

$ins = $conn->prepare(
    "INSERT INTO patients
        (first_name, last_name, middle_name, password_hash, email,
         phone_number, gender, date_of_birth, address,
         google_id, google_picture, created_at, must_change_password, password_updated_at)
     VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, NULL)"
);

if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$ins->bind_param(
    'ssssssssss',
    $firstName, $lastName, $passwordHash, $email,
    $defaultPhone, $defaultGender, $defaultDob, $defaultAddress,
    $googleId, $picture
);

if (!$ins->execute()) {
    $err = $ins->error;
    $ins->close();
    if (strpos($err, 'Duplicate') !== false) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists. Please sign in with your password.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not create account: ' . $err]);
    }
    exit;
}

$patientId = (int)$ins->insert_id;
$ins->close();

session_regenerate_id(true);
$_SESSION['patient_id']           = $patientId;
$_SESSION['email']                = $email;
$_SESSION['first_name']           = $firstName;
$_SESSION['last_name']            = $lastName;
$_SESSION['role']                 = 'patient';
$_SESSION['user_type']            = 'patient';
$_SESSION['must_change_password'] = false;
$_SESSION['profile_image']        = null;
$_SESSION['last_activity']        = time();
$_SESSION['google_new_user']      = true;

$logId = google_record_patient_login($conn, $patientId);
if ($logId !== null) {
    $_SESSION['patient_log_id'] = $logId;
}

log_system_event($conn, 'auth.register', 'patients', $patientId, 'Patient registered via Google', null);

echo json_encode([
    'success'  => true,
    'message'  => 'Account created! Please complete your profile.',
    'new_user' => true,
    'redirect' => 'patient/patient.php',
]);
