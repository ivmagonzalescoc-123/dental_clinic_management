<?php
header('Content-Type: application/json');
session_start();

include '../../../config/database.php';

$response = ['success' => false, 'message' => 'Invalid credentials'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username_or_email = $input['username_or_email'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($username_or_email) || empty($password)) {
        $response['message'] = 'Username/Email and password are required';
        echo json_encode($response);
        exit;
    }

    // First, try to authenticate from users table (admin, dentist, secretary)
    $sql = "SELECT user_id, first_name, last_name, username, password_hash, email, role_id FROM users 
            WHERE (username = ? OR email = ?) LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $response['message'] = 'Database error';
        echo json_encode($response);
        exit;
    }

    $stmt->bind_param("ss", $username_or_email, $username_or_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stmt->close();

        // Check plain text password
        if ($user['password_hash'] === $password) {
            // Get role name
            $roleStmt = $conn->prepare("SELECT role_name FROM role WHERE role_id = ?");
            $roleStmt->bind_param("i", $user['role_id']);
            $roleStmt->execute();
            $roleResult = $roleStmt->get_result();
            $roleRow = $roleResult->fetch_assoc();
            $roleStmt->close();

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['role'] = $roleRow['role_name'] ?? 'admin';
            $_SESSION['user_type'] = 'user';

            $response['success'] = true;
            $response['message'] = 'Login successful';
            $response['user'] = [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $roleRow['role_name'] ?? 'admin'
            ];
            echo json_encode($response);
            exit;
        } else {
            $response['message'] = 'Invalid username/email or password';
            echo json_encode($response);
            exit;
        }
    }

    // If not found in users, try patients table
    $patientSql = "SELECT patient_id, first_name, last_name, username, password_hash, email FROM patients 
                   WHERE (username = ? OR email = ?) LIMIT 1";
    
    $patientStmt = $conn->prepare($patientSql);
    if (!$patientStmt) {
        $response['message'] = 'Database error';
        echo json_encode($response);
        exit;
    }

    $patientStmt->bind_param("ss", $username_or_email, $username_or_email);
    $patientStmt->execute();
    $patientResult = $patientStmt->get_result();

    if ($patientResult->num_rows > 0) {
        $patient = $patientResult->fetch_assoc();
        $patientStmt->close();

        // Check plain text password
        if ($patient['password_hash'] === $password) {
            $_SESSION['patient_id'] = $patient['patient_id'];
            $_SESSION['username'] = $patient['username'];
            $_SESSION['email'] = $patient['email'];
            $_SESSION['first_name'] = $patient['first_name'];
            $_SESSION['last_name'] = $patient['last_name'];
            $_SESSION['role'] = 'patient';
            $_SESSION['user_type'] = 'patient';

            $response['success'] = true;
            $response['message'] = 'Login successful';
            $response['user'] = [
                'patient_id' => $patient['patient_id'],
                'username' => $patient['username'],
                'first_name' => $patient['first_name'],
                'last_name' => $patient['last_name'],
                'role' => 'patient'
            ];
            echo json_encode($response);
            exit;
        } else {
            $response['message'] = 'Invalid username/email or password';
            echo json_encode($response);
            exit;
        }
    }

    $patientStmt->close();
    $response['message'] = 'Invalid username/email or password';

} else {
    $response['message'] = 'Invalid request method';
}

echo json_encode($response);
$conn->close();
?>
