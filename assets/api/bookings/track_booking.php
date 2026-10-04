<?php
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/booking_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/rate_limit_helpers.php';
bookings_ensure_decline_reason_column($conn);
bookings_ensure_tracking_code_column($conn);

appointments_ensure_reference_column($conn);

// Tracking works only with the public reference number (8 characters). The sequential
// booking/appointment numbers are internal and can't be looked up, so nobody can walk
// through other people's bookings by counting.
$trackingCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['tracking_code'] ?? $_GET['ref'] ?? '')));
if (strpos($trackingCode, 'REF') === 0 && strlen($trackingCode) > 8) {
    $trackingCode = substr($trackingCode, 3); // "Ref K7MP3QXA" pasted as-is
}

if (!preg_match('/^[A-Z0-9]{6,10}$/', $trackingCode)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid reference number.']);
    exit;
}

if (!rate_limit_check($conn, 'booking_track_ip', rate_limit_client_ip(), 30, 600)) {
    rate_limit_reject('Too many lookups from this device. Please try again later.');
}

// 1) A guest booking (and the appointment it became, if any).
$stmt = $conn->prepare(
    'SELECT b.booking_id, b.status, b.created_at, b.decline_reason, b.tracking_code, a.status AS appt_status
     FROM bookings b
     LEFT JOIN appointments a ON a.booking_id = b.booking_id
     WHERE b.tracking_code = ?
     ORDER BY a.appointment_id DESC
     LIMIT 1'
);
$row = null;
if ($stmt) {
    $stmt->bind_param('s', $trackingCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 2) Otherwise an appointment made in the patient portal or by the clinic. It maps onto
//    the same three steps: booked -> confirmed -> processed at the clinic.
if (!$row) {
    $astmt = $conn->prepare(
        'SELECT a.status AS appt_status, a.created_at, a.cancellation_reason, a.reference_code
         FROM appointments a WHERE a.reference_code = ? LIMIT 1'
    );
    if ($astmt) {
        $astmt->bind_param('s', $trackingCode);
        $astmt->execute();
        $appt = $astmt->get_result()->fetch_assoc();
        $astmt->close();
        if ($appt) {
            $s = (string)$appt['appt_status'];
            $row = [
                'booking_id' => 0,
                'status' => in_array($s, ['scheduled', 'pending'], true) ? 'verified' : ($s === 'cancelled' ? 'cancelled' : 'approved'),
                'created_at' => $appt['created_at'],
                'decline_reason' => (string)($appt['cancellation_reason'] ?? ''),
                'tracking_code' => (string)$appt['reference_code'],
                'appt_status' => $s,
            ];
        }
    }
}

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'No booking or appointment matches that reference number. Please check and try again.']);
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
    'tracking_code' => (string)($row['tracking_code'] ?? ''),
    'status' => $row['status'],
    'step1' => $step1,
    'step2' => $step2,
    'step3' => $step3,
    'cancelled' => $cancelled,
    'no_show' => $noShow,
    'hasError' => $hasError,
    'decline_reason' => (string)($row['decline_reason'] ?? ''),
]);
?>
