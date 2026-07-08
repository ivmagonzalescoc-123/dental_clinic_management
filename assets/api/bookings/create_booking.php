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

    if (!$conn) throw new Exception('Database connection failed');

    // CSRF validation (token from index.php)
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }

    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone_number'] ?? '');
    $dob        = trim($_POST['date_of_birth'] ?? '');
    $prefDate   = trim($_POST['preferred_date'] ?? '');
    $prefTime   = trim($_POST['preferred_time'] ?? 'morning');
    $reason     = trim($_POST['reason'] ?? '');

    // Validation
    if ($firstName === '' || $lastName === '') {
        throw new Exception('First name and last name are required.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid email address is required.');
    }
    if ($prefDate === '') {
        throw new Exception('Preferred date is required.');
    }
    if (!in_array($prefTime, ['morning', 'afternoon'], true)) {
        $prefTime = 'morning';
    }

    // Validate date format and range (must be today or future, within 30 days)
    $dt = DateTime::createFromFormat('Y-m-d', $prefDate);
    if (!$dt || $dt->format('Y-m-d') !== $prefDate) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD.');
    }
    $today = new DateTime('today');
    if ($dt < $today) {
        throw new Exception('Preferred date must be today or later.');
    }
    $maxDate = new DateTime('+30 days');
    if ($dt > $maxDate) {
        throw new Exception('Preferred date must be within the next 30 days.');
    }

    // No Sunday bookings
    if ((int)$dt->format('w') === 0) {
        throw new Exception('Clinic is closed on Sundays. Please select another date.');
    }

    // Check duplicate booking (same email + same preferred date, not cancelled/converted)
    $dupStmt = $conn->prepare(
        "SELECT booking_id FROM bookings WHERE email = ? AND preferred_date = ? AND status IN ('pending_verification','verified') LIMIT 1"
    );
    if ($dupStmt) {
        $dupStmt->bind_param('ss', $email, $prefDate);
        $dupStmt->execute();
        $dupRes = $dupStmt->get_result();
        if ($dupRes && $dupRes->num_rows > 0) {
            $dupStmt->close();
            throw new Exception('You already have a booking for this date. Please wait for your existing booking to be processed.');
        }
        $dupStmt->close();
    }

    // Check if email already has a patient account
    $existingPatient = $conn->prepare("SELECT patient_id FROM patients WHERE email = ? AND email != '' LIMIT 1");
    if ($existingPatient) {
        $existingPatient->bind_param('s', $email);
        $existingPatient->execute();
        $existingResult = $existingPatient->get_result();
        if ($existingResult && $existingResult->num_rows > 0) {
            $existingPatient->close();
            echo json_encode([
                'success' => false,
                'message' => 'You already have an account with the clinic. Please login with the email you used.',
                'code' => 'account_exists'
            ]);
            exit;
        }
        $existingPatient->close();
    }

    // Generate 6-digit OTP
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = hash('sha256', $otp);
    $otpExpires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Insert booking
    $sql = "INSERT INTO bookings (first_name, last_name, email, phone_number, date_of_birth, preferred_date, preferred_time, reason, otp_hash, otp_expires_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $conn->error);

    $dobVal = $dob !== '' ? $dob : null;
    $reasonVal = $reason !== '' ? $reason : null;
    $phoneVal = $phone !== '' ? $phone : null;

    $stmt->bind_param('ssssssssss', $firstName, $lastName, $email, $phoneVal, $dobVal, $prefDate, $prefTime, $reasonVal, $otpHash, $otpExpires);
    if (!$stmt->execute()) throw new Exception('Failed to save booking: ' . $stmt->error);
    $bookingId = (int)$conn->insert_id;
    $stmt->close();

    // Send OTP email
    $patientName = trim($firstName . ' ' . $lastName);
    $subject = 'Booking Verification - ' . email_clinic_name();
    $textContent = email_booking_otp_text($otp, $patientName);
    $htmlContent = email_booking_otp_html($otp, $patientName);

    $emailSent = reminder_send_email($email, $subject, $textContent, $htmlContent);

    echo json_encode([
        'success'    => true,
        'message'    => 'OTP sent to your email. Please verify to complete your booking.',
        'booking_id' => $bookingId,
        'email_sent' => $emailSent,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
