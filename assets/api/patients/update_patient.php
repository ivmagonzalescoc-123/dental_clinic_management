<?php
include '../../../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = $_POST['patient_id'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $address = $_POST['address'] ?? '';

    // Validate required fields
    if (empty($patient_id) || empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($date_of_birth)) {
        $_SESSION['message'] = 'All required fields must be filled!';
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=patients');
        exit;
    }

    // Check if username already exists (excluding current patient)
    $check_sql = "SELECT patient_id FROM patients WHERE (username = ? OR email = ?) AND patient_id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ssi", $username, $email, $patient_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = 'Username or email already exists!';
        $_SESSION['message_type'] = 'danger';
        $check_stmt->close();
        header('Location: ../../admin.php?page=manage_users&type=patients');
        exit;
    }
    $check_stmt->close();

    // Update patient
    $sql = "UPDATE patients SET first_name=?, middle_name=?, last_name=?, username=?, email=?, date_of_birth=?, gender=?, phone_number=?, address=? WHERE patient_id=?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['message'] = 'Database error: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
        header('Location: ../../admin.php?page=manage_users&type=patients');
        exit;
    }

    $stmt->bind_param("ssssssssi", $first_name, $middle_name, $last_name, $username, $email, $date_of_birth, $gender, $phone_number, $address, $patient_id);

    if ($stmt->execute()) {
        $_SESSION['message'] = 'Patient updated successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error updating patient: ' . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }

    $stmt->close();
    $conn->close();
    header('Location: ../../admin.php?page=manage_users&type=patients');
    exit;
}
?>
