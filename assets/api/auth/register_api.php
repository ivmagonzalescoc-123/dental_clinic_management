<?php
header('Content-Type: application/json');

include '../../../config/database.php';

$response = ['success' => false, 'message' => 'Registration failed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $first_name = $input['first_name'] ?? '';
    $last_name = $input['last_name'] ?? '';
    $middle_name = $input['middle_name'] ?? '';
    $username = $input['username'] ?? '';
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    $date_of_birth = $input['date_of_birth'] ?? '';
    $gender = $input['gender'] ?? '';
    $phone_number = $input['phone_number'] ?? '';
    $address = $input['address'] ?? '';

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($password)) {
        $response['message'] = 'All required fields must be filled';
        echo json_encode($response);
        exit;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email format';
        echo json_encode($response);
        exit;
    }

    // Check if username already exists
    $checkUsernameStmt = $conn->prepare("SELECT patient_id FROM patients WHERE username = ? LIMIT 1");
    if (!$checkUsernameStmt) {
        $response['message'] = 'Database error';
        echo json_encode($response);
        exit;
    }
    $checkUsernameStmt->bind_param("s", $username);
    $checkUsernameStmt->execute();
    $usernameResult = $checkUsernameStmt->get_result();
    
    if ($usernameResult->num_rows > 0) {
        $checkUsernameStmt->close();
        $response['message'] = 'Username already exists';
        echo json_encode($response);
        exit;
    }
    $checkUsernameStmt->close();

    // Check if email already exists
    $checkEmailStmt = $conn->prepare("SELECT patient_id FROM patients WHERE email = ? LIMIT 1");
    if (!$checkEmailStmt) {
        $response['message'] = 'Database error';
        echo json_encode($response);
        exit;
    }
    $checkEmailStmt->bind_param("s", $email);
    $checkEmailStmt->execute();
    $emailResult = $checkEmailStmt->get_result();
    
    if ($emailResult->num_rows > 0) {
        $checkEmailStmt->close();
        $response['message'] = 'Email already registered';
        echo json_encode($response);
        exit;
    }
    $checkEmailStmt->close();

    // Insert new patient with plain text password
    $sql = "INSERT INTO patients 
            (first_name, last_name, middle_name, username, password_hash, email, phone_number, gender, date_of_birth, address, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $response['message'] = 'Database error';
        echo json_encode($response);
        exit;
    }

    $stmt->bind_param(
        "ssssssssss",
        $first_name,
        $last_name,
        $middle_name,
        $username,
        $password,
        $email,
        $phone_number,
        $gender,
        $date_of_birth,
        $address
    );

    if ($stmt->execute()) {
        $patient_id = $stmt->insert_id;
        $stmt->close();

        // Set session for auto-login
        session_start();
        $_SESSION['patient_id'] = $patient_id;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['role'] = 'patient';
        $_SESSION['user_type'] = 'patient';

        $response['success'] = true;
        $response['message'] = 'Account created successfully';
        $response['user'] = [
            'patient_id' => $patient_id,
            'username' => $username,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'role' => 'patient'
        ];
    } else {
        $response['message'] = 'Error creating account: ' . $stmt->error;
        $stmt->close();
    }

} else {
    $response['message'] = 'Invalid request method';
}

echo json_encode($response);
$conn->close();
?>
