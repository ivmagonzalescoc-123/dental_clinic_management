<?php
include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
include '../../../config/capabilities.php';

security_start_session();
check_session(['admin', 'secretary']);

$role = strtolower((string)($_SESSION['role'] ?? ''));
if ($role === 'admin') {
    capabilities_require_web('manage_users', '../../admin.php?page=manage_users&type=patients');
    $redirectUrl = '../../admin.php?page=manage_users&type=patients';
} elseif ($role === 'secretary') {
    capabilities_require_web('patient.manage', '../../secretary/secretary.php?page=manage_patients');
    $redirectUrl = '../../secretary/secretary.php?page=manage_patients';
} else {
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Security token mismatch. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $first_name = security_clean_string($_POST['first_name'] ?? '', 100);
    $middle_name = security_clean_string($_POST['middle_name'] ?? '', 100);
    $last_name = security_clean_string($_POST['last_name'] ?? '', 100);
    $email = security_clean_string($_POST['email'] ?? '', 150);
    $date_of_birth = security_clean_string($_POST['date_of_birth'] ?? '', 20);
    $gender = security_clean_string($_POST['gender'] ?? '', 20);
    $phone_number = security_clean_string($_POST['phone_number'] ?? '', 30);
    $address = security_clean_string($_POST['address'] ?? '', 500);

    if (empty($patient_id) || empty($first_name) || empty($last_name) || empty($email) || empty($date_of_birth)) {
        $_SESSION['message'] = 'All required fields must be filled!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = 'Invalid email address!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $check_sql = 'SELECT patient_id FROM patients WHERE email = ? AND patient_id != ?';
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('si', $email, $patient_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = 'Email already exists!';
        $_SESSION['message_type'] = 'danger';
        $check_stmt->close();
        header('Location: ' . $redirectUrl);
        exit;
    }
    $check_stmt->close();

    $sql = 'UPDATE patients SET first_name=?, middle_name=?, last_name=?, email=?, date_of_birth=?, gender=?, phone_number=?, address=? WHERE patient_id=?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['message'] = 'Database error: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $stmt->bind_param('ssssssssi', $first_name, $middle_name, $last_name, $email, $date_of_birth, $gender, $phone_number, $address, $patient_id);

    if ($stmt->execute()) {
        $_SESSION['message'] = 'Patient updated successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error updating patient: ' . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }

    $stmt->close();
    $conn->close();
    header('Location: ' . $redirectUrl);
    exit;
}
?>
