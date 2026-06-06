<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include __DIR__ . '/availability_guard.php';

$doctor_id  = isset($_GET['doctor_id'])  ? (int)$_GET['doctor_id']  : 0;
$date       = isset($_GET['date'])       ? trim($_GET['date'])       : '';
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$duration   = isset($_GET['duration'])   ? (int)$_GET['duration']   : 0;

if (!$doctor_id || !$date) {
    echo json_encode(['success' => false, 'message' => 'doctor_id and date are required']);
    exit;
}

if (!appt_validate_appointment_date($date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

[$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $doctor_id, $date);
if (!$bookable) {
    echo json_encode(['success' => true, 'slots' => [], 'message' => $bookableMsg]);
    exit;
}

appt_ensure_standard_timeslots_for_doctor_date($conn, $doctor_id, $date);

try {
    // If a duration is provided, use it directly. Otherwise derive from the service.
    $minDuration = $duration > 0 ? $duration : 0;
    if ($minDuration <= 0 && $service_id) {
        $dStmt = $conn->prepare("
            SELECT COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS dur
            FROM services s
            LEFT JOIN timeslot_service ts ON ts.service_id = s.service_id
            WHERE s.service_id = ?
            LIMIT 1
        ");
        $dStmt->bind_param('i', $service_id);
        $dStmt->execute();
        $dRow = $dStmt->get_result()->fetch_assoc();
        $dStmt->close();
        $minDuration = (int)($dRow['dur'] ?? 30);
    }

    $sql = "
        SELECT t.timeslot_id, t.start_time, t.end_time, t.status,
               TIMESTAMPDIFF(MINUTE, t.start_time, t.end_time) AS slot_minutes
        FROM timeslot t
        WHERE t.doctor_id = ?
          AND t.slot_date  = ?
          AND t.status     = 'available'
        ORDER BY t.start_time ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $doctor_id, $date);
    $stmt->execute();
    $res   = $stmt->get_result();
    $rawSlots = [];
    while ($row = $res->fetch_assoc()) {
        $rawSlots[] = [
            'timeslot_id' => (int)$row['timeslot_id'],
            'start_time'  => $row['start_time'],
            'end_time'    => $row['end_time'],
            'slot_minutes'=> (int)$row['slot_minutes']
        ];
    }
    $stmt->close();

    $slots = [];
    if ($minDuration <= 0) {
        foreach ($rawSlots as $row) {
            $slots[] = [
                'timeslot_id'      => (int)$row['timeslot_id'],
                'start_time'       => $row['start_time'],
                'end_time'         => $row['end_time'],
                'duration_minutes' => (int)$row['slot_minutes'],
                'label'            => date('g:i A', strtotime($row['start_time']))
                                    . ' – '
                                    . date('g:i A', strtotime($row['end_time'])),
            ];
        }
    } else {
        $count = count($rawSlots);
        for ($i = 0; $i < $count; $i++) {
            $accMinutes = 0;
            $startTime = $rawSlots[$i]['start_time'];
            $endTime = $rawSlots[$i]['end_time'];
            $accMinutes += $rawSlots[$i]['slot_minutes'];
            $prevEnd = $rawSlots[$i]['end_time'];

            if ($accMinutes < $minDuration) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($rawSlots[$j]['start_time'] !== $prevEnd) {
                        break;
                    }
                    $accMinutes += $rawSlots[$j]['slot_minutes'];
                    $prevEnd = $rawSlots[$j]['end_time'];
                    $endTime = $rawSlots[$j]['end_time'];
                    if ($accMinutes >= $minDuration) {
                        break;
                    }
                }
            }

            if ($accMinutes >= $minDuration) {
                $slots[] = [
                    'timeslot_id'      => (int)$rawSlots[$i]['timeslot_id'],
                    'start_time'       => $startTime,
                    'end_time'         => $endTime,
                    'duration_minutes' => $accMinutes,
                    'label'            => date('g:i A', strtotime($startTime))
                                        . ' – '
                                        . date('g:i A', strtotime($endTime)),
                ];
            }
        }
    }

    $message = empty($slots)
        ? 'No available slots for the selected services on this date.'
        : '';
    echo json_encode(['success' => true, 'slots' => $slots, 'message' => $message, 'duration' => $minDuration]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
