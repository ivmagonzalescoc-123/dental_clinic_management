<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
check_session(['doctor']);
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$doctor_id = $_SESSION['user_id'] ?? 0;
$today = date('Y-m-d');

$out = ['success' => false, 'queue' => [], 'count' => 0];

if (!$doctor_id || !$conn) {
    echo json_encode($out);
    exit;
}

try {
    // Return queue entries for today regardless of appointment ownership.
    // Client will show assignment and allow claiming if needed.
        $sql = "SELECT q.checkin_id, q.que_number, q.status AS queue_status, q.checkin_time, a.appointment_id, a.user_id AS owner_id, p.first_name, p.last_name, p.phone_number,
               u.first_name AS owner_first, u.last_name AS owner_last
            FROM check_in_queue q
            INNER JOIN appointments a ON q.appointment_id = a.appointment_id
            INNER JOIN patients p ON a.patient_id = p.patient_id
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE DATE(q.checkin_time) = ? AND q.status IN ('waiting','called')
            ORDER BY q.que_number ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();

        $out['success'] = true;
        $out['queue'] = $rows;
        $out['count'] = count($rows);
    }
} catch (Exception $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out);
