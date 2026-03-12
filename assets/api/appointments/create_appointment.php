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

    $patient_id            = isset($_POST['patient_id'])            ? (int)$_POST['patient_id']            : 0;
    $appointment_date      = isset($_POST['appointment_date'])      ? trim($_POST['appointment_date'])      : '';
    $user_id               = isset($_POST['user_id'])               ? (int)$_POST['user_id']               : 0;
    $reason                = isset($_POST['reason'])                ? trim($_POST['reason'])                : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : 0;
    $is_emergency          = isset($_POST['is_emergency'])          ? (int)$_POST['is_emergency']          : 0;
    $notes                 = isset($_POST['notes'])                 ? trim($_POST['notes'])                 : '';
    $status                = isset($_POST['status'])                ? trim($_POST['status'])                : 'scheduled';
    $timeslot_id           = isset($_POST['timeslot_id'])           ? (int)$_POST['timeslot_id']           : 0;
    $service_id            = isset($_POST['service_id'])            ? (int)$_POST['service_id']            : 0;
    $allow_duplicate_day   = isset($_POST['allow_duplicate_day'])   ? (int)$_POST['allow_duplicate_day']   : 0;

    $requester_role = $_SESSION['role'] ?? '';

    if (empty($patient_id) || empty($appointment_date) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Patient, doctor and appointment date are required!']);
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

    $allowedStatus = ['scheduled', 'confirmed'];
    if (!in_array($status, $allowedStatus, true)) {
        $status = 'scheduled';
    }

    // Verify doctor exists and is active
    $doc_stmt = $conn->prepare('SELECT user_id, is_active, role_id FROM users WHERE user_id = ? LIMIT 1');
    $doc_stmt->bind_param('i', $user_id);
    $doc_stmt->execute();
    $doc = $doc_stmt->get_result()->fetch_assoc();
    $doc_stmt->close();
    if (!$doc || (int)($doc['is_active'] ?? 0) !== 1 || (int)($doc['role_id'] ?? 0) !== 2) {
        echo json_encode(['success' => false, 'message' => 'Selected doctor is not available. Please choose another doctor.']);
        exit;
    }

    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $user_id, $appointment_date);
    if (!$bookable) {
        echo json_encode(['success' => false, 'message' => $bookableMsg]);
        exit;
    }

    // Verify patient exists
    $pat_stmt = $conn->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
    $pat_stmt->bind_param('i', $patient_id);
    $pat_stmt->execute();
    $patient = $pat_stmt->get_result()->fetch_assoc();
    $pat_stmt->close();
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Selected patient not found.']);
        exit;
    }

    appt_ensure_standard_timeslots_for_doctor_date($conn, $user_id, $appointment_date);

    // Timeslot is required for all new bookings.
    if ($timeslot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a 20-minute time slot.']);
        exit;
    }

    // Verify selected timeslot is still available
    if ($timeslot_id > 0) {
        $ts_stmt = $conn->prepare(
            "SELECT timeslot_id, status, doctor_id, slot_date FROM timeslot WHERE timeslot_id = ? LIMIT 1"
        );
        $ts_stmt->bind_param('i', $timeslot_id);
        $ts_stmt->execute();
        $ts = $ts_stmt->get_result()->fetch_assoc();
        $ts_stmt->close();

        if (!$ts) {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot not found.']);
            exit;
        }
        if ($ts['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Selected timeslot is no longer available. Please choose another time.']);
            exit;
        }
        if ((int)$ts['doctor_id'] !== $user_id) {
            echo json_encode(['success' => false, 'message' => 'Timeslot does not belong to the selected doctor.']);
            exit;
        }
        if ($ts['slot_date'] !== $appointment_date) {
            echo json_encode(['success' => false, 'message' => 'Timeslot date does not match selected appointment date.']);
            exit;
        }
    }

    // Check for duplicate patient booking on same date
    $dup_stmt = $conn->prepare(
        'SELECT COUNT(*) AS cnt FROM appointments WHERE patient_id = ? AND appointment_date = ? AND status != "cancelled"'
    );
    $dup_stmt->bind_param('is', $patient_id, $appointment_date);
    $dup_stmt->execute();
    $dup_row = $dup_stmt->get_result()->fetch_assoc();
    $dup_stmt->close();
    if ((int)($dup_row['cnt'] ?? 0) > 0) {
        $isSecretary = (strtolower((string)$requester_role) === 'secretary');
        if ($isSecretary && $allow_duplicate_day !== 1) {
            echo json_encode([
                'success' => false,
                'warning' => true,
                'code' => 'duplicate_day_warning',
                'message' => 'Patient already has an appointment on this date. Submit again to confirm override.'
            ]);
            exit;
        }

        if (!$isSecretary) {
            echo json_encode(['success' => false, 'message' => 'Patient already has an appointment on the selected date.']);
            exit;
        }
    }

    $isPatientRequester = isset($_SESSION['patient_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'patient');

    if ($isPatientRequester) {
        $is_online_appointment = 1;
    }

    // Appointment service is selected later by the doctor during in-clinic consultation.
    if ($isPatientRequester || strtolower($requester_role) === 'secretary') {
        $service_id = 0;
    }

    if (!$isPatientRequester && strtolower($requester_role) === 'secretary') {
        $status = 'confirmed';
    }

    $conn->begin_transaction();

    // Insert appointment with timeslot_id and service_id
    $ts_id_val  = $timeslot_id > 0 ? $timeslot_id : null;
    $svc_id_val = $service_id  > 0 ? $service_id  : null;

    $sql  = "INSERT INTO appointments
             (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id, is_emergency, timeslot_id, service_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare insert failed: ' . $conn->error);
    $stmt->bind_param(
        'ississiiii',
        $patient_id, $appointment_date, $reason, $is_online_appointment,
        $notes, $status, $user_id, $is_emergency, $ts_id_val, $svc_id_val
    );
    if (!$stmt->execute()) throw new Exception('Insert failed: ' . $stmt->error);
    $appointment_id = (int)$conn->insert_id;
    $stmt->close();

    // Mark the timeslot as booked
    if ($timeslot_id > 0) {
        $tbUpd = $conn->prepare(
            "UPDATE timeslot SET status = 'booked', appointment_id = ? WHERE timeslot_id = ? AND status = 'available'"
        );
        $tbUpd->bind_param('ii', $appointment_id, $timeslot_id);
        $tbUpd->execute();
        if ($tbUpd->affected_rows === 0) {
            $tbUpd->close();
            throw new Exception('Selected timeslot is no longer available. Please choose another time.');
        }
        $tbUpd->close();

        // If confirmed, create patient_arrivals pending record
        if ($status === 'confirmed') {
            $tsSel = $conn->prepare('SELECT start_time FROM timeslot WHERE timeslot_id = ? LIMIT 1');
            $tsSel->bind_param('i', $timeslot_id);
            $tsSel->execute();
            $tsData = $tsSel->get_result()->fetch_assoc();
            $tsSel->close();
            $expectedTime = $tsData['start_time'] ?? '00:00:00';
            $paIns = $conn->prepare(
                "INSERT INTO patient_arrivals (appointment_id, timeslot_id, expected_time, status)
                 VALUES (?, ?, ?, 'pending')"
            );
            $paIns->bind_param('iis', $appointment_id, $timeslot_id, $expectedTime);
            $paIns->execute();
            $paIns->close();
        }
    }

    $conn->commit();

    $responseMsg = ($status === 'confirmed')
        ? 'Appointment created and confirmed.'
        : 'Appointment created successfully! Awaiting secretary approval.';

    echo json_encode([
        'success'        => true,
        'message'        => $responseMsg,
        'appointment_id' => $appointment_id,
        'status'         => $status,
    ]);

} catch (Exception $e) {
    if ($conn && $conn->connect_errno === 0) {
        try { $conn->rollback(); } catch (Exception $re) {}
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
