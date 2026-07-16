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

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit;
}

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE bookings SET status = 'approved' WHERE booking_id = ? AND status = 'verified'");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error);
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new Exception('Booking not found or already processed.');
    }

    $bookingStmt = $conn->prepare("SELECT first_name, last_name, email, preferred_date, preferred_time, reason FROM bookings WHERE booking_id = ?");
    if ($bookingStmt) {
        $bookingStmt->bind_param('i', $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        $bookingStmt->close();

        if ($booking && !empty($booking['email']) && function_exists('reminder_send_email') && function_exists('email_booking_approved_html')) {
            $patientName = trim($booking['first_name'] . ' ' . $booking['last_name']);
            $subject = 'Appointment Approved - ' . (function_exists('email_clinic_name') ? email_clinic_name() : 'Dental Clinic');
            $textContent = 'Your booking #' . $bookingId . ' has been approved. Please visit the clinic on ' . $booking['preferred_date'] . '.';
            $htmlContent = email_booking_approved_html($patientName, $bookingId, $booking['preferred_date'], $booking['preferred_time'], $booking['reason'] ?? '');
            reminder_send_email($booking['email'], $subject, $textContent, $htmlContent);
        }
    }

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'booking.approve',
            'bookings',
            $bookingId,
            'Booking approved by secretary.',
            (int)($_SESSION['user_id'] ?? 0)
        );
    }

    echo json_encode(['success' => true, 'message' => 'Booking approved.']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}