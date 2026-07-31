<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/email_templates.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/booking_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'secretary'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

$bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
$reason = trim($_POST['decline_reason'] ?? '');
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit;
}
if ($reason === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A reason is required to decline a booking.']);
    exit;
}

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    bookings_ensure_decline_reason_column($conn);

    $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', decline_reason = ? WHERE booking_id = ? AND status IN ('pending_verification','verified','approved')");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    $stmt->bind_param('si', $reason, $bookingId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new Exception('Booking not found or already processed.');
    }

    if (function_exists('bookings_ensure_tracking_code_column')) {
        bookings_ensure_tracking_code_column($conn);
    }

    $bookingStmt = $conn->prepare('SELECT first_name, last_name, email, preferred_date, preferred_time, tracking_code FROM bookings WHERE booking_id = ?');
    if ($bookingStmt) {
        $bookingStmt->bind_param('i', $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        $bookingStmt->close();

        if ($booking && !empty($booking['email']) && function_exists('reminder_send_email') && function_exists('email_booking_declined_html')) {
            $patientName = trim($booking['first_name'] . ' ' . $booking['last_name']);
            $trackingCode = (string)($booking['tracking_code'] ?? '');
            $refLabel = $trackingCode !== '' ? $trackingCode : $bookingId;
            $subject = 'Booking Request Declined - ' . (function_exists('email_clinic_name') ? email_clinic_name() : 'Dental Clinic');
            $textContent = 'Your booking Ref# ' . $refLabel . ' for ' . $booking['preferred_date'] . ' was declined. Reason: ' . $reason;
            $htmlContent = email_booking_declined_html($patientName, $bookingId, $booking['preferred_date'], $booking['preferred_time'], $reason, $trackingCode);
            reminder_send_email($booking['email'], $subject, $textContent, $htmlContent);
        }
    }

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'booking.decline',
            'bookings',
            $bookingId,
            'Booking declined by secretary. Reason: ' . $reason,
            (int)($_SESSION['user_id'] ?? 0)
        );
    }

    echo json_encode(['success' => true, 'message' => 'Booking declined.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
