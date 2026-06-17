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
        $extra    = ' AND COALESCE(a.user_id, t.doctor_id) = ?';
        $params[] = $doctor_id;
        $types   .= 'i';
    }

    $sql = "
        SELECT
            a.appointment_id,
            a.patient_id,
            COALESCE(a.user_id, t.doctor_id) AS doctor_id,
            svc.service_id,
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
            svc.service_name,
            COALESCE(svc.duration_minutes, 30) AS duration_minutes,
                        t.start_time    AS expected_time
        FROM appointments a
        INNER JOIN timeslot t        ON t.timeslot_id  = a.timeslot_id
        LEFT  JOIN patients p        ON p.patient_id   = a.patient_id
        LEFT  JOIN users d           ON d.user_id      = a.user_id
        LEFT  JOIN (
            SELECT
                ss.appointment_id,
                MIN(ss.service_id) AS service_id,
                GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_name,
                MAX(COALESCE(ts.duration_minutes, s.default_duration_minutes, 30)) AS duration_minutes
            FROM selected_services ss
            LEFT JOIN services s ON s.service_id = ss.service_id
            LEFT JOIN (
                SELECT t1.service_id, t1.duration_minutes
                FROM timeslot_service t1
                INNER JOIN (
                    SELECT service_id, MAX(id) AS max_id
                    FROM timeslot_service
                    GROUP BY service_id
                ) t2 ON t2.max_id = t1.id
            ) ts ON ts.service_id = ss.service_id
            GROUP BY ss.appointment_id
        ) svc ON svc.appointment_id = a.appointment_id
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
        $paStatus = null;

        // Appointment-level status always wins for in_progress / completed
        $apptStatus = $row['appointment_status'] ?? '';
        if ($apptStatus === 'in_progress') {
            $paStatus = 'in_progress';
        } elseif (in_array($apptStatus, ['completed', 'awaiting_secretary_records', 'records_complete'], true)) {
            $paStatus = 'served';
        } elseif ($apptStatus === 'checked_in') {
            $paStatus = 'arrived';
        } elseif ($apptStatus === 'no_show') {
            $paStatus = 'no_show';
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
            'arrived_at'         => null,
            'expected_time'      => $row['expected_time'] ?? $row['start_time'],
            'arrival_id'         => null,
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'schedule' => $rows, 'date' => $date]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
