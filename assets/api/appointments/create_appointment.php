<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
    include __DIR__ . '/availability_guard.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';
    if (!$conn) throw new Exception('Database connection failed');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
        exit;
    }

    if (!isset($_SESSION['user_id']) && !isset($_SESSION['patient_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in again.']);
        exit;
    }
    capabilities_require_json('appointment.create');

    $patient_id            = isset($_POST['patient_id'])            ? (int)$_POST['patient_id']            : 0;
    $appointment_date      = isset($_POST['appointment_date'])      ? trim($_POST['appointment_date'])      : '';
    $user_id               = isset($_POST['user_id'])               ? (int)$_POST['user_id']               : 0;
    $reason                = isset($_POST['reason'])                ? trim($_POST['reason'])                : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : 0;
    $is_emergency          = isset($_POST['is_emergency'])          ? (int)$_POST['is_emergency']          : 0;
    $notes                 = isset($_POST['notes'])                 ? trim($_POST['notes'])                 : '';
    $status                = isset($_POST['status'])                ? trim($_POST['status'])                : 'scheduled';
    $timeslot_id           = isset($_POST['timeslot_id'])           ? (int)$_POST['timeslot_id']           : 0;
    // Backward-compatible: some older clients may still post service_id.
    $service_id            = isset($_POST['service_id'])            ? (int)$_POST['service_id']            : 0;
    // New: support multiple selected services
    $selected_service_ids  = isset($_POST['selected_service_ids'])  ? $_POST['selected_service_ids']       : [];
    $allow_duplicate_day   = isset($_POST['allow_duplicate_day'])   ? (int)$_POST['allow_duplicate_day']   : 0;

    $requester_role = $_SESSION['role'] ?? '';
    $isPatientRequester = isset($_SESSION['patient_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'patient');

    if ($isPatientRequester) {
        $sessionPatientId = (int)($_SESSION['patient_id'] ?? 0);
        if ($sessionPatientId <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Patient session expired. Please log in again.']);
            exit;
        }
        if ($patient_id !== $sessionPatientId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only create appointments for your own account.']);
            exit;
        }
    }

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

    // Compute total duration based on selected services
    $total_duration_minutes = 0;
    if (is_array($selected_service_ids) && count($selected_service_ids) > 0) {
        $service_ids_clean = array_values(array_filter(array_map('intval', $selected_service_ids)));
        if (count($service_ids_clean) > 0) {
            $in = implode(',', $service_ids_clean);
            $durSql = "
                SELECT COALESCE(ts.duration_minutes, s.default_duration_minutes, 20) AS duration_minutes
                FROM services s
                LEFT JOIN timeslot_service ts ON ts.service_id = s.service_id
                WHERE s.service_id IN ($in)
            ";
            $durRes = $conn->query($durSql);
            if ($durRes) {
                while ($row = $durRes->fetch_assoc()) {
                    $total_duration_minutes += (int)($row['duration_minutes'] ?? 0);
                }
            }
        }
    }
    if ($total_duration_minutes <= 0 && $service_id > 0) {
        $durStmt = $conn->prepare(
            "SELECT COALESCE(ts.duration_minutes, s.default_duration_minutes, 20) AS duration_minutes
             FROM services s
             LEFT JOIN timeslot_service ts ON ts.service_id = s.service_id
             WHERE s.service_id = ? LIMIT 1"
        );
        if ($durStmt) {
            $durStmt->bind_param('i', $service_id);
            $durStmt->execute();
            $durRow = $durStmt->get_result()->fetch_assoc();
            $durStmt->close();
            $total_duration_minutes = (int)($durRow['duration_minutes'] ?? 0);
        }
    }

    // Verify selected timeslot is still available
    if ($timeslot_id > 0) {
        $ts_stmt = $conn->prepare(
            "SELECT timeslot_id, status, doctor_id, slot_date, start_time, end_time FROM timeslot WHERE timeslot_id = ? LIMIT 1"
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

        // Validate consecutive slots for the full duration
        if ($total_duration_minutes > 20) {
            $slots_stmt = $conn->prepare(
                "SELECT timeslot_id, start_time, end_time
                 FROM timeslot
                 WHERE doctor_id = ? AND slot_date = ? AND status = 'available' AND start_time >= ?
                 ORDER BY start_time ASC"
            );
            $slots_stmt->bind_param('iss', $user_id, $appointment_date, $ts['start_time']);
            $slots_stmt->execute();
            $slots_res = $slots_stmt->get_result();

            $required_slots = [];
            $acc_minutes = 0;
            $prev_end = null;
            while ($row = $slots_res->fetch_assoc()) {
                if ($prev_end !== null && $row['start_time'] !== $prev_end) {
                    break;
                }
                $required_slots[] = (int)$row['timeslot_id'];
                $slot_minutes = (int)((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60);
                $acc_minutes += $slot_minutes;
                $prev_end = $row['end_time'];
                if ($acc_minutes >= $total_duration_minutes) {
                    break;
                }
            }
            $slots_stmt->close();

            if ($acc_minutes < $total_duration_minutes) {
                echo json_encode(['success' => false, 'message' => 'Selected start time does not have enough consecutive slots for the chosen services.']);
                exit;
            }
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

    if ($isPatientRequester) {
        $is_online_appointment = 1;
    }

    if (!$isPatientRequester && strtolower($requester_role) === 'secretary') {
        $status = 'confirmed';
    }

    $conn->begin_transaction();

    // Insert appointment (service is linked via selected_services, not appointments.service_id)
    $sql  = "INSERT INTO appointments
             (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id, is_emergency, timeslot_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare insert failed: ' . $conn->error);
    $stmt->bind_param(
        'ississiii',
        $patient_id, $appointment_date, $reason, $is_online_appointment,
        $notes, $status, $user_id, $is_emergency, $timeslot_id
    );
    if (!$stmt->execute()) throw new Exception('Insert failed: ' . $stmt->error);
    $appointment_id = (int)$conn->insert_id;
    $stmt->close();

    // Preserve legacy payload support by storing any preselected service in selected_services.
    if ($service_id > 0) {
        $legacyNote = 'Preselected during booking';
        $ssStmt = $conn->prepare(
            'INSERT INTO selected_services (appointment_id, service_id, notes) VALUES (?, ?, ?)'
        );
        if ($ssStmt) {
            $ssStmt->bind_param('iis', $appointment_id, $service_id, $legacyNote);
            if (!$ssStmt->execute()) {
                $ssStmt->close();
                throw new Exception('Failed to save selected service: ' . $conn->error);
            }
            $ssStmt->close();
        }
    }
    
    // Save multiple selected services (new format)
    if (is_array($selected_service_ids) && count($selected_service_ids) > 0) {
        $ssStmt = $conn->prepare('INSERT INTO selected_services (appointment_id, service_id) VALUES (?, ?)');
        if (!$ssStmt) throw new Exception('Prepare failed: ' . $conn->error);
        
        foreach ($selected_service_ids as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                $ssStmt->bind_param('ii', $appointment_id, $sid);
                if (!$ssStmt->execute()) {
                    $ssStmt->close();
                    throw new Exception('Failed to save selected service: ' . $conn->error);
                }
            }
        }
        $ssStmt->close();
    }

    // Mark the required timeslots as booked
    if ($timeslot_id > 0) {
        $slots_to_book = [$timeslot_id];
        if ($total_duration_minutes > 20) {
            $slots_stmt = $conn->prepare(
                "SELECT timeslot_id, start_time, end_time
                 FROM timeslot
                 WHERE doctor_id = ? AND slot_date = ? AND status = 'available' AND start_time >= ?
                 ORDER BY start_time ASC"
            );
            $slots_stmt->bind_param('iss', $user_id, $appointment_date, $ts['start_time']);
            $slots_stmt->execute();
            $slots_res = $slots_stmt->get_result();

            $slots_to_book = [];
            $acc_minutes = 0;
            $prev_end = null;
            while ($row = $slots_res->fetch_assoc()) {
                if ($prev_end !== null && $row['start_time'] !== $prev_end) {
                    break;
                }
                $slots_to_book[] = (int)$row['timeslot_id'];
                $slot_minutes = (int)((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60);
                $acc_minutes += $slot_minutes;
                $prev_end = $row['end_time'];
                if ($acc_minutes >= $total_duration_minutes) {
                    break;
                }
            }
            $slots_stmt->close();

            if ($acc_minutes < $total_duration_minutes) {
                throw new Exception('Selected timeslot does not have enough consecutive availability.');
            }
        }

        $placeholders = implode(',', array_fill(0, count($slots_to_book), '?'));
        $types = str_repeat('i', count($slots_to_book));
        $tbUpd = $conn->prepare(
            "UPDATE timeslot SET status = 'booked' WHERE timeslot_id IN ($placeholders) AND status = 'available'"
        );
        $tbUpd->bind_param($types, ...$slots_to_book);
        $tbUpd->execute();
        if ($tbUpd->affected_rows < count($slots_to_book)) {
            $tbUpd->close();
            throw new Exception('Selected timeslot is no longer available. Please choose another time.');
        }
        $tbUpd->close();

        // Arrival/check-in flow is now represented by appointments.status.
    }

    $conn->commit();

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'appointment.create',
            'appointments',
            $appointment_id,
            'Created appointment with status=' . $status . ', doctor_id=' . $user_id . ', timeslot_id=' . $timeslot_id,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }

    wf_notify_appointment_created($conn, $appointment_id, $patient_id, $user_id, $appointment_date, $status);

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
