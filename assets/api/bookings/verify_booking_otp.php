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
    include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/email_templates.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';

    security_start_session();

    if (!$conn) throw new Exception('Database connection failed');

    $otp = trim($_POST['otp'] ?? '');

    if ($otp === '' || strlen($otp) !== 6) {
        throw new Exception('Please enter the 6-digit verification code.');
    }

    // Get pending booking from session
    $pending = $_SESSION['pending_booking'] ?? null;
    if (!$pending) {
        throw new Exception('No pending booking found. Please start over.');
    }

    // Check expiry
    if (strtotime($pending['otp_expires_at']) < time()) {
        unset($_SESSION['pending_booking']);
        throw new Exception('Verification code has expired. Please request a new one.');
    }

    // Verify OTP
    $otpHash = hash('sha256', $otp);
    if (!hash_equals($pending['otp_hash'], $otpHash)) {
        throw new Exception('Invalid verification code. Please try again.');
    }

    // OTP verified — now insert into DB with 'verified' status
    $sql = "INSERT INTO bookings (first_name, last_name, email, phone_number, date_of_birth, preferred_date, preferred_time, reason, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'verified', ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $conn->error);

    $stmt->bind_param('sssssssss',
        $pending['first_name'],
        $pending['last_name'],
        $pending['email'],
        $pending['phone_number'],
        $pending['date_of_birth'],
        $pending['preferred_date'],
        $pending['preferred_time'],
        $pending['reason'],
        $pending['created_at']
    );
    if (!$stmt->execute()) throw new Exception('Failed to save booking: ' . $stmt->error);
    $bookingId = (int)$conn->insert_id;
    $stmt->close();

    // Clear session
    unset($_SESSION['pending_booking']);

    // Send confirmation email
    $patientName = trim($pending['first_name'] . ' ' . $pending['last_name']);

    // Notify secretaries of the new patient booking
    wf_notify_new_patient_booking($conn, $bookingId, $patientName, $pending['preferred_date']);

    $subject = 'Booking Confirmed - ' . email_clinic_name();
    $textContent = 'Your booking has been confirmed. Booking #' . $bookingId . ' for ' . $pending['preferred_date'] . '.';
    $htmlContent = email_booking_confirmed_html($patientName, $bookingId, $pending['preferred_date'], $pending['preferred_time'], $pending['reason'] ?? '');
    reminder_send_email($pending['email'], $subject, $textContent, $htmlContent);

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
