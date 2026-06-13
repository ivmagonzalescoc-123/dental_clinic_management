<?php
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
security_start_session();
check_session(['admin','secretary','doctor']);
try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
    security_ensure_auth_columns($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        throw new Exception('Invalid security token');
    }

    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? null);
    $gender = trim($_POST['gender'] ?? null);
    $phone_number = trim($_POST['phone_number'] ?? null);
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? null);

    if (empty($first_name) || empty($last_name)) {
        throw new Exception('First name and last name are required');
    }

    if ($email === '') {
        throw new Exception('Email is required to create a patient portal account.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address.');
    }

    // Check by email or phone to avoid duplicate patient records.
    $identifier_sql = 'SELECT patient_id FROM patients WHERE (email = ? AND email != "") OR (phone_number = ? AND phone_number != "") LIMIT 1';
    $identifier_stmt = $conn->prepare($identifier_sql);
    if ($identifier_stmt) {
        $identifier_stmt->bind_param('ss', $email, $phone_number);
        $identifier_stmt->execute();
        $res = $identifier_stmt->get_result();
        if ($res && ($existing = $res->fetch_assoc())) {
            echo json_encode([
                'success' => true,
                'message' => 'Patient already exists',
                'patient_id' => (int)$existing['patient_id'],
                'already_exists' => true,
            ]);
            exit;
        }
        $identifier_stmt->close();
    }

    $added_by = $_SESSION['user_id'] ?? null;

    $generated_password = security_generate_default_password();
    $password_hash = security_hash_password($generated_password);
    $must_change_password = 1;

    $sql = "INSERT INTO patients (first_name, middle_name, last_name, password_hash, date_of_birth, gender, phone_number, email, address, added_by, must_change_password, password_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    if (!$stmt->bind_param('sssssssssii', $first_name, $middle_name, $last_name, $password_hash, $date_of_birth, $gender, $phone_number, $email, $address, $added_by, $must_change_password)) {
        throw new Exception('Bind failed: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $patient_id = (int)$conn->insert_id;
    $stmt->close();

    $patientName = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);
    $subject = 'Your Azucena Dental Clinic Patient Account';
    $message = "Hello {$patientName},\n\n"
        . "Your patient portal account has been created.\n\n"
        . "Login email: {$email}\n"
        . "Temporary password: {$generated_password}\n\n"
        . "Please log in and change your password after your first visit.\n\n"
        . "Azucena's Dental Clinic";

    $emailSent = reminder_send_email($email, $subject, $message);

    $response = [
        'success' => true,
        'message' => $emailSent
            ? 'Patient account created. Login credentials were sent to their email.'
            : 'Patient account created, but the welcome email could not be sent. Check email settings.',
        'patient_id' => $patient_id,
        'email_sent' => $emailSent,
    ];

    if (!$emailSent) {
        $response['email_warning'] = 'Welcome email failed to send. Verify Brevo/SMTP settings in .env.';
    }

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
