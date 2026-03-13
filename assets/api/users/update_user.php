<?php
include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
security_start_session();
security_ensure_work_type_column($conn);
check_session(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Security token mismatch. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $first_name = security_clean_string($_POST['first_name'] ?? '', 100);
    $last_name = security_clean_string($_POST['last_name'] ?? '', 100);
    $email = security_clean_string($_POST['email'] ?? '', 150);
    $role_id = (int)($_POST['role_id'] ?? 0);
    $phone_number_raw = security_clean_string($_POST['phone_number'] ?? '', 30);
    $date_of_birth_raw = security_clean_string($_POST['date_of_birth'] ?? '', 20);
    $gender_raw = security_clean_string($_POST['gender'] ?? '', 20);
    $address_raw = security_clean_string($_POST['address'] ?? '', 500);
    $work_type_raw = security_clean_string($_POST['work_type'] ?? 'regular', 20);

    $phone_number = $phone_number_raw !== '' ? $phone_number_raw : null;
    $date_of_birth = $date_of_birth_raw !== '' ? $date_of_birth_raw : null;
    $gender = $gender_raw !== '' ? $gender_raw : null;
    $address = $address_raw !== '' ? $address_raw : null;
    $doctor_role_id = 0;
    $roleStmt = $conn->prepare("SELECT role_id FROM role WHERE LOWER(role_name) = 'doctor' LIMIT 1");
    if ($roleStmt) {
        $roleStmt->execute();
        $roleRes = $roleStmt->get_result();
        if ($roleRes && ($roleRow = $roleRes->fetch_assoc())) {
            $doctor_role_id = (int)($roleRow['role_id'] ?? 0);
        }
        $roleStmt->close();
    }

    $work_type = 'regular';
    if ($doctor_role_id > 0 && $role_id === $doctor_role_id) {
        $work_type = ($work_type_raw === 'part_time') ? 'part_time' : 'regular';
    }
    $is_active = (int)($_POST['is_active'] ?? 1);

    // Validate required fields
    if (empty($user_id) || empty($first_name) || empty($last_name) || empty($email) || empty($role_id)) {
        $_SESSION['message'] = 'All required fields must be filled!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = 'Invalid email address!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }

    // Check if email already exists (excluding current user)
    $check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $email, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = 'Email already exists!';
        $_SESSION['message_type'] = 'danger';
        $check_stmt->close();
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }
    $check_stmt->close();

    // Update user
    $sql = "UPDATE users SET first_name=?, last_name=?, email=?, role_id=?, work_type=?, phone_number=?, date_of_birth=?, gender=?, address=?, is_active=? WHERE user_id=?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['message'] = 'Database error: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }

    $stmt->bind_param("sssisssssii", $first_name, $last_name, $email, $role_id, $work_type, $phone_number, $date_of_birth, $gender, $address, $is_active, $user_id);

    if ($stmt->execute()) {
        $_SESSION['message'] = 'User updated successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error updating user: ' . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }

    $stmt->close();
    $conn->close();
    header('Location: ../../admin.php?page=manage_users&type=users');
    exit;
}
?>
