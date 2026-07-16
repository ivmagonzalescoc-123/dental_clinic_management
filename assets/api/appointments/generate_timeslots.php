<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include __DIR__ . '/availability_guard.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'secretary', 'doctor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$doctor_id     = isset($_POST['doctor_id'])     ? (int)$_POST['doctor_id']     : 0;
$from_date     = isset($_POST['from_date'])     ? trim($_POST['from_date'])     : date('Y-m-d');
$to_date       = isset($_POST['to_date'])       ? trim($_POST['to_date'])       : date('Y-m-d', strtotime('+14 days'));
$slot_duration = isset($_POST['slot_duration']) ? (int)$_POST['slot_duration'] : APPT_BASE_SLOT_MINUTES;

if (!$doctor_id) {
    echo json_encode(['success' => false, 'message' => 'doctor_id is required']);
    exit;
}

if ($slot_duration < 10 || $slot_duration > 480) $slot_duration = APPT_BASE_SLOT_MINUTES;

try {
    // Load availability
    $avail = $conn->prepare("SELECT weekday, start_time, end_time FROM doctor_availability WHERE user_id = ?");
    $avail->bind_param('i', $doctor_id);
    $avail->execute();
    $availRes    = $avail->get_result();
    $availability = [];
    while ($r = $availRes->fetch_assoc()) {
        $availability[(int)$r['weekday']][] = ['start' => $r['start_time'], 'end' => $r['end_time']];
    }
    $avail->close();

    if (empty($availability)) {
        echo json_encode(['success' => false, 'message' => 'No availability configured for this doctor']);
        exit;
    }

    $created = 0;
    $skipped = 0;
    $period  = new DatePeriod(
        new DateTime($from_date),
        new DateInterval('P1D'),
        (new DateTime($to_date))->modify('+1 day')
    );

    foreach ($period as $day) {
        $weekday  = (int)$day->format('w');
        if (empty($availability[$weekday])) continue;
        $slotDate = $day->format('Y-m-d');

        if (appt_has_doctor_leave($conn, $doctor_id, $slotDate)) {
            continue;
        }

        foreach ($availability[$weekday] as $block) {
            $cursor = new DateTime($slotDate . ' ' . $block['start']);
            $endDt  = new DateTime($slotDate . ' ' . $block['end']);

            while (($cursor->getTimestamp() + $slot_duration * 60) <= $endDt->getTimestamp()) {
                $startTime = $cursor->format('H:i:s');
                $cursor->modify("+{$slot_duration} minutes");
                $endTime = $cursor->format('H:i:s');

                $ins = $conn->prepare("
                    INSERT IGNORE INTO timeslot (doctor_id, slot_date, start_time, end_time, status)
                    VALUES (?, ?, ?, ?, 'available')
                ");
                $ins->bind_param('isss', $doctor_id, $slotDate, $startTime, $endTime);
                $ins->execute();
                if ($ins->affected_rows > 0) {
                    $created++;
                } else {
                    $skipped++;
                }
                $ins->close();
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "{$created} timeslots generated" . ($skipped ? ", {$skipped} already existed" : ''),
        'created' => $created,
        'skipped' => $skipped,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
