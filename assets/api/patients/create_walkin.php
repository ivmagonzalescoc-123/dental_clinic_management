<?php
header('Content-Type: application/json');
session_start();
try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? null);
    $gender = trim($_POST['gender'] ?? null);
    $phone_number = trim($_POST['phone_number'] ?? null);
    $email = trim($_POST['email'] ?? null);
    $address = trim($_POST['address'] ?? null);

    if (empty($first_name) || empty($last_name)) {
        throw new Exception('First name and last name are required');
    }

    // If username, email or phone provided, check for existing patient and return existing id to avoid duplicates
    $identifier_sql = 'SELECT patient_id FROM patients WHERE (email = ? AND email != "") OR (phone_number = ? AND phone_number != "") OR (username = ? AND username != "") LIMIT 1';
    $identifier_stmt = $conn->prepare($identifier_sql);
    if ($identifier_stmt) {
        $identifier_stmt->bind_param('sss', $email, $phone_number, $username);
        $identifier_stmt->execute();
        $res = $identifier_stmt->get_result();
        if ($res && ($existing = $res->fetch_assoc())) {
            echo json_encode(['success' => true, 'message' => 'Patient already exists', 'patient_id' => (int)$existing['patient_id']]);
            exit;
        }
        $identifier_stmt->close();
    }

    $added_by = $_SESSION['user_id'] ?? null;

    // If username provided with password, hash the password
    $password_hash = null;
    if (!empty($username)) {
        if (empty($password)) {
            throw new Exception('Password is required when username is provided');
        }
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
    }

    $sql = "INSERT INTO patients (first_name, middle_name, last_name, username, password_hash, date_of_birth, gender, phone_number, email, address, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    if (!$stmt->bind_param('ssssssssssi', $first_name, $middle_name, $last_name, $username, $password_hash, $date_of_birth, $gender, $phone_number, $email, $address, $added_by)) {
        throw new Exception('Bind failed: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $patient_id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Patient created', 'patient_id' => $patient_id]);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>
