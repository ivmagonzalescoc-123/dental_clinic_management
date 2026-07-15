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

// Determine steps: 1=created, 2=reminder sent, 3=successful visit
$cancelled = false;
$step1 = true; // Booking always exists
$step2 = false; // Reminder sent?
$step3 = false; // Successfully processed?

if ($row['status'] === 'cancelled') {
    $cancelled = true;
    $step1 = false;
} elseif ($row['status'] === 'converted') {
    // Processed — check if reminder was sent
    $step3 = true;
    if (!empty($row['converted_to_appointment_id'])) {
        $remStmt = $conn->prepare('SELECT reminder_id FROM schedule_reminders WHERE patient_schedule_id IN (SELECT schedule_id FROM patient_schedules WHERE appointment_id = ?) AND sent_at IS NOT NULL LIMIT 1');
        if ($remStmt) {
            $apptId = (int)$row['converted_to_appointment_id'];
            $remStmt->bind_param('i', $apptId);
            $remStmt->execute();
            $remRes = $remStmt->get_result();
            $step2 = $remRes && $remRes->num_rows > 0;
            $remStmt->close();
        }
    }
}
// pending_verification or verified → only step 1

echo json_encode([
    'success' => true,
    'booking_id' => (int)$row['booking_id'],
    'step1' => $step1,
    'step2' => $step2,
    'step3' => $step3,
    'cancelled' => $cancelled,
]);
?>
