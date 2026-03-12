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
$password = (string)($input['password'] ?? '');
$date_of_birth = security_clean_string($input['date_of_birth'] ?? '', 20);
$gender = security_clean_string($input['gender'] ?? '', 20);
$phone_number = security_clean_string($input['phone_number'] ?? '', 30);
$address = security_clean_string($input['address'] ?? '', 500);

if ($first_name === '' || $last_name === '' || $email === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

$passwordErrors = security_password_policy_errors($password);
if (!empty($passwordErrors)) {
    echo json_encode(['success' => false, 'message' => $passwordErrors[0]]);
    exit;
}

$passwordHash = security_hash_password($password);

$checkStmt = $conn->prepare('SELECT patient_id FROM patients WHERE email = ? LIMIT 1');
if (!$checkStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$checkStmt->bind_param('s', $email);
$checkStmt->execute();
$exists = $checkStmt->get_result();
if ($exists && $exists->num_rows > 0) {
    $checkStmt->close();
    echo json_encode(['success' => false, 'message' => 'Email already exists']);
    exit;
}
$checkStmt->close();

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
    $err = $stmt->error;
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error creating account: ' . $err]);
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
    'message' => 'Account created successfully',
    'user' => [
        'patient_id' => $patient_id,
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'role' => 'patient',
    ],
]);
?>
