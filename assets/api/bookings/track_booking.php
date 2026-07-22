<?php
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if ($bookingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid booking number.']);
    exit;
}

$stmt = $conn->prepare(
    'SELECT b.booking_id, b.first_name, b.last_name, b.email, b.phone_number, b.preferred_date, b.preferred_time,
            b.reason, b.status, b.created_at, a.status AS appt_status
     FROM bookings b
     LEFT JOIN appointments a ON a.booking_id = b.booking_id
     WHERE b.booking_id = ?
     ORDER BY a.appointment_id DESC
     LIMIT 1'
);
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
$noShow = false;
$hasError = false;
$step1 = true;
$step2 = false;
$step3 = false;

$apptStatus = $row['appt_status'] ?? '';

// A booking can be cancelled directly (bookings.status), or after being processed into
// an appointment the secretary can cancel/decline it or mark the patient a no-show —
// both of those live on the appointment's own status, not the booking's.
if ($row['status'] === 'cancelled' || $apptStatus === 'cancelled') {
    $cancelled = true;
    $hasError = true;
    $step1 = false;
} elseif ($apptStatus === 'no_show') {
    $noShow = true;
    $hasError = true;
    $step1 = false;
} else {
    // Step 2: approved or converted (both mean booking is confirmed)
    $step2 = in_array($row['status'], ['approved', 'converted'], true);

    // Step 3: converted, OR the linked appointment has already moved past creation
    // (checked in, in session, or further). bookings.status can lag behind reality if
    // the secretary's convert step didn't flip it, so the appointment's own status is
    // the more reliable signal that the patient has actually been processed.
    $appointmentProcessed = in_array($apptStatus, [
        'checked_in', 'in_progress', 'completed_by_doctor',
        'awaiting_secretary_records', 'records_complete', 'completed',
    ], true);
    $step3 = ($row['status'] === 'converted') || $appointmentProcessed;

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
    'no_show' => $noShow,
    'hasError' => $hasError,
]);
?>
