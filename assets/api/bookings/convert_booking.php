<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

ob_start();
header('Content-Type: application/json');

session_start();
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'secretary'], true)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    if (!$conn) throw new Exception('Database connection failed');

    $bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
    if ($bookingId <= 0) {
        throw new Exception('Invalid booking ID.');
    }

    // A booking is normally 'approved' by the time it's processed (Approve is clicked
    // before Process), so the WHERE clause must accept both 'verified' and 'approved' —
    // requiring only 'verified' meant this update silently never matched in the common
    // path, leaving bookings.status stuck even after a real appointment was created.
    $stmt = $conn->prepare("UPDATE bookings SET status = 'converted' WHERE booking_id = ? AND status IN ('verified', 'approved')");
    if (!$stmt) throw new Exception('Database error.');
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    ob_end_clean();
    echo json_encode(['success' => true, 'updated' => $affected > 0, 'message' => 'Booking marked as converted.']);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
