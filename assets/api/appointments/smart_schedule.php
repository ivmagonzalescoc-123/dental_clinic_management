<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include __DIR__ . '/availability_guard.php';
    if (!$conn) throw new Exception('Database connection failed');

    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $user_id        = isset($_POST['user_id'])        ? (int)$_POST['user_id']        : 0; // doctor
    $days_ahead     = isset($_POST['days_ahead'])     ? (int)$_POST['days_ahead']     : 14;
    // Backward-compatible: older clients may still pass service_id for duration/selection.
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

        [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $user_id, $ts['slot_date']);
        if (!$bookable) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $bookableMsg]);
            exit;
        }

        // Mark timeslot as booked
        $tbUpd = $conn->prepare(
            "UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'"
        );
        $tbUpd->bind_param('i', $timeslot_id);
        $tbUpd->execute();
        if ($tbUpd->affected_rows === 0) {
            $tbUpd->close();
            throw new Exception('Selected timeslot is no longer available.');
        }
        $tbUpd->close();

        // Link timeslot_id onto appointment
        $aUpd = $conn->prepare(
            "UPDATE appointments SET timeslot_id = ?, appointment_date = ? WHERE appointment_id = ?"
        );
        $aUpd->bind_param('isi', $timeslot_id, $ts['slot_date'], $appointment_id);
        $aUpd->execute();
        $aUpd->close();

        // Preserve legacy service payload by recording it on selected_services.
        if ($service_id > 0) {
            $chkSel = $conn->prepare(
                'SELECT selected_service_id FROM selected_services WHERE appointment_id = ? AND service_id = ? LIMIT 1'
            );
            $exists = false;
            if ($chkSel) {
                $chkSel->bind_param('ii', $appointment_id, $service_id);
                $chkSel->execute();
                $exists = (bool)$chkSel->get_result()->fetch_assoc();
                $chkSel->close();
            }
            if (!$exists) {
                $legacyNote = 'Preselected during scheduling';
                $insSel = $conn->prepare(
                    'INSERT INTO selected_services (appointment_id, service_id, notes) VALUES (?, ?, ?)'
                );
                if ($insSel) {
                    $insSel->bind_param('iis', $appointment_id, $service_id, $legacyNote);
                    $insSel->execute();
                    $insSel->close();
                }
            }
        }

        // Create patient_schedules row for EHR linkage
        $schStart = $ts['slot_date'] . ' ' . $ts['start_time'];
        $schEnd   = $ts['slot_date'] . ' ' . $ts['end_time'];
        $insS = $conn->prepare(
            "INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status)
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

    // Timeslots are generated lazily/on-demand elsewhere in the app (e.g. when a
    // date is opened in the booking calendar) — without this, most future dates
    // simply have zero rows in `timeslot` yet, so the query below silently
    // returns nothing and "Suggest slots" looks broken even though it isn't.
    $cursorDate = new DateTime($start_date);
    $endDateObj = new DateTime($end_date);
    while ($cursorDate <= $endDateObj) {
        appt_ensure_standard_timeslots_for_doctor_date($conn, $user_id, $cursorDate->format('Y-m-d'));
        $cursorDate->modify('+1 day');
    }

    // Individual `timeslot` rows are fine-grained (e.g. 10-minute increments), so a
    // single row's own span is almost never >= the requested appointment duration.
    // Instead of filtering rows by their own span, walk each day's available rows in
    // order and accumulate consecutive (back-to-back) slots until they cover the
    // requested duration — mirrors the merging logic already used in get_timeslots.php.
    $slotStmt = $conn->prepare(
        "SELECT timeslot_id, slot_date, start_time, end_time,
                TIMESTAMPDIFF(MINUTE, start_time, end_time) AS slot_minutes
         FROM timeslot
         WHERE doctor_id = ? AND slot_date BETWEEN ? AND ? AND status = 'available'
         ORDER BY slot_date ASC, start_time ASC"
    );
    $slotStmt->bind_param('iss', $user_id, $start_date, $end_date);
    $slotStmt->execute();
    $res = $slotStmt->get_result();
    $byDate = [];
    while ($row = $res->fetch_assoc()) {
        $byDate[$row['slot_date']][] = $row;
    }
    $slotStmt->close();

    $slots = [];
    foreach ($byDate as $slotDate => $rawSlots) {
        [$bookable] = appt_assert_doctor_bookable($conn, $user_id, $slotDate);
        if (!$bookable) {
            continue;
        }

        $count = count($rawSlots);
        for ($i = 0; $i < $count; $i++) {
            $accMinutes = (int)$rawSlots[$i]['slot_minutes'];
            $startTime  = $rawSlots[$i]['start_time'];
            $endTime    = $rawSlots[$i]['end_time'];
            $prevEnd    = $rawSlots[$i]['end_time'];

            if ($duration > 0 && $accMinutes < $duration) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($rawSlots[$j]['start_time'] !== $prevEnd) {
                        break;
                    }
                    $accMinutes += (int)$rawSlots[$j]['slot_minutes'];
                    $prevEnd = $rawSlots[$j]['end_time'];
                    $endTime = $rawSlots[$j]['end_time'];
                    if ($accMinutes >= $duration) {
                        break;
                    }
                }
            }

            if ($duration > 0 && $accMinutes < $duration) {
                continue;
            }

            $slots[] = [
                'timeslot_id' => (int)$rawSlots[$i]['timeslot_id'],
                'start'       => $slotDate . ' ' . $startTime,
                'end'         => $slotDate . ' ' . $endTime,
                'slot_date'   => $slotDate,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
            ];

            if (count($slots) >= 20) {
                break 2;
            }
        }
    }

    echo json_encode(['success' => true, 'slots' => $slots, 'duration' => $duration]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
