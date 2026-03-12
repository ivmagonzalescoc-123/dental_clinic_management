<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary', 'doctor', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$date      = isset($_GET['date'])      ? trim($_GET['date'])     : date('Y-m-d');
$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;

// Doctor role always filters to own patients only
if ($role === 'doctor') {
    $doctor_id = (int)($_SESSION['user_id'] ?? 0);
}

try {
    $params = [$date];
    $types  = 's';
    $extra  = '';

    if ($doctor_id) {
        $extra    = ' AND a.user_id = ?';
        $params[] = $doctor_id;
        $types   .= 'i';
    }

    $sql = "
        SELECT
            a.appointment_id,
            a.patient_id,
            a.user_id       AS doctor_id,
            a.service_id,
            a.timeslot_id,
            a.reason,
            a.status        AS appointment_status,
            a.is_emergency,
            t.timeslot_id   AS ts_id,
            t.start_time,
            t.end_time,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.phone_number  AS patient_phone,
            CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
            s.service_name,
            COALESCE(ts2.duration_minutes, s.default_duration_minutes, 30) AS duration_minutes,
            pa.arrival_id,
            pa.status       AS arrival_status,
            pa.arrived_at,
            pa.expected_time
        FROM appointments a
        INNER JOIN timeslot t        ON t.timeslot_id  = a.timeslot_id
        LEFT  JOIN patients p        ON p.patient_id   = a.patient_id
        LEFT  JOIN users d           ON d.user_id      = a.user_id
        LEFT  JOIN services s        ON s.service_id   = a.service_id
        LEFT  JOIN timeslot_service ts2 ON ts2.service_id = a.service_id
        LEFT  JOIN patient_arrivals pa  ON pa.appointment_id = a.appointment_id
        WHERE t.slot_date = ?
          AND a.status NOT IN ('cancelled', 'rescheduled')
        {$extra}
        ORDER BY t.start_time ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];

    $isToday = ($date === date('Y-m-d'));
    $now     = time();

    while ($row = $res->fetch_assoc()) {
        // Derive clean arrival_status
        $paStatus = $row['arrival_status'];

        // Appointment-level status always wins for in_progress / completed
        $apptStatus = $row['appointment_status'] ?? '';
        if ($apptStatus === 'in_progress') {
            $paStatus = 'in_progress';
        } elseif ($apptStatus === 'completed') {
            $paStatus = 'served';
        } elseif ($paStatus === null) {
            // No arrival record yet — check if overdue
            if ($isToday && $row['start_time']) {
                $expectedTs = strtotime($date . ' ' . $row['start_time']);
                $paStatus   = ($expectedTs && ($now - $expectedTs) > 900) ? 'overdue' : 'pending';
            } else {
                $paStatus = 'pending';
            }
        }

        $rows[] = [
            'appointment_id'     => (int)$row['appointment_id'],
            'patient_id'         => (int)$row['patient_id'],
            'doctor_id'          => (int)$row['doctor_id'],
            'timeslot_id'        => (int)$row['ts_id'],
            'start_time'         => $row['start_time'],
            'end_time'           => $row['end_time'],
            'patient_name'       => $row['patient_name'] ?? '—',
            'patient_phone'      => $row['patient_phone'] ?? '',
            'doctor_name'        => $row['doctor_name'] ?? '—',
            'service_name'       => $row['service_name'] ?? '—',
            'duration_minutes'   => (int)$row['duration_minutes'],
            'reason'             => $row['reason'] ?? '',
            'appointment_status' => $row['appointment_status'],
            'is_emergency'       => (bool)$row['is_emergency'],
            'arrival_status'     => $paStatus,
            'arrived_at'         => $row['arrived_at'],
            'expected_time'      => $row['expected_time'] ?? $row['start_time'],
            'arrival_id'         => $row['arrival_id'] ? (int)$row['arrival_id'] : null,
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'schedule' => $rows, 'date' => $date]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
