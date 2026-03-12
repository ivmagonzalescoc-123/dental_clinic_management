<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    if (!$conn) throw new Exception('Database connection failed');

    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $user_id        = isset($_POST['user_id'])        ? (int)$_POST['user_id']        : 0; // doctor
    $days_ahead     = isset($_POST['days_ahead'])     ? (int)$_POST['days_ahead']     : 14;
    $service_id     = isset($_POST['service_id'])     ? (int)$_POST['service_id']     : 0;
    $duration       = isset($_POST['duration'])       ? (int)$_POST['duration']       : 30;

    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Doctor (user_id) is required.']);
        exit;
    }

    // Resolve duration from timeslot_service if service_id provided
    if ($service_id > 0) {
        $durStmt = $conn->prepare(
            "SELECT COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS dur
             FROM services s
             LEFT JOIN timeslot_service ts ON ts.service_id = s.service_id
             WHERE s.service_id = ? LIMIT 1"
        );
        $durStmt->bind_param('i', $service_id);
        $durStmt->execute();
        $durRow = $durStmt->get_result()->fetch_assoc();
        $durStmt->close();
        if ($durRow) $duration = (int)$durRow['dur'];
    }

    // If booking a specific slot
    if ($appointment_id && isset($_POST['book_timeslot_id'])) {
        $timeslot_id = (int)$_POST['book_timeslot_id'];

        $conn->begin_transaction();

        // Ensure timeslot is still available
        $tsStmt = $conn->prepare(
            "SELECT timeslot_id, slot_date, start_time, end_time, status
             FROM timeslot WHERE timeslot_id = ? AND doctor_id = ? LIMIT 1"
        );
        $tsStmt->bind_param('ii', $timeslot_id, $user_id);
        $tsStmt->execute();
        $ts = $tsStmt->get_result()->fetch_assoc();
        $tsStmt->close();

        if (!$ts || $ts['status'] !== 'available') {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Selected timeslot is no longer available.']);
            exit;
        }

        // Mark timeslot as booked
        $tbUpd = $conn->prepare(
            "UPDATE timeslot SET status = 'booked', appointment_id = ? WHERE timeslot_id = ?"
        );
        $tbUpd->bind_param('ii', $appointment_id, $timeslot_id);
        $tbUpd->execute();
        $tbUpd->close();

        // Link timeslot_id onto appointment
        $aUpd = $conn->prepare(
            "UPDATE appointments SET timeslot_id = ?, appointment_date = ?, service_id = COALESCE(?, service_id) WHERE appointment_id = ?"
        );
        $aUpd->bind_param('isii', $timeslot_id, $ts['slot_date'], $service_id ?: null, $appointment_id);
        $aUpd->execute();
        $aUpd->close();

        // Create schedules row for EHR linkage
        $schStart = $ts['slot_date'] . ' ' . $ts['start_time'];
        $schEnd   = $ts['slot_date'] . ' ' . $ts['end_time'];
        $insS = $conn->prepare(
            "INSERT INTO schedules (appointment_id, user_id, scheduled_start, scheduled_end, status)
             VALUES (?, ?, ?, ?, 'scheduled')"
        );
        $insS->bind_param('iiss', $appointment_id, $user_id, $schStart, $schEnd);
        $insS->execute();
        $schedule_id = (int)$conn->insert_id;
        $insS->close();

        $conn->commit();
        echo json_encode([
            'success'     => true,
            'message'     => 'Timeslot booked successfully',
            'schedule_id' => $schedule_id,
            'timeslot_id' => $timeslot_id,
            'start'       => $schStart,
            'end'         => $schEnd,
        ]);
        exit;
    }

    // Return list of available timeslots for the doctor
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d', strtotime("+{$days_ahead} days"));

    $params = [$user_id, $start_date, $end_date];
    $types  = 'iss';
    $extra  = '';

    if ($duration > 0) {
        $extra    = ' AND TIMESTAMPDIFF(MINUTE, CONCAT(slot_date,\' \',start_time), CONCAT(slot_date,\' \',end_time)) >= ?';
        $params[] = $duration;
        $types   .= 'i';
    }

    $slotStmt = $conn->prepare(
        "SELECT timeslot_id, slot_date, start_time, end_time
         FROM timeslot
         WHERE doctor_id = ? AND slot_date BETWEEN ? AND ? AND status = 'available'
         {$extra}
         ORDER BY slot_date ASC, start_time ASC
         LIMIT 20"
    );
    $slotStmt->bind_param($types, ...$params);
    $slotStmt->execute();
    $res   = $slotStmt->get_result();
    $slots = [];
    while ($row = $res->fetch_assoc()) {
        $slots[] = [
            'timeslot_id' => (int)$row['timeslot_id'],
            'start'       => $row['slot_date'] . ' ' . $row['start_time'],
            'end'         => $row['slot_date'] . ' ' . $row['end_time'],
            'slot_date'   => $row['slot_date'],
            'start_time'  => $row['start_time'],
            'end_time'    => $row['end_time'],
        ];
    }
    $slotStmt->close();

    echo json_encode(['success' => true, 'slots' => $slots, 'duration' => $duration]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
