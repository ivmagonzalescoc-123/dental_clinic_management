<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/email_templates.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';

    if (!$conn) throw new Exception('Database connection failed');

    $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    $otp       = trim($_POST['otp'] ?? '');

    if ($bookingId <= 0) {
        throw new Exception('Invalid booking reference.');
    }
    if ($otp === '' || strlen($otp) !== 6) {
        throw new Exception('Please enter the 6-digit verification code.');
    }

    // Fetch booking
    $stmt = $conn->prepare(
        "SELECT booking_id, otp_hash, otp_expires_at, status FROM bookings WHERE booking_id = ? LIMIT 1"
    );
    if (!$stmt) throw new Exception('Database error.');
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        throw new Exception('Booking not found.');
    }
    if ($booking['status'] === 'verified') {
        echo json_encode(['success' => true, 'message' => 'Booking already verified.']);
        exit;
    }
    if ($booking['status'] !== 'pending_verification') {
        throw new Exception('This booking cannot be verified.');
    }

    // Check expiry
    if (strtotime($booking['otp_expires_at']) < time()) {
        throw new Exception('Verification code has expired. Please request a new one.');
    }

    // Verify OTP
    $otpHash = hash('sha256', $otp);
    if (!hash_equals($booking['otp_hash'], $otpHash)) {
        throw new Exception('Invalid verification code. Please try again.');
    }

    // Update status to verified
    $upd = $conn->prepare("UPDATE bookings SET status = 'verified' WHERE booking_id = ? AND status = 'pending_verification'");
    if (!$upd) throw new Exception('Database error.');
    $upd->bind_param('i', $bookingId);
    $upd->execute();
    $upd->close();

    // Fetch full booking details for confirmation email
    $detStmt = $conn->prepare("SELECT first_name, last_name, email, preferred_date, preferred_time, reason FROM bookings WHERE booking_id = ? LIMIT 1");
    if ($detStmt) {
        $detStmt->bind_param('i', $bookingId);
        $detStmt->execute();
        $det = $detStmt->get_result()->fetch_assoc();
        $detStmt->close();

        if ($det && !empty($det['email'])) {
            $patientName = trim($det['first_name'] . ' ' . $det['last_name']);
            $subject = 'Booking Confirmed - ' . email_clinic_name();
            $textContent = email_booking_confirmed_text($patientName, $bookingId, $det['preferred_date'], $det['preferred_time'], $det['reason'] ?? '');
            $htmlContent = email_booking_confirmed_html($patientName, $bookingId, $det['preferred_date'], $det['preferred_time'], $det['reason'] ?? '');
            reminder_send_email($det['email'], $subject, $textContent, $htmlContent);
        }
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Booking confirmed! A confirmation email has been sent.',
        'booking_id' => $bookingId,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
