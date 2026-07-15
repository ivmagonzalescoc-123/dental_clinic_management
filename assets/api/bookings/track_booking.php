<?php
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if ($bookingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid booking number.']);
    exit;
}

$stmt = $conn->prepare('SELECT booking_id, first_name, last_name, email, phone_number, preferred_date, preferred_time, reason, status, created_at FROM bookings WHERE booking_id = ?');
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Booking not found. Please check your booking number.']);
    exit;
}

// Determine steps: 1=booking created, 2=arrived at clinic, 3=appointment completed
$cancelled = false;
$hasError = false;
$step1 = true; // Booking always exists
$step2 = false; // Patient arrived at clinic?
$step3 = false; // Appointment completed?

// Error cases: cancelled, no-show, or overdue without arrival (> 1 day)
if ($row['status'] === 'cancelled') {
    $cancelled = true;
    $hasError = true;
    $step1 = false;
} elseif ($row['status'] === 'no-show') {
    $hasError = true;
} else {
    // Check if converted to appointment
    $apptStmt = $conn->prepare('SELECT appointment_id, status FROM appointments WHERE booking_id = ? LIMIT 1');
    if ($apptStmt) {
        $apptStmt->bind_param('i', $bookingId);
        $apptStmt->execute();
        $apptRes = $apptStmt->get_result();
        $appointment = $apptRes ? $apptRes->fetch_assoc() : null;
        $apptStmt->close();
        
        if ($appointment) {
            // Step 2: Check if patient arrived (checked in at clinic)
            $arrivalStmt = $conn->prepare('SELECT schedule_id FROM patient_schedules WHERE appointment_id = ? LIMIT 1');
            if ($arrivalStmt) {
                $arrivalStmt->bind_param('i', $appointment['appointment_id']);
                $arrivalStmt->execute();
                $arrivalRes = $arrivalStmt->get_result();
                $step2 = $arrivalRes && $arrivalRes->num_rows > 0;
                $arrivalStmt->close();
            }
            
            // Step 3: Check if appointment is completed (has billing record)
            $billingStmt = $conn->prepare('SELECT billing_id FROM billing WHERE appointment_id = ? LIMIT 1');
            if ($billingStmt) {
                $billingStmt->bind_param('i', $appointment['appointment_id']);
                $billingStmt->execute();
                $billingRes = $billingStmt->get_result();
                $step3 = $billingRes && $billingRes->num_rows > 0;
                $billingStmt->close();
            }
        } else {
            // Converted but no appointment found - check if un-processed and overdue (> 1 day)
            $createdTime = strtotime($row['created_at']);
            $now = time();
            $dayInSeconds = 86400;
            
            if ($now - $createdTime > $dayInSeconds) {
                $hasError = true;
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'booking_id' => (int)$row['booking_id'],
    'step1' => $step1,
    'step2' => $step2,
    'step3' => $step3,
    'cancelled' => $cancelled,
    'hasError' => $hasError,
]);
?>
