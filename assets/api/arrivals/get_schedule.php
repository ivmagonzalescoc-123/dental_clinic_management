<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['secretary', 'doctor', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$date      = isset($_GET['date'])      ? trim($_GET['date'])         : date('Y-m-d');
$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id']    : 0;

// Doctors only see their own patients
if ($role === 'doctor') {
    $doctor_id = (int)($_SESSION['user_id'] ?? 0);
}

try {
    $sql = "SELECT
                a.appointment_id,
                a.appointment_date,
                a.reason,
                a.status        AS appointment_status,
                svc.service_id,
                a.is_emergency,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.phone_number,
                CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                u.user_id       AS doctor_id,
                svc.service_name,
                t.timeslot_id,
                t.start_time,
                t.end_time,
                t.status        AS timeslot_status,
                                t.start_time    AS expected_time
            FROM appointments a
            INNER JOIN timeslot t  ON t.timeslot_id = a.timeslot_id
            LEFT  JOIN patients p  ON p.patient_id  = a.patient_id
            LEFT  JOIN users    u  ON u.user_id     = a.user_id
            LEFT  JOIN (
                SELECT
                    ss.appointment_id,
                    MIN(ss.service_id) AS service_id,
                    GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_name
                FROM selected_services ss
                LEFT JOIN services s ON s.service_id = ss.service_id
                GROUP BY ss.appointment_id
            ) svc ON svc.appointment_id = a.appointment_id
            WHERE t.slot_date = ?
              AND a.status NOT IN ('cancelled','rescheduled')";

    $params = [$date];
    $types  = 's';

    if ($doctor_id > 0) {
        $sql    .= ' AND a.user_id = ?';
        $params[] = $doctor_id;
        $types  .= 'i';
    }

    $sql .= ' ORDER BY t.start_time ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Query prepare error: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows    = [];
    $now     = new DateTime();
    $isToday = ($date === date('Y-m-d'));

    while ($row = $res->fetch_assoc()) {
        $row['arrived_at'] = null;
        $row['arrival_id'] = null;
        $row['arrival_notes'] = null;
        $row['expected_time'] = $row['expected_time'] ?? $row['start_time'];

        $apptStatus = (string)($row['appointment_status'] ?? '');
        if ($apptStatus === 'in_progress') {
            $row['arrival_status'] = 'in_progress';
        } elseif ($apptStatus === 'completed') {
            $row['arrival_status'] = 'served';
        } elseif ($apptStatus === 'checked_in') {
            $row['arrival_status'] = 'arrived';
        } elseif ($apptStatus === 'no_show') {
            $row['arrival_status'] = 'no_show';
        } elseif ($isToday && $row['appointment_status'] !== 'completed') {
            $expected = DateTime::createFromFormat('H:i:s', $row['start_time']);
            if ($expected) {
                $diff = $now->getTimestamp() - $expected->getTimestamp();
                $row['arrival_status'] = ($diff > 900) ? 'overdue' : 'pending';
            } else {
                $row['arrival_status'] = 'pending';
            }
        } else {
            $row['arrival_status'] = 'pending';
        }
        $rows[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'date' => $date, 'schedule' => $rows]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
