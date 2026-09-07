<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/system_logs.php';
include '../../../config/capabilities.php';

security_start_session();
capabilities_require_json('profile.update_own');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = security_json_input();
$csrfToken = (string)($input['csrf_token'] ?? '');
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$firstName = security_clean_string($input['first_name'] ?? '', 100);
$middleName = security_clean_string($input['middle_name'] ?? '', 100);
$lastName = security_clean_string($input['last_name'] ?? '', 100);
$email = security_clean_string($input['email'] ?? '', 150);
$phone = security_clean_string($input['phone_number'] ?? '', 30);
$dob = security_clean_string($input['date_of_birth'] ?? '', 20);
$gender = security_clean_string($input['gender'] ?? '', 20);
$address = security_clean_string($input['address'] ?? '', 500);

if ($firstName === '' || $lastName === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'First name, last name, and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Date of birth must use YYYY-MM-DD format.']);
    exit;
}

$role = strtolower((string)($_SESSION['role'] ?? ''));
$isPatient = isset($_SESSION['patient_id']) || $role === 'patient';

if ($isPatient) {
    $patientId = (int)($_SESSION['patient_id'] ?? 0);
    if ($patientId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Patient session expired.']);
        exit;
    }

    $check = $conn->prepare('SELECT patient_id FROM patients WHERE email = ? AND patient_id != ? LIMIT 1');
    if (!$check) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to validate email uniqueness.']);
        exit;
    }
    $check->bind_param('si', $email, $patientId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email is already used by another patient.']);
        exit;
    }

    $dobValue = $dob !== '' ? $dob : null;
    $genderValue = $gender !== '' ? $gender : null;
    $phoneValue = $phone !== '' ? $phone : null;
    $addressValue = $address !== '' ? $address : null;

    $stmt = $conn->prepare('UPDATE patients SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone_number = ?, date_of_birth = ?, gender = ?, address = ? WHERE patient_id = ? LIMIT 1');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit;
    }
    $stmt->bind_param('ssssssssi', $firstName, $middleName, $lastName, $email, $phoneValue, $dobValue, $genderValue, $addressValue, $patientId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update patient profile.']);
        exit;
    }

    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name'] = $lastName;
    $_SESSION['email'] = $email;

    log_system_event($conn, 'profile.update', 'patients', $patientId, 'Patient updated own profile', null);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'profile' => [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $email,
            'phone_number' => $phoneValue,
            'date_of_birth' => $dobValue,
            'gender' => $genderValue,
            'address' => $addressValue,
        ],
    ]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User session expired.']);
    exit;
}

$check = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
if (!$check) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to validate email uniqueness.']);
    exit;
}
$check->bind_param('si', $email, $userId);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();

if ($exists) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Email is already used by another user.']);
    exit;
}

$dobValue = $dob !== '' ? $dob : null;
$genderValue = $gender !== '' ? $gender : null;
$phoneValue = $phone !== '' ? $phone : null;
$addressValue = $address !== '' ? $address : null;

$stmt = $conn->prepare('UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone_number = ?, date_of_birth = ?, gender = ?, address = ? WHERE user_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    exit;
}
$stmt->bind_param('ssssssssi', $firstName, $middleName, $lastName, $email, $phoneValue, $dobValue, $genderValue, $addressValue, $userId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update user profile.']);
    exit;
}

$_SESSION['first_name'] = $firstName;
$_SESSION['last_name'] = $lastName;
$_SESSION['email'] = $email;

log_system_event($conn, 'profile.update', 'users', $userId, 'System user updated own profile', $userId);

echo json_encode([
    'success' => true,
    'message' => 'Profile updated successfully.',
    'profile' => [
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'email' => $email,
        'phone_number' => $phoneValue,
        'date_of_birth' => $dobValue,
        'gender' => $genderValue,
        'address' => $addressValue,
    ],
]);
