<?php
// SSE stream for doctor's queue
set_time_limit(0);
ignore_user_abort(true);
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
check_session(['doctor']);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$doctor_id = $_SESSION['user_id'] ?? 0;
$today = date('Y-m-d');

if (!$doctor_id || !$conn) {
    echo "data: {\"success\":false}\n\n";
    flush();
    exit;
}

$lastCount = -1;

while (true) {
    // query current queue
    // Include all today's queue entries regardless of appointment ownership; clients can filter or show owner
        $sql = "SELECT q.checkin_id, q.que_number, q.status AS queue_status, q.checkin_time, a.appointment_id, a.user_id AS owner_id, p.first_name, p.last_name, p.phone_number,
               u.first_name AS owner_first, u.last_name AS owner_last
            FROM check_in_queue q
            INNER JOIN appointments a ON q.appointment_id = a.appointment_id
            INNER JOIN patients p ON a.patient_id = p.patient_id
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE DATE(q.checkin_time) = ? AND q.status IN ('waiting','called')
            ORDER BY q.que_number ASC";
    $stmt = $conn->prepare($sql);
    $rows = [];
    $count = 0;
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        $count = count($rows);
    }

    if ($count !== $lastCount) {
        $payload = ['success' => true, 'count' => $count, 'queue' => $rows];
        echo 'data: ' . json_encode($payload) . "\n\n";
        @ob_flush();
        @flush();
        $lastCount = $count;
    }

    // sleep a bit
    sleep(2);
}

?>
