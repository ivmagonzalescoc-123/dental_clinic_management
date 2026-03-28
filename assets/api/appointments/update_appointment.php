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
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $appointment_date = isset($_POST['appointment_date']) ? trim($_POST['appointment_date']) : '';
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : null;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $timeslot_id = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;

    if (empty($appointment_id)) {
        echo json_encode(['success' => false, 'message' => 'Appointment ID is required!']);
        exit;
    }

    $curStmt = $conn->prepare('SELECT appointment_id, user_id, appointment_date, reason, is_online_appointment, notes, status, timeslot_id FROM appointments WHERE appointment_id = ? LIMIT 1');
    if (!$curStmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $curStmt->bind_param('i', $appointment_id);
    $curStmt->execute();
    $current = $curStmt->get_result()->fetch_assoc();
    $curStmt->close();

    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
        exit;
    }

    if ($user_id <= 0) {
        $user_id = (int)$current['user_id'];
    }
    if ($appointment_date === '') {
        $appointment_date = (string)$current['appointment_date'];
    }
    if ($reason === '') {
        $reason = (string)$current['reason'];
    }
    if ($notes === null) {
        $notes = (string)$current['notes'];
    }
    if ($is_online_appointment === null) {
        $is_online_appointment = (int)$current['is_online_appointment'];
    }

    $allowedStatus = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'];
    if ($status === '') {
        $status = (string)$current['status'];
    } elseif (!in_array($status, $allowedStatus, true)) {
        $status = (string)$current['status'];
    }

    if ($timeslot_id <= 0) {
        $timeslot_id = (int)($current['timeslot_id'] ?? 0);
    }

    if (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Doctor is required!']);
        exit;
    }

    if (!appt_validate_appointment_date($appointment_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment date. Use YYYY-MM-DD format.']);
        exit;
    }

    [$reasonOk, $reasonResult] = appt_validate_reason($reason);
    if (!$reasonOk) {
        echo json_encode(['success' => false, 'message' => $reasonResult]);
        exit;
    }
    $reason = $reasonResult;

    [$notesOk, $notesResult] = appt_validate_notes($notes);
    if (!$notesOk) {
        echo json_encode(['success' => false, 'message' => $notesResult]);
        exit;
    }
    $notes = $notesResult;

    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $user_id, $appointment_date);
    if (!$bookable) {
        echo json_encode(['success' => false, 'message' => $bookableMsg]);
        exit;
    }

    appt_ensure_standard_timeslots_for_doctor_date($conn, $user_id, $appointment_date);

    $currentTimeslotId = (int)($current['timeslot_id'] ?? 0);
    $timeslotChanged = ($timeslot_id > 0 && $timeslot_id !== $currentTimeslotId);
    $doctorChanged = ((int)$current['user_id'] !== (int)$user_id);
    $dateChanged = ((string)$current['appointment_date'] !== (string)$appointment_date);

    if (($doctorChanged || $dateChanged) && !$timeslotChanged) {
        echo json_encode(['success' => false, 'message' => 'Please select a new available timeslot for the new date/doctor.']);
        exit;
    }

    if ($timeslot_id > 0) {
        $tsStmt = $conn->prepare('SELECT timeslot_id, doctor_id, slot_date, status FROM timeslot WHERE timeslot_id = ? LIMIT 1');
        if (!$tsStmt) {
            throw new Exception('Timeslot prepare failed: ' . $conn->error);
        }
        $tsStmt->bind_param('i', $timeslot_id);
        $tsStmt->execute();
        $ts = $tsStmt->get_result()->fetch_assoc();
        $tsStmt->close();

        if (!$ts) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot not found.']);
            exit;
        }
        if ((int)$ts['doctor_id'] !== (int)$user_id) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot does not belong to this doctor.']);
            exit;
        }
        if ((string)$ts['slot_date'] !== (string)$appointment_date) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot date does not match appointment date.']);
            exit;
        }

        if ($timeslotChanged && (string)$ts['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot is already taken. Please choose another slot.']);
            exit;
        }
    }

    $conn->begin_transaction();

    try {
        if ($timeslotChanged && $currentTimeslotId > 0) {
            $freeOld = $conn->prepare("UPDATE timeslot SET status = 'available' WHERE timeslot_id = ? AND status = 'booked'");
            if (!$freeOld) throw new Exception('Prepare free-old failed: ' . $conn->error);
            $freeOld->bind_param('i', $currentTimeslotId);
            $freeOld->execute();
            $freeOld->close();
        }

        if ($timeslotChanged && $timeslot_id > 0) {
            $bookNew = $conn->prepare("UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'");
            if (!$bookNew) throw new Exception('Prepare book-new failed: ' . $conn->error);
            $bookNew->bind_param('i', $timeslot_id);
            $bookNew->execute();
            if ($bookNew->affected_rows === 0) {
                $bookNew->close();
                throw new Exception('Selected timeslot is already taken. Please choose another slot.');
            }
            $bookNew->close();
        }

        $update_sql = "UPDATE appointments SET user_id = ?, appointment_date = ?, reason = ?, is_online_appointment = ?, notes = ?, status = ?, timeslot_id = ? WHERE appointment_id = ?";
        $update_stmt = $conn->prepare($update_sql);

        if (!$update_stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        if (!$update_stmt->bind_param("issiisii", $user_id, $appointment_date, $reason, $is_online_appointment, $notes, $status, $timeslot_id, $appointment_id)) {
            throw new Exception('Bind failed: ' . $update_stmt->error);
        }

        if (!$update_stmt->execute()) {
            throw new Exception('Execute failed: ' . $update_stmt->error);
        }
        $update_stmt->close();

        $conn->commit();
    } catch (Exception $txe) {
        $conn->rollback();
        throw $txe;
    }

    echo json_encode(['success' => true, 'message' => 'Appointment updated successfully!', 'appointment_id' => $appointment_id, 'timeslot_id' => $timeslot_id]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
