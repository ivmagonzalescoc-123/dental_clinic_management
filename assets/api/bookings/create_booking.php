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
    include_once dirname(dirname(dirname(__DIR__))) . '/config/rate_limit_helpers.php';

    security_start_session();

    if (!$conn) throw new Exception('Database connection failed');

    // CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
        exit;
    }

    // This endpoint sends an OTP email on every call (including "resend"), so without a
    // limit it can be used to email-bomb any address. Cap per-IP and per-email separately.
    if (!rate_limit_check($conn, 'booking_otp_ip', rate_limit_client_ip(), 10, 3600)) {
        rate_limit_reject('Too many booking attempts from this device. Please try again later.');
    }

    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone_number'] ?? '');
    $dob        = trim($_POST['date_of_birth'] ?? '');
    $prefDate   = trim($_POST['preferred_date'] ?? '');
    $prefTime   = trim($_POST['preferred_time'] ?? '9:00 AM');
    $reason     = trim($_POST['reason'] ?? '');
    $termsAccepted = ($_POST['terms_accepted'] ?? '') === '1';
    $otpChannel = trim($_POST['otp_channel'] ?? 'email');
    if (!in_array($otpChannel, ['email', 'sms'], true)) {
        throw new Exception('That verification method is not available. Please choose email or SMS.');
    }

    // Validation
    if (!$termsAccepted) {
        throw new Exception('You must agree to the Terms and Conditions to book an appointment.');
    }
    if ($firstName === '' || $lastName === '') {
        throw new Exception('First name and last name are required.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid email address is required.');
    }
    if (!rate_limit_check($conn, 'booking_otp_email', $email, 3, 600)) {
        throw new Exception('Too many verification codes requested for this email. Please wait a few minutes and try again.');
    }
    if ($prefDate === '') {
        throw new Exception('Preferred date is required.');
    }

    // Validate date of birth (optional field, but if present it must be a real
    // calendar date with a sane 4-digit year — some browsers let users type more
    // than 4 digits into a <input type="date"> year segment).
    if ($dob !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            throw new Exception('Invalid birthdate format. Use YYYY-MM-DD.');
        }
        $dobDt = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobDt || $dobDt->format('Y-m-d') !== $dob) {
            throw new Exception('Invalid birthdate.');
        }
        $dobYear = (int)$dobDt->format('Y');
        $currentYear = (int)date('Y');
        if ($dobYear < 1900 || $dobYear > $currentYear) {
            throw new Exception('Birth year must be between 1900 and ' . $currentYear . '.');
        }
        if ($dobDt > new DateTime('today')) {
            throw new Exception('Birthdate cannot be in the future.');
        }
    }
    // Validate exact time (matches the frontend picker: H:MM AM/PM, 9:00 AM - 4:30 PM)
    if (!preg_match('/^(\d{1,2}):([0-5]\d)\s*(AM|PM)$/i', $prefTime, $timeMatch)) {
        throw new Exception('Please select a valid preferred time.');
    }
    $prefHour = (int)$timeMatch[1];
    $prefMinute = (int)$timeMatch[2];
    $prefMeridiem = strtoupper($timeMatch[3]);
    if ($prefHour < 1 || $prefHour > 12) {
        throw new Exception('Please select a valid preferred time.');
    }
    $prefHour24 = $prefHour % 12;
    if ($prefMeridiem === 'PM') $prefHour24 += 12;
    $prefMinutesOfDay = $prefHour24 * 60 + $prefMinute;
    if ($prefMinutesOfDay < 540) {
        throw new Exception('Earliest preferred time is 9:00 AM.');
    }
    if ($prefMinutesOfDay > 990) {
        throw new Exception('Latest preferred time is 4:30 PM.');
    }
    // Normalize to a consistent "H:MM AM/PM" format for storage/display
    $prefTime = $prefHour . ':' . str_pad((string)$prefMinute, 2, '0', STR_PAD_LEFT) . ' ' . $prefMeridiem;

    // Validate date format and range. Require exactly a 4-digit year up front so a
    // browser quirk that lets someone type extra digits into the year segment (e.g.
    // "20255-01-01") is rejected outright instead of relying only on the max-date check below.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefDate)) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD.');
    }
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

    // Check duplicate booking (same email + same preferred date, verified only)
    $dupStmt = $conn->prepare(
        "SELECT booking_id FROM bookings WHERE email = ? AND preferred_date = ? AND status IN ('verified','converted') LIMIT 1"
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

    // Clean up expired pending_verification bookings for this email+date (older than 10 min)
    $cleanup = $conn->prepare("DELETE FROM bookings WHERE email = ? AND preferred_date = ? AND status = 'pending_verification' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    if ($cleanup) {
        $cleanup->bind_param('ss', $email, $prefDate);
        $cleanup->execute();
        $cleanup->close();
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

    // Store booking data in SESSION (no DB insert yet)
    $_SESSION['pending_booking'] = [
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'email'         => $email,
        'phone_number'  => $phone !== '' ? $phone : null,
        'date_of_birth' => $dob !== '' ? $dob : null,
        'preferred_date'=> $prefDate,
        'preferred_time'=> $prefTime,
        'reason'        => $reason !== '' ? $reason : 'N/A',
        'otp_hash'      => $otpHash,
        'otp_expires_at'=> $otpExpires,
        'created_at'    => date('Y-m-d H:i:s'),
    ];

    // Send OTP via the chosen channel
    $patientName = trim($firstName . ' ' . $lastName);
    $emailSent = false;

    if ($otpChannel === 'sms') {
        if ($phone === '') {
            throw new Exception('A phone number is required to receive the code via SMS.');
        }
        $smsMessage = 'Your Azucena\'s Dental Clinic verification code is: ' . $otp . '. It expires in 10 minutes.';
        $emailSent = reminder_send_sms($phone, $smsMessage);

        if (!$emailSent && reminder_sms_needs_paid_plan()) {
            // Keep the existing pending_booking (and its still-valid OTP/hash) intact so the
            // user can fall back to email or retry — don't strand them mid-verification.
            echo json_encode([
                'success' => false,
                'message' => 'SMS is not available yet — this requires a paid SMS plan. Please use email to receive your code.',
                'code'    => 'sms_unavailable',
            ]);
            exit;
        }

        $otpMessage = 'Code sent via SMS. Please verify to complete your booking.';
    } else {
        $subject = 'Booking Verification - ' . email_clinic_name();
        $textContent = 'Your verification code is: ' . $otp . '. It expires in 10 minutes.';
        $htmlContent = email_booking_otp_html($otp, $patientName);
        $emailSent = reminder_send_email($email, $subject, $textContent, $htmlContent);
        $otpMessage = 'OTP sent to your email. Please verify to complete your booking.';
    }

    echo json_encode([
        'success'    => true,
        'message'    => $otpMessage,
        'email_sent' => $emailSent,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
