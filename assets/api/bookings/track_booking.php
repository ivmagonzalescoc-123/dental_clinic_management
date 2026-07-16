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

// New 3-step tracker logic:
// Step 1: Booking Created (always true after OTP verification)
// Step 2: Booking Confirmed (secretary approved the booking)
// Step 3: Visit Clinic (secretary processed -> appointment created)

$cancelled = false;
$hasError = false;
$step1 = true;
$step2 = false;
$step3 = false;

if ($row['status'] === 'cancelled') {
    $cancelled = true;
    $hasError = true;
    $step1 = false;
} else {
    // Step 2: approved or converted (both mean booking is confirmed)
    $step2 = in_array($row['status'], ['approved', 'converted'], true);
    
    // Step 3: converted (appointment exists)
    $step3 = ($row['status'] === 'converted');
    
    // Error: verified but older than 1 day (pending approval too long)
    if ($row['status'] === 'verified') {
        $createdTime = strtotime($row['created_at']);
        if (time() - $createdTime > 86400) {
            $hasError = true;
        }
    }
}

echo json_encode([
    'success' => true,
    'booking_id' => (int)$row['booking_id'],
    'status' => $row['status'],
    'step1' => $step1,
    'step2' => $step2,
    'step3' => $step3,
    'cancelled' => $cancelled,
    'hasError' => $hasError,
]);
?>
