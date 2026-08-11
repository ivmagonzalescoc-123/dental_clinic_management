<?php
include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
security_start_session();
security_ensure_auth_columns($conn);
check_session(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Security token mismatch. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin.php?page=manage_users&type=patients');
        exit;
    }

    $first_name = security_clean_string($_POST['first_name'] ?? '', 100);
    $middle_name = security_clean_string($_POST['middle_name'] ?? '', 100);
    $last_name = security_clean_string($_POST['last_name'] ?? '', 100);
    $email = security_clean_string($_POST['email'] ?? '', 150);
    $date_of_birth = security_clean_string($_POST['date_of_birth'] ?? '', 20);
    $gender = security_clean_string($_POST['gender'] ?? '', 20);
    $phone_number = security_clean_string($_POST['phone_number'] ?? '', 30);
    $address = security_clean_string($_POST['address'] ?? '', 500);

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($date_of_birth)) {
        $_SESSION['message'] = 'All required fields must be filled!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin.php?page=manage_users&type=patients');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = 'Invalid email address!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin.php?page=manage_users&type=patients');
        exit;
    }

    $defaultPassword = security_generate_default_password();
    $passwordHash = security_hash_password($defaultPassword);

    // Check if email already exists
    $check_sql = "SELECT patient_id FROM patients WHERE email = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = 'Email already exists!';
        $_SESSION['message_type'] = 'danger';
        $check_stmt->close();
        header('Location: ../../../admin.php?page=manage_users&type=patients');
        exit;
    }
    $check_stmt->close();

    // Insert new patient
        $sql = "INSERT INTO patients (first_name, middle_name, last_name, email, password_hash, date_of_birth, gender, phone_number, address) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('add_patient.php: prepare failed: ' . $conn->error);
        $_SESSION['message'] = 'Database error. Please try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin.php?page=manage_users&type=patients');
        exit;
    }

    $stmt->bind_param("sssssssss", $first_name, $middle_name, $last_name, $email, $passwordHash, $date_of_birth, $gender, $phone_number, $address);

    if ($stmt->execute()) {
        security_set_generated_password_modal(
            $defaultPassword,
            trim($first_name . ' ' . $last_name) . ' (patient)',
            'Patient added successfully.'
        );
    } else {
        error_log('add_patient.php: insert failed: ' . $stmt->error);
        $_SESSION['message'] = 'Error adding patient. Please try again.';
        $_SESSION['message_type'] = 'danger';
    }

    $stmt->close();
    $conn->close();
    header('Location: ../../../admin.php?page=manage_users&type=patients');
    exit;
}
?>
