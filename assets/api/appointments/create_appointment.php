<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
    $appointment_date = isset($_POST['appointment_date']) ? trim($_POST['appointment_date']) : '';
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'scheduled';

    if (empty($patient_id) || empty($appointment_date) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Patient, doctor and appointment date are required!']);
        exit;
    }

    // Insert appointment (timeslot and branch removed)
    $sql = "INSERT INTO appointments (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare insert failed: ' . $conn->error);
    }

    if (!$stmt->bind_param("ississi", $patient_id, $appointment_date, $reason, $is_online_appointment, $notes, $status, $user_id)) {
        throw new Exception('Bind insert failed: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Insert failed: ' . $stmt->error);
    }

    $appointment_id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Appointment created successfully!', 'appointment_id' => $appointment_id]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
