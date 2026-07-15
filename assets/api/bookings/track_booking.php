<?php
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if ($bookingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid booking number.']);
    exit;
}

$stmt = $conn->prepare('SELECT booking_id, first_name, last_name, email, phone_number, preferred_date, preferred_time, reason, status, created_at FROM bookings WHERE booking_id = ?');
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Booking not found. Please check your booking number.']);
    exit;
}

$statusLabels = [
    'pending_verification' => ['label' => 'Pending Verification', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
    'verified' => ['label' => 'Verified', 'color' => '#1d6fd8', 'bg' => '#e3eeff'],
    'converted' => ['label' => 'Received', 'color' => '#16a34a', 'bg' => '#dcfce7'],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#dc2626', 'bg' => '#fee2e2'],
];

$statusInfo = $statusLabels[$row['status']] ?? ['label' => ucfirst($row['status']), 'color' => '#6b7280', 'bg' => '#f3f4f6'];

$notes = '';
switch ($row['status']) {
    case 'pending_verification':
        $notes = 'Your booking is awaiting verification by our team. We will confirm your appointment shortly.';
        break;
    case 'verified':
        $notes = 'Your booking has been verified! A secretary will process your appointment soon.';
        break;
    case 'converted':
        $notes = 'Your booking has been processed and an appointment has been created. Please check your email for login credentials.';
        break;
    case 'cancelled':
        $notes = 'This booking has been cancelled. Please book a new appointment if needed.';
        break;
}

echo json_encode([
    'success' => true,
    'booking' => [
        'booking_id' => (int)$row['booking_id'],
        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        'email' => $row['email'],
        'preferred_date' => $row['preferred_date'],
        'preferred_time' => $row['preferred_time'],
        'status' => $row['status'],
        'status_label' => $statusInfo['label'],
        'status_color' => $statusInfo['color'],
        'status_bg' => $statusInfo['bg'],
        'note' => $notes,
    ]
]);
?>
