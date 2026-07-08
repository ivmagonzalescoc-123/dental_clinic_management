<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

header('Content-Type: application/json');

session_start();
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';

// Require secretary or admin session
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

try {
    if (!$conn) throw new Exception('Database connection failed');

    $status = trim($_GET['status'] ?? 'verified');
    if (!in_array($status, ['pending_verification', 'verified', 'converted', 'cancelled', 'all'], true)) {
        $status = 'verified';
    }

    if ($status === 'all') {
        $sql = "SELECT booking_id, first_name, last_name, email, phone_number, date_of_birth,
                       preferred_date, preferred_time, reason, status, created_at
                FROM bookings
                WHERE status != 'pending_verification'
                ORDER BY created_at DESC
                LIMIT 200";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    } else {
        $sql = "SELECT booking_id, first_name, last_name, email, phone_number, date_of_birth,
                       preferred_date, preferred_time, reason, status, created_at
                FROM bookings
                WHERE status = ?
                ORDER BY created_at DESC
                LIMIT 200";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $status);
        $stmt->execute();
    }

    $res = $stmt->get_result();
    $bookings = [];
    while ($row = $res->fetch_assoc()) {
        $bookings[] = [
            'booking_id'     => (int)$row['booking_id'],
            'first_name'     => $row['first_name'],
            'last_name'      => $row['last_name'],
            'email'          => $row['email'],
            'phone_number'   => $row['phone_number'],
            'date_of_birth'  => $row['date_of_birth'],
            'preferred_date' => $row['preferred_date'],
            'preferred_time' => $row['preferred_time'],
            'reason'         => $row['reason'],
            'status'         => $row['status'],
            'created_at'     => $row['created_at'],
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'bookings' => $bookings]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
