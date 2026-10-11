<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
include '../../../config/capabilities.php';
include_once '../../../config/patient_input_helpers.php';

security_start_session();
check_session(['admin', 'secretary', 'doctor']);

$role = strtolower((string)($_SESSION['role'] ?? ''));
// Admin needs user management; secretary and doctor need patient management.
capabilities_require_json($role === 'admin' ? 'manage_users' : 'patient.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
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
    if ($gender === '') {
        $gender = 'N/A'; // keep the column's convention consistent — never store a blank string
    }
    $sql = 'UPDATE patients SET gender=? WHERE patient_id=?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('update_patient.php: prepare failed (gender update): ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit;
    }
    $stmt->bind_param('si', $gender, $patient_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Gender updated successfully.']);
    } else {
        error_log('update_patient.php: gender update failed: ' . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error updating gender. Please try again.']);
    }
    $stmt->close();
    exit;
}

// Full update - validate every field (same rules as Add Patient)
$checked = patient_validate_details($_POST, ['require_dob' => true]);
if (!empty($checked['errors'])) {
    echo json_encode(['success' => false, 'message' => reset($checked['errors']), 'errors' => $checked['errors']]);
    exit;
}
$details = $checked['values'];
$first_name = $details['first_name'];
// "N/A" keeps the columns' convention consistent — never store a blank string
$middle_name = $details['middle_name'] !== '' ? $details['middle_name'] : 'N/A';
$last_name = $details['last_name'];
$email = $details['email'];
$date_of_birth = $details['date_of_birth'];
$gender = $details['gender'] !== '' ? $details['gender'] : 'N/A';
$phone_number = $details['phone_number'];
$address = $details['address'];
$occupation = $details['occupation'] !== '' ? $details['occupation'] : 'N/A';

// Ensure occupation column exists in patients table
$conn->query("ALTER TABLE patients ADD COLUMN IF NOT EXISTS occupation varchar(150) DEFAULT NULL AFTER address");

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
    error_log('update_patient.php: prepare failed (full update): ' . $conn->error);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    exit;
}

$stmt->bind_param('sssssssssi', $first_name, $middle_name, $last_name, $email, $date_of_birth, $gender, $phone_number, $address, $occupation, $patient_id);

if ($stmt->execute()) {
    // phone_number comes back because it is saved in one format (09XXXXXXXXX).
    echo json_encode(['success' => true, 'message' => 'Patient updated successfully.', 'phone_number' => $phone_number]);
} else {
    error_log('update_patient.php: update failed: ' . $stmt->error);
    echo json_encode(['success' => false, 'message' => 'Error updating patient. Please try again.']);
}

$stmt->close();
?>
