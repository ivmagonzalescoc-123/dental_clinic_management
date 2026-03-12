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
    // If service given, get its duration to filter out slots too short
    $minDuration = 0;
    if ($service_id) {
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
    $slots = [];
    while ($row = $res->fetch_assoc()) {
        $slotMins = (int)$row['slot_minutes'];
        // Skip slots that are shorter than the service requires
        if ($minDuration > 0 && $slotMins < $minDuration) continue;
        $slots[] = [
            'timeslot_id'      => (int)$row['timeslot_id'],
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'duration_minutes' => $slotMins,
            'label'            => date('g:i A', strtotime($row['start_time']))
                                . ' – '
                                . date('g:i A', strtotime($row['end_time'])),
        ];
    }
    $stmt->close();

    $message = empty($slots)
        ? 'No available 20-minute slots for this doctor/date.'
        : '';
    echo json_encode(['success' => true, 'slots' => $slots, 'message' => $message]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
