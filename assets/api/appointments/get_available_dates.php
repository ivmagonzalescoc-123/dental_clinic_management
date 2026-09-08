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

    // Built once per request (leave ranges + part-time weekday availability fetched in a
    // small constant number of queries) and reused for both the slot-generation pass below
    // and the per-day bookability check further down — instead of re-querying leave/schedule
    // data on every single day in each of those two loops separately.
    $bookableChecker = $user_id > 0 ? appt_build_bookable_checker($conn, $user_id) : null;

    if ($user_id > 0) {
        // Ensure timeslots exist for the specific doctor — skip days we already know aren't
        // bookable (leave/no schedule) instead of letting appt_ensure_standard_timeslots_for_
        // doctor_date re-derive that itself with its own extra queries for every such day.
        $genPeriod = new DatePeriod(
            new DateTime($start),
            new DateInterval('P1D'),
            (new DateTime($end))->modify('+1 day')
        );
        foreach ($genPeriod as $dt) {
            $dStr = $dt->format('Y-m-d');
            [$isBookable] = $bookableChecker($dStr);
            if ($isBookable) {
                appt_ensure_standard_timeslots_for_doctor_date($conn, $user_id, $dStr);
            }
        }
    } else {
        // No doctor — ensure timeslots for ALL active doctors on each date
        $docRes = $conn->query("SELECT user_id FROM users WHERE role_id = 2 AND is_active = 1");
        $doctorIds = [];
        if ($docRes) {
            while ($dRow = $docRes->fetch_assoc()) {
                $doctorIds[] = (int)$dRow['user_id'];
            }
        }
        if (!empty($doctorIds)) {
            $genPeriod = new DatePeriod(
                new DateTime($start),
                new DateInterval('P1D'),
                (new DateTime($end))->modify('+1 day')
            );
            foreach ($genPeriod as $dt) {
                $dateStr = $dt->format('Y-m-d');
                foreach ($doctorIds as $did) {
                    appt_ensure_standard_timeslots_for_doctor_date($conn, $did, $dateStr);
                }
            }
        }
    }

    // Use timeslot table — count available vs booked per date
    if ($user_id) {
        $sql = "
            SELECT slot_date,
                   COUNT(DISTINCT doctor_id)    AS doctors_available,
                   SUM(status = 'booked')       AS booked_slots,
                   COUNT(*)                     AS total_slots
            FROM timeslot
            WHERE doctor_id = ?
              AND slot_date BETWEEN ? AND ?
            GROUP BY slot_date
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iss', $user_id, $start, $end);
    } else {
        // No doctor — aggregate across all doctors' timeslots
        $sql = "
            SELECT slot_date,
                   COUNT(DISTINCT doctor_id)                       AS doctors_available,
                   SUM(status = 'booked')                          AS booked_slots,
                   COUNT(*)                                        AS total_slots
            FROM timeslot
            WHERE slot_date BETWEEN ? AND ?
            GROUP BY slot_date
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
            'booked'   => (int)$row['booked_slots'],
            'total'    => (int)($row['total_slots'] ?? 0),
            'doctors'  => (int)($row['doctors_available'] ?? 0),
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

        $data = $counts[$d] ?? ['booked' => 0, 'total' => 0, 'doctors' => 0];
        $doctorsAvailable = $data['doctors'];
        $available = ($doctorsAvailable > 0 && $data['booked'] < $data['total']);
        $reason = '';

        if ($user_id > 0) {
            // Even with open capacity, do not allow dates with no schedule or approved leave.
            [$bookable, $bookableMsg] = $bookableChecker($d);
            $available = $available && $bookable;
            if (!$bookable) {
                $reason = (string)$bookableMsg;
            }
        } else {
            // No doctor mode: check if Sunday
            $weekday = (int)(new DateTime($d))->format('w');
            if ($weekday === 0) {
                $available = false;
                $reason = 'Clinic is closed on Sundays.';
            } elseif ($doctorsAvailable <= 0) {
                $available = false;
                $reason = 'No doctors available on this date.';
            }
        }

        if (!$available && $reason === '' && $data['total'] > 0 && $data['booked'] >= $data['total']) {
            $reason = 'No remaining available slots on this date.';
        }

        $dates[] = [
            'date'              => $d,
            'count'             => $data['booked'],
            'total'             => $data['total'],
            'available'         => $available,
            'reason'            => $reason,
            'doctors_available' => $doctorsAvailable,
        ];
    }

    echo json_encode(['success' => true, 'dates' => $dates]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
