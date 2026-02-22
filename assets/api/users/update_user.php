<?php
include '../../../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $role_id = $_POST['role_id'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $address = $_POST['address'] ?? '';
    $is_active = $_POST['is_active'] ?? 1;

    // Validate required fields
    if (empty($user_id) || empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($role_id)) {
        $_SESSION['message'] = 'All required fields must be filled!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }

    // Check if username already exists (excluding current user)
    $check_sql = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ssi", $username, $email, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = 'Username or email already exists!';
        $_SESSION['message_type'] = 'danger';
        $check_stmt->close();
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }
    $check_stmt->close();

    // Update user
    $sql = "UPDATE users SET first_name=?, last_name=?, username=?, email=?, role_id=?, phone_number=?, date_of_birth=?, gender=?, address=?, is_active=? WHERE user_id=?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['message'] = 'Database error: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=users');
        exit;
    }

    $stmt->bind_param("sssssisssii", $first_name, $last_name, $username, $email, $role_id, $phone_number, $date_of_birth, $gender, $address, $is_active, $user_id);

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
