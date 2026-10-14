<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

// JSON-only responses: some hosts (e.g. InfinityFree) force display_errors on
// regardless of ini_set above, which would otherwise leak a stray PHP notice/
// warning into the response body and break the caller's res.json() parsing.
// Buffer everything and guarantee clean JSON no matter what happens below.
ob_start();

session_start();
header('Content-Type: application/json');

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // respect @-suppressed errors
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function ($e) {
    error_log('get_timeslots.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code(500);
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'Internal server error', 'slots' => []]);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('get_timeslots.php shutdown fatal: ' . print_r($err, true));
        if (!headers_sent()) header('Content-Type: application/json');
        http_response_code(500);
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Internal server error', 'slots' => []]);
    }
});

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include __DIR__ . '/availability_guard.php';

// Discard any warnings/notices buffered while loading the includes above.
if (ob_get_level()) ob_end_clean();

$doctor_id  = isset($_GET['doctor_id'])  ? (int)$_GET['doctor_id']  : 0;
$date       = isset($_GET['date'])       ? trim($_GET['date'])       : '';
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$duration   = isset($_GET['duration'])   ? (int)$_GET['duration']   : 0;

if (!$date) {
    echo json_encode(['success' => false, 'message' => 'date is required']);
    exit;
}

if (!appt_validate_appointment_date($date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Nothing can be booked in the past. Today, a time is offered only while its slot is
// still running (a walk-in can take the slot that started a few minutes ago).
if ($date < date('Y-m-d')) {
    echo json_encode(['success' => true, 'slots' => [], 'message' => 'This date has already passed.', 'duration' => $duration]);
    exit;
}
$slotCutoff = $date === date('Y-m-d') ? date('H:i:s') : null;
$slotStillOpen = function ($endTime) use ($slotCutoff) {
    return $slotCutoff === null || (string)$endTime > $slotCutoff;
};

// ── No-doctor mode: merged slots across all available doctors ────────────
if (!$doctor_id) {
    // Find all active doctors who are available on this date
    $docRes = $conn->query("SELECT user_id FROM users WHERE role_id = 2 AND is_active = 1");
    $availableDoctorIds = [];
    if ($docRes) {
        while ($dRow = $docRes->fetch_assoc()) {
            $did = (int)$dRow['user_id'];
            [$ok] = appt_assert_doctor_bookable($conn, $did, $date);
            if ($ok) {
                $availableDoctorIds[] = $did;
                appt_ensure_standard_timeslots_for_doctor_date($conn, $did, $date);
            }
        }
    }

    if (empty($availableDoctorIds)) {
        echo json_encode(['success' => true, 'slots' => [], 'message' => 'No doctors available on this date.', 'duration' => $duration]);
        exit;
    }

    // Derive minimum duration
    $minDuration = $duration > 0 ? $duration : 0;
    if ($minDuration <= 0 && $service_id) {
        $dStmt = $conn->prepare("SELECT COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS dur FROM services s LEFT JOIN timeslot_service ts ON ts.service_id = s.service_id WHERE s.service_id = ? LIMIT 1");
        $dStmt->bind_param('i', $service_id);
        $dStmt->execute();
        $dRow = $dStmt->get_result()->fetch_assoc();
        $dStmt->close();
        $minDuration = (int)($dRow['dur'] ?? 30);
    }

    // Query all available timeslots for all available doctors, grouped by start_time
    $placeholders = implode(',', array_fill(0, count($availableDoctorIds), '?'));
    $types = str_repeat('i', count($availableDoctorIds)) . 's';
    $bindParams = array_merge($availableDoctorIds, [$date]);

    $sql = "
        SELECT t.start_time, t.end_time,
               COUNT(*) AS spots_total,
               SUM(t.status = 'available') AS spots_available,
               GROUP_CONCAT(CASE WHEN t.status = 'available' THEN t.timeslot_id END ORDER BY t.doctor_id) AS available_ids
        FROM timeslot t
        WHERE t.doctor_id IN ($placeholders)
          AND t.slot_date = ?
        GROUP BY t.start_time, t.end_time
        HAVING spots_available > 0
        ORDER BY t.start_time ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$bindParams);
    $stmt->execute();
    $res = $stmt->get_result();

    $mergedSlots = [];
    while ($row = $res->fetch_assoc()) {
        if (!$slotStillOpen($row['end_time'])) continue;
        $firstAvailableId = (int)(explode(',', $row['available_ids'])[0]);
        $spotsAvail = (int)$row['spots_available'];
        $spotsTotal = (int)$row['spots_total'];
        $slotMinutes = (int)((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60);

        $label = date('g:i A', strtotime($row['start_time']))
               . ' – '
               . date('g:i A', strtotime($row['end_time']))
               . ' (' . $spotsAvail . ' slot' . ($spotsAvail !== 1 ? 's' : '') . ')';

        $mergedSlots[] = [
            'timeslot_id'      => $firstAvailableId,
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'duration_minutes' => $slotMinutes,
            'spots_available'  => $spotsAvail,
            'spots_total'      => $spotsTotal,
            'label'            => $label,
        ];
    }
    $stmt->close();

    // If duration > 0, group consecutive merged slots into ranges
    $slots = [];
    if ($minDuration <= 0) {
        $slots = $mergedSlots;
    } else {
        $count = count($mergedSlots);
        for ($i = 0; $i < $count; $i++) {
            $accMinutes = 0;
            $startTime = $mergedSlots[$i]['start_time'];
            $endTime = $mergedSlots[$i]['end_time'];
            $accMinutes += $mergedSlots[$i]['duration_minutes'];
            $prevEnd = $mergedSlots[$i]['end_time'];
            $minSpots = $mergedSlots[$i]['spots_available'];
            $firstId = $mergedSlots[$i]['timeslot_id'];

            if ($accMinutes < $minDuration) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($mergedSlots[$j]['start_time'] !== $prevEnd) break;
                    $accMinutes += $mergedSlots[$j]['duration_minutes'];
                    $prevEnd = $mergedSlots[$j]['end_time'];
                    $endTime = $mergedSlots[$j]['end_time'];
                    $minSpots = min($minSpots, $mergedSlots[$j]['spots_available']);
                    if ($accMinutes >= $minDuration) break;
                }
            }

            if ($accMinutes >= $minDuration) {
                $slots[] = [
                    'timeslot_id'      => $firstId,
                    'start_time'       => $startTime,
                    'end_time'         => $endTime,
                    'duration_minutes' => $accMinutes,
                    'spots_available'  => $minSpots,
                    'label'            => date('g:i A', strtotime($startTime))
                                        . ' – '
                                        . date('g:i A', strtotime($endTime))
                                        . ' (' . $minSpots . ' slot' . ($minSpots !== 1 ? 's' : '') . ')',
                ];
            }
        }
    }

    $message = empty($slots) ? 'No available slots for the selected services on this date.' : '';
    echo json_encode(['success' => true, 'slots' => $slots, 'message' => $message, 'duration' => $minDuration]);
    exit;
}

// ── Single-doctor mode (existing behavior) ───────────────────────────────
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
        if (!$slotStillOpen($row['end_time'])) continue;
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
