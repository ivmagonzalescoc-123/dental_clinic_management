<?php
include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/session_check.php';
security_start_session();
security_ensure_auth_columns($conn);
security_ensure_work_type_column($conn);
check_session(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Security token mismatch. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin/admin.php?page=manage_users&type=users');
        exit;
    }

    $first_name = security_clean_string($_POST['first_name'] ?? '', 100);
    $last_name = security_clean_string($_POST['last_name'] ?? '', 100);
    $email = security_clean_string($_POST['email'] ?? '', 150);
    $role_id = (int)($_POST['role_id'] ?? 0);
    $phone_number_raw = security_clean_string($_POST['phone_number'] ?? '', 30);
    $date_of_birth_raw = security_clean_string($_POST['date_of_birth'] ?? '', 20);
    $gender_raw = security_clean_string($_POST['gender'] ?? '', 20);
    $address_raw = security_clean_string($_POST['address'] ?? '', 500);
    $work_type_raw = security_clean_string($_POST['work_type'] ?? 'regular', 20);
    $specialization_id = (int)($_POST['specialization_id'] ?? 0);

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

    $is_doctor_role = ($doctor_role_id > 0 && $role_id === $doctor_role_id);

    $work_type = 'regular';
    if ($is_doctor_role) {
        $work_type = ($work_type_raw === 'part_time') ? 'part_time' : 'regular';
    }

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($role_id)) {
        $_SESSION['message'] = 'All required fields must be filled!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin/admin.php?page=manage_users&type=users');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = 'Invalid email address.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin/admin.php?page=manage_users&type=users');
        exit;
    }

    if ($is_doctor_role && $specialization_id <= 0) {
        $_SESSION['message'] = 'Please select a specialization for doctor accounts.';
        $_SESSION['message_type'] = 'warning';
        header('Location: ../../../admin/admin.php?page=manage_users&type=users');
        exit;
    }

    if ($is_doctor_role && $specialization_id > 0) {
        $specCheck = $conn->prepare('SELECT specialization_id FROM specialization WHERE specialization_id = ? LIMIT 1');
        if ($specCheck) {
            $specCheck->bind_param('i', $specialization_id);
            $specCheck->execute();
            $specExists = (bool)$specCheck->get_result()->fetch_assoc();
            $specCheck->close();
            if (!$specExists) {
                $_SESSION['message'] = 'Selected specialization is invalid.';
                $_SESSION['message_type'] = 'warning';
                header('Location: ../../../admin/admin.php?page=manage_users&type=users');
                exit;
            }
        }
    }

    // Check if email already exists
    $check_sql = "SELECT user_id FROM users WHERE email = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = 'Email already exists!';
        $_SESSION['message_type'] = 'danger';
        $check_stmt->close();
        header('Location: ../../../admin/admin.php?page=manage_users&type=users');
        exit;
    }
    $check_stmt->close();

    $defaultPassword = security_generate_default_password();
    $passwordHash = security_hash_password($defaultPassword);

    // Insert new user
    $sql = "INSERT INTO users (first_name, last_name, email, password_hash, role_id, work_type, phone_number, date_of_birth, gender, address, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('add_user.php: prepare failed: ' . $conn->error);
        $_SESSION['message'] = 'Database error. Please try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../../admin/admin.php?page=manage_users&type=users');
        exit;
    }

    $stmt->bind_param("ssssisssss", $first_name, $last_name, $email, $passwordHash, $role_id, $work_type, $phone_number, $date_of_birth, $gender, $address);

    $conn->begin_transaction();
    try {
        if (!$stmt->execute()) {
            throw new Exception('Error adding user: ' . $stmt->error);
        }

        $newUserId = (int)$stmt->insert_id;

        if ($is_doctor_role && $specialization_id > 0) {
            $spStmt = $conn->prepare('INSERT INTO dentist_specialization (user_id, specialization_id) VALUES (?, ?)');
            if (!$spStmt) {
                throw new Exception('Error preparing specialization insert: ' . $conn->error);
            }
            $spStmt->bind_param('ii', $newUserId, $specialization_id);
            if (!$spStmt->execute()) {
                $spStmt->close();
                throw new Exception('Error saving specialization: ' . $spStmt->error);
            }
            $spStmt->close();
        }

        $conn->commit();
        security_set_generated_password_modal(
            $defaultPassword,
            trim($first_name . ' ' . $last_name),
            'User added successfully.'
        );
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    $stmt->close();
    $conn->close();
    header('Location: ../../../admin/admin.php?page=manage_users&type=users');
    exit;
}
?>
