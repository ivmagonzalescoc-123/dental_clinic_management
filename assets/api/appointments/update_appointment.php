<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include __DIR__ . '/availability_guard.php';
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $appointment_date = isset($_POST['appointment_date']) ? trim($_POST['appointment_date']) : '';
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'scheduled';

    if (empty($appointment_id) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID and doctor are required!']);
        exit;
    }

    if (!appt_validate_appointment_date($appointment_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment date. Use YYYY-MM-DD format.']);
        exit;
    }

    [$reasonOk, $reasonResult] = appt_validate_reason($reason);
    if (!$reasonOk) {
        echo json_encode(['success' => false, 'message' => $reasonResult]);
        exit;
    }
    $reason = $reasonResult;

    [$notesOk, $notesResult] = appt_validate_notes($notes);
    if (!$notesOk) {
        echo json_encode(['success' => false, 'message' => $notesResult]);
        exit;
    }
    $notes = $notesResult;

    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $user_id, $appointment_date);
    if (!$bookable) {
        echo json_encode(['success' => false, 'message' => $bookableMsg]);
        exit;
    }

    // Update the appointment
    $update_sql = "UPDATE appointments SET user_id = ?, appointment_date = ?, reason = ?, is_online_appointment = ?, notes = ?, status = ? WHERE appointment_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    
    if (!$update_stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    if (!$update_stmt->bind_param("issiisi", $user_id, $appointment_date, $reason, $is_online_appointment, $notes, $status, $appointment_id)) {
        throw new Exception('Bind failed: ' . $update_stmt->error);
    }
    
    if (!$update_stmt->execute()) {
        throw new Exception('Execute failed: ' . $update_stmt->error);
    }
    
    $update_stmt->close();

    echo json_encode(['success' => true, 'message' => 'Appointment updated successfully!']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
