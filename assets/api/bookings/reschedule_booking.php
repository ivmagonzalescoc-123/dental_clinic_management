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
$newDate = trim($_POST['preferred_date'] ?? '');
$newTime = trim($_POST['preferred_time'] ?? '');

if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit;
}

$dt = DateTime::createFromFormat('Y-m-d', $newDate);
if (!$dt || $dt->format('Y-m-d') !== $newDate) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}
$today = new DateTime('today');
if ($dt < $today) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New date cannot be in the past.']);
    exit;
}

if (!preg_match('/^(\d{1,2}):([0-5]\d)\s*(AM|PM)$/i', $newTime, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid time format. Use H:MM AM/PM.']);
    exit;
}
$newTime = $m[1] . ':' . $m[2] . ' ' . strtoupper($m[3]);

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE bookings SET preferred_date = ?, preferred_time = ? WHERE booking_id = ? AND status IN ('pending_verification','verified','approved')");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    $stmt->bind_param('ssi', $newDate, $newTime, $bookingId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new Exception('Booking not found or already processed.');
    }

    $bookingStmt = $conn->prepare('SELECT first_name, last_name, email FROM bookings WHERE booking_id = ?');
    if ($bookingStmt) {
        $bookingStmt->bind_param('i', $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        $bookingStmt->close();

        if ($booking && !empty($booking['email']) && function_exists('reminder_send_email') && function_exists('email_booking_rescheduled_html')) {
            $patientName = trim($booking['first_name'] . ' ' . $booking['last_name']);
            $subject = 'Booking Rescheduled - ' . (function_exists('email_clinic_name') ? email_clinic_name() : 'Dental Clinic');
            $textContent = 'Your booking #' . $bookingId . ' has been rescheduled to ' . $newDate . ', ' . $newTime . '.';
            $htmlContent = email_booking_rescheduled_html($patientName, $bookingId, $newDate, $newTime);
            reminder_send_email($booking['email'], $subject, $textContent, $htmlContent);
        }
    }

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'booking.reschedule',
            'bookings',
            $bookingId,
            'Booking rescheduled by secretary to ' . $newDate . ' ' . $newTime . '.',
            (int)($_SESSION['user_id'] ?? 0)
        );
    }

    echo json_encode(['success' => true, 'message' => 'Booking rescheduled.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
