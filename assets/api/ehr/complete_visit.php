<?php
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

header('Content-Type: application/json');

// Verify doctor is logged in
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$serviceId = (int)($_POST['service_id'] ?? 0);
$toothData = isset($_POST['tooth_data']) ? (array)$_POST['tooth_data'] : [];
$treatmentIds = isset($_POST['treatment_ids']) ? (array)$_POST['treatment_ids'] : [];
$notes = $_POST['notes'] ?? '';
$followUpDate = $_POST['follow_up_date'] ?? null;

if ($appointmentId <= 0 || $serviceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$conn->begin_transaction();
try {
    // Verify appointment exists and belongs to this doctor
    $stmt = $conn->prepare('SELECT appointment_id, patient_id, user_id FROM appointments WHERE appointment_id = ? AND user_id = ?');
    if (!$stmt) throw new Exception('Error preparing statement.');
    $stmt->bind_param('ii', $appointmentId, $doctor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    
    if (!$appt) {
        throw new Exception('Appointment not found or access denied.');
    }
    
    $patientId = $appt['patient_id'];
    
    // Update appointment status to completed
    $status = 'completed';
    $stmt = $conn->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?');
    if ($stmt) {
        $stmt->bind_param('ssi', $status, $notes, $appointmentId);
        $stmt->execute();
        $stmt->close();
    }
    
    // Record tooth status for each tooth updated
    foreach ($toothData as $toothId => $toothStatus) {
        if (!empty($toothStatus)) {
            $toothId = (int)$toothId;
            // Insert or update tooth status
            $stmt = $conn->prepare('
                INSERT INTO tooth_status (tooth_selected, status) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ');
            if ($stmt) {
                $stmt->bind_param('is', $toothId, $toothStatus);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    // Update queue status to served
    $queueStatus = 'served';
    $stmt = $conn->prepare('UPDATE check_in_queue SET status = ?, served_time = NOW(), completed_time = NOW() WHERE appointment_id = ?');
    if ($stmt) {
        $stmt->bind_param('si', $queueStatus, $appointmentId);
        $stmt->execute();
        $stmt->close();
    }
    
    // Get service price
    $stmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
    $servicePrice = 0;
    if ($stmt) {
        $stmt->bind_param('i', $serviceId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $servicePrice = (float)($row['initial_price'] ?? 0);
        }
        $stmt->close();
    }
    
    // Insert billing record (consultation fee only)
    $billingAmount = $servicePrice;
    $billingStatus = 'pending';
    $stmt = $conn->prepare('
        INSERT INTO billing (appointment_id, patient_id, amount, status, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ');
    if ($stmt) {
        $stmt->bind_param('iids', $appointmentId, $patientId, $billingAmount, $billingStatus);
        $stmt->execute();
        $stmt->close();
    }
    
    $conn->commit();
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Visit completed successfully',
        'amount' => $billingAmount,
        'redirect' => 'doctor.php?page=appointments'
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
