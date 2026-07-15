<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
include '../../../config/capabilities.php';

security_start_session();
check_session(['admin', 'secretary']);

$role = strtolower((string)($_SESSION['role'] ?? ''));
if ($role === 'admin') {
    capabilities_require_web('manage_users', '../../admin.php?page=manage_users&type=patients');
} elseif ($role === 'secretary') {
    capabilities_require_web('patient.manage', '../../secretary/secretary.php?page=manage_patients');
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$patient_id = (int)($_POST['patient_id'] ?? 0);

if (empty($patient_id)) {
    echo json_encode(['success' => false, 'message' => 'Patient ID is required.']);
    exit;
}

// Check if this is a partial update (only gender or specific fields)
$isPartialUpdate = isset($_POST['gender']) && !isset($_POST['first_name']);

if ($isPartialUpdate) {
    // Partial update - only update provided fields
    $gender = security_clean_string($_POST['gender'] ?? '', 20);
    $sql = 'UPDATE patients SET gender=? WHERE patient_id=?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('si', $gender, $patient_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Gender updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating gender: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

// Full update - validate all required fields
$first_name = security_clean_string($_POST['first_name'] ?? '', 100);
$middle_name = security_clean_string($_POST['middle_name'] ?? '', 100);
$last_name = security_clean_string($_POST['last_name'] ?? '', 100);
$email = security_clean_string($_POST['email'] ?? '', 150);
$date_of_birth = security_clean_string($_POST['date_of_birth'] ?? '', 20);
$gender = security_clean_string($_POST['gender'] ?? '', 20);
$phone_number = security_clean_string($_POST['phone_number'] ?? '', 30);
$address = security_clean_string($_POST['address'] ?? '', 500);
$occupation = security_clean_string($_POST['occupation'] ?? '', 150);

// Ensure occupation column exists in patients table
$conn->query("ALTER TABLE patients ADD COLUMN IF NOT EXISTS occupation varchar(150) DEFAULT NULL AFTER address");

if (empty($first_name) || empty($last_name) || empty($email) || empty($date_of_birth)) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

$check_sql = 'SELECT patient_id FROM patients WHERE email = ? AND patient_id != ?';
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param('si', $email, $patient_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email already exists.']);
    $check_stmt->close();
    exit;
}
$check_stmt->close();

$sql = 'UPDATE patients SET first_name=?, middle_name=?, last_name=?, email=?, date_of_birth=?, gender=?, phone_number=?, address=?, occupation=? WHERE patient_id=?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param('sssssssssi', $first_name, $middle_name, $last_name, $email, $date_of_birth, $gender, $phone_number, $address, $occupation, $patient_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Patient updated successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating patient: ' . $stmt->error]);
}

$stmt->close();
?>
