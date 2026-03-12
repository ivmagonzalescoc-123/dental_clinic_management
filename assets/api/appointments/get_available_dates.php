<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

header('Content-Type: application/json');
session_start();

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include __DIR__ . '/availability_guard.php';

    $days    = isset($_GET['days'])    ? (int)$_GET['days']    : 30;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($days <= 0 || $days > 365) $days = 30;

    $start = date('Y-m-d');
    $end   = date('Y-m-d', strtotime("+{$days} days"));

    if ($user_id > 0) {
        $genPeriod = new DatePeriod(
            new DateTime($start),
            new DateInterval('P1D'),
            (new DateTime($end))->modify('+1 day')
        );
        foreach ($genPeriod as $dt) {
            appt_ensure_standard_timeslots_for_doctor_date($conn, $user_id, $dt->format('Y-m-d'));
        }
    }

    // Use timeslot table when a doctor is specified — count available vs booked per date
    if ($user_id) {
        $sql = "
            SELECT slot_date,
                   SUM(status = 'booked')    AS booked_slots,
                   COUNT(*)                  AS total_slots
            FROM timeslot
            WHERE doctor_id = ?
              AND slot_date BETWEEN ? AND ?
            GROUP BY slot_date
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iss', $user_id, $start, $end);
    } else {
        // No doctor — aggregate confirmed appointments per date across all doctors
        $sql = "
            SELECT appointment_date AS slot_date,
                   COUNT(DISTINCT appointment_id) AS booked_slots,
                   15                             AS total_slots
            FROM appointments
            WHERE appointment_date BETWEEN ? AND ?
              AND status IN ('confirmed', 'checked_in', 'in_progress', 'completed')
            GROUP BY appointment_date
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $start, $end);
    }

    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->execute();
    $res    = $stmt->get_result();
    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $counts[$row['slot_date']] = [
            'booked' => (int)$row['booked_slots'],
            'total'  => (int)($row['total_slots'] ?? 15),
        ];
    }
    $stmt->close();

    $dates  = [];
    $period = new DatePeriod(
        new DateTime($start),
        new DateInterval('P1D'),
        (new DateTime($end))->modify('+1 day')
    );
    foreach ($period as $dt) {
        $d    = $dt->format('Y-m-d');

        $data = $counts[$d] ?? ['booked' => 0, 'total' => 15];
        $available = ($data['booked'] < $data['total']);
        $reason = '';

        if ($user_id > 0) {
            // Even with open capacity, do not allow dates with no schedule or approved leave.
            [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $user_id, $d);
            $available = $available && $bookable;
            if (!$bookable) {
                $reason = (string)$bookableMsg;
            }
        }

        if (!$available && $reason === '' && ((int)$data['total'] > 0) && ((int)$data['booked'] >= (int)$data['total'])) {
            $reason = 'No remaining available slots on this date.';
        }

        $dates[] = [
            'date'      => $d,
            'count'     => $data['booked'],
            'total'     => $data['total'],
            'available' => $available,
            'reason'    => $reason,
        ];
    }

    echo json_encode(['success' => true, 'dates' => $dates]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
