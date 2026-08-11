<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';

security_start_session();
security_ensure_auth_columns($conn);

$response = ['success' => false, 'message' => 'Registration failed'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$input = security_json_input();
$csrfToken = $input['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$first_name = security_clean_string($input['first_name'] ?? '', 100);
$last_name = security_clean_string($input['last_name'] ?? '', 100);
$middle_name = security_clean_string($input['middle_name'] ?? '', 100);
$email = security_clean_string($input['email'] ?? '', 150);
$date_of_birth = security_clean_string($input['date_of_birth'] ?? '', 20);
$gender = security_clean_string($input['gender'] ?? '', 20);
$phone_number = security_clean_string($input['phone_number'] ?? '', 30);
$address = security_clean_string($input['address'] ?? '', 500);
$password = (string)($input['password'] ?? '');
$confirmPassword = (string)($input['confirm_password'] ?? '');
$termsAccepted = (int)($input['terms_accepted'] ?? 0) === 1;

if ($first_name === '' || $last_name === '' || $email === '' || $phone_number === '') {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (!$termsAccepted) {
    echo json_encode(['success' => false, 'message' => 'You must accept the Terms and Conditions to create an account']);
    exit;
}

if ($password === '' || $confirmPassword === '') {
    echo json_encode(['success' => false, 'message' => 'Password and confirmation are required']);
    exit;
}

if (!hash_equals($password, $confirmPassword)) {
    echo json_encode(['success' => false, 'message' => 'Password and confirmation do not match']);
    exit;
}

$passwordErrors = security_password_policy_errors($password);
if (!empty($passwordErrors)) {
    echo json_encode(['success' => false, 'message' => $passwordErrors[0]]);
    exit;
}

$phoneDigits = preg_replace('/\D+/', '', $phone_number);
if (strlen($phoneDigits) < 4) {
    echo json_encode(['success' => false, 'message' => 'Phone number must contain at least 4 digits']);
    exit;
}

$passwordHash = security_hash_password($password);

$checkStmt = $conn->prepare('SELECT email, phone_number FROM patients WHERE LOWER(email) = LOWER(?) OR phone_number = ? LIMIT 10');
if (!$checkStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$checkStmt->bind_param('ss', $email, $phone_number);
$checkStmt->execute();
$exists = $checkStmt->get_result();

$emailExists = false;
$phoneExists = false;
if ($exists) {
    while ($row = $exists->fetch_assoc()) {
        if (isset($row['email']) && strcasecmp((string)$row['email'], $email) === 0) {
            $emailExists = true;
        }
        if (isset($row['phone_number']) && (string)$row['phone_number'] === $phone_number) {
            $phoneExists = true;
        }
    }
}
$checkStmt->close();

if ($emailExists && $phoneExists) {
    echo json_encode(['success' => false, 'message' => 'Email and phone number are already registered']);
    exit;
}
if ($emailExists) {
    echo json_encode(['success' => false, 'message' => 'Email already exists']);
    exit;
}
if ($phoneExists) {
    echo json_encode(['success' => false, 'message' => 'Phone number already exists']);
    exit;
}

$sql = "INSERT INTO patients
    (first_name, last_name, middle_name, password_hash, email, phone_number, gender, date_of_birth, address, created_at, must_change_password, password_updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, NOW())";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$stmt->bind_param(
    'sssssssss',
    $first_name,
    $last_name,
    $middle_name,
    $passwordHash,
    $email,
    $phone_number,
    $gender,
    $date_of_birth,
    $address
);

if (!$stmt->execute()) {
    error_log('register_api.php: account insert failed: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create your account right now. Please try again.']);
    exit;
}

$patient_id = (int)$stmt->insert_id;
$stmt->close();

session_regenerate_id(true);
$_SESSION['patient_id'] = $patient_id;
$_SESSION['email'] = $email;
$_SESSION['first_name'] = $first_name;
$_SESSION['last_name'] = $last_name;
$_SESSION['role'] = 'patient';
$_SESSION['user_type'] = 'patient';
$_SESSION['must_change_password'] = false;
$_SESSION['last_activity'] = time();

echo json_encode([
    'success' => true,
    'message' => 'Account created successfully. Redirecting to your dashboard...',
    'must_change_password' => false,
    'user' => [
        'patient_id' => $patient_id,
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'role' => 'patient',
    ],
]);
