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
$notes = $_POST['notes'] ?? '';
$followUpDate = $_POST['follow_up_date'] ?? null;
// Optional billing extras
$materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
$doctor_fee = isset($_POST['doctor_fee']) ? (float)$_POST['doctor_fee'] : 0.0;
$recommended_services = isset($_POST['recommended_services']) ? json_decode($_POST['recommended_services'], true) : null;

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

    // Calculate billing total: service + materials + doctor fee + any recommended services
    $billingAmount = 0.0;
    $billingAmount += $servicePrice;
    if ($materials_used && is_array($materials_used)) {
        foreach ($materials_used as $m) {
            $qty = isset($m['qty']) ? (float)$m['qty'] : 0;
            $price = isset($m['price']) ? (float)$m['price'] : 0;
            $billingAmount += $qty * $price;
        }
    }
    if ($recommended_services && is_array($recommended_services)) {
        foreach ($recommended_services as $sid) {
            $sstmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
            $sstmt->bind_param('i', $sid);
            $sstmt->execute();
            $sres = $sstmt->get_result();
            if ($sres && ($srow = $sres->fetch_assoc())) $billingAmount += (float)$srow['initial_price'];
            $sstmt->close();
        }
    }
    if ($doctor_fee > 0) $billingAmount += $doctor_fee;

    $billingStatus = 'unpaid';
    $chk = $conn->prepare('SELECT billing_id FROM billing WHERE appointment_id = ? LIMIT 1');
    $existingBillingId = 0;
    if ($chk) {
        $chk->bind_param('i', $appointmentId);
        $chk->execute();
        $cres = $chk->get_result();
        $crow = $cres ? $cres->fetch_assoc() : null;
        $existingBillingId = (int)($crow['billing_id'] ?? 0);
        $chk->close();
    }

    if ($existingBillingId > 0) {
        $stmt = $conn->prepare('UPDATE billing SET user_id = ?, total_amount = ?, status = ?, created_at = NOW() WHERE billing_id = ?');
        if ($stmt) {
            $stmt->bind_param('idsi', $patientId, $billingAmount, $billingStatus, $existingBillingId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, status, created_at) VALUES (?, ?, ?, ?, NOW())');
        if ($stmt) {
            $stmt->bind_param('iids', $appointmentId, $patientId, $billingAmount, $billingStatus);
            $stmt->execute();
            $stmt->close();
        }
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
