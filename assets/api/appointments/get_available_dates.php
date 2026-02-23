<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

header('Content-Type: application/json');
session_start();

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    if ($days <= 0 || $days > 365) $days = 30;

    $start = date('Y-m-d');
    $end = date('Y-m-d', strtotime("+{$days} days"));

    // Get counts grouped by date in range — count appointments that are already confirmed, checked-in, in-progress
    // or have an entry in the check_in_queue for that date (to include on-queue patients).
    $sql = "SELECT a.appointment_date, COUNT(DISTINCT a.appointment_id) AS cnt
            FROM appointments a
            LEFT JOIN check_in_queue q ON q.appointment_id = a.appointment_id AND DATE(q.checkin_time) = a.appointment_date
            WHERE a.appointment_date BETWEEN ? AND ?
              AND (
                    a.status IN ('confirmed','checked_in','in_progress')
                    OR q.checkin_id IS NOT NULL
                  )
            GROUP BY a.appointment_date";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $counts[$row['appointment_date']] = (int)$row['cnt'];
    }
    $stmt->close();

    $dates = [];
    $period = new DatePeriod(new DateTime($start), new DateInterval('P1D'), (new DateTime($end))->modify('+1 day'));
    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');
        $cnt = $counts[$d] ?? 0;
        $dates[] = [
            'date' => $d,
            'count' => $cnt,
            'available' => ($cnt < 15)
        ];
    }

    echo json_encode(['success' => true, 'dates' => $dates]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
