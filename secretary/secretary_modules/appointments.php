<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/capabilities.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';
include dirname(dirname(__DIR__)) . '/assets/api/appointments/availability_guard.php';
include_once dirname(dirname(__DIR__)) . '/config/workflow_notifications.php';
security_ensure_work_type_column($conn);
$csrf_token = security_generate_csrf_token();

$conn->query("CREATE TABLE IF NOT EXISTS appointment_reschedule_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    requested_doctor_id INT NULL,
    requested_date DATE NOT NULL,
    requested_timeslot_id INT NOT NULL,
    request_note VARCHAR(500) NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    review_note VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_arr_status_created (status, created_at),
    KEY idx_arr_appointment_status (appointment_id, status),
    KEY idx_arr_patient_status (patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$action = $_GET['action'] ?? '';
$view = $_GET['view'] ?? 'all'; // 'all' | 'today' | 'tomorrow' | 'one_hour' | 'missed' | 'no_show' | 'reschedule' | 'records_queue'
$search = $_GET['search'] ?? '';

function appointment_can_transition_secretary(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }

    $matrix = [
        'scheduled' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['arrived', 'checked_in', 'cancelled', 'no_show'],
        'arrived' => ['in_progress', 'cancelled'],
        'checked_in' => ['in_progress', 'no_show'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    return in_array($to, $matrix[$from] ?? [], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    capabilities_require_web('appointment.manage', 'secretary.php?page=appointments&view=' . urlencode($view));

    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Invalid security token. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
if (!in_array($view, ['all', 'today', 'tomorrow', 'one_hour', 'missed', 'no_show', 'reschedule', 'records_queue'], true)) {
    $view = 'all';
}

if ($action === 'approve_patient_reschedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    if ($requestId <= 0) {
        $_SESSION['message'] = 'Invalid reschedule request.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    try {
        $reqStmt = $conn->prepare("SELECT r.request_id, r.appointment_id, r.patient_id, r.requested_doctor_id, r.requested_date, r.requested_timeslot_id, r.status,
                                          a.user_id AS current_doctor_id, a.appointment_date, a.timeslot_id AS current_timeslot_id, a.status AS appointment_status
                                   FROM appointment_reschedule_requests r
                                   INNER JOIN appointments a ON a.appointment_id = r.appointment_id
                                   WHERE r.request_id = ?
                                   LIMIT 1");
        if (!$reqStmt) throw new Exception('Unable to load request.');
        $reqStmt->bind_param('i', $requestId);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();
        $reqStmt->close();

        if (!$req) throw new Exception('Reschedule request not found.');
        if ((string)$req['status'] !== 'pending') throw new Exception('Request is no longer pending.');

        $targetDoctorId = (int)($req['requested_doctor_id'] ?: $req['current_doctor_id']);
        $targetDate = (string)$req['requested_date'];
        $targetTimeslotId = (int)$req['requested_timeslot_id'];
        $currentTimeslotId = (int)($req['current_timeslot_id'] ?? 0);

        [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $targetDoctorId, $targetDate);
        if (!$bookable) throw new Exception($bookableMsg);

        $slotStmt = $conn->prepare('SELECT timeslot_id, doctor_id, slot_date, status FROM timeslot WHERE timeslot_id = ? LIMIT 1');
        if (!$slotStmt) throw new Exception('Unable to validate requested slot.');
        $slotStmt->bind_param('i', $targetTimeslotId);
        $slotStmt->execute();
        $slot = $slotStmt->get_result()->fetch_assoc();
        $slotStmt->close();

        if (!$slot) throw new Exception('Requested timeslot not found.');
        if ((int)$slot['doctor_id'] !== $targetDoctorId || (string)$slot['slot_date'] !== $targetDate) {
            throw new Exception('Requested timeslot does not match doctor/date.');
        }
        if ((string)$slot['status'] !== 'available' && $targetTimeslotId !== $currentTimeslotId) {
            throw new Exception('Requested timeslot is already taken.');
        }

        $conn->begin_transaction();

        if ($currentTimeslotId > 0 && $currentTimeslotId !== $targetTimeslotId) {
            $freeOld = $conn->prepare("UPDATE timeslot SET status = 'available' WHERE timeslot_id = ? AND status = 'booked'");
            if (!$freeOld) throw new Exception('Failed to free old timeslot.');
            $freeOld->bind_param('i', $currentTimeslotId);
            $freeOld->execute();
            $freeOld->close();
        }

        if ($targetTimeslotId !== $currentTimeslotId) {
            $bookNew = $conn->prepare("UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'");
            if (!$bookNew) throw new Exception('Failed to book requested timeslot.');
            $bookNew->bind_param('i', $targetTimeslotId);
            $bookNew->execute();
            if ($bookNew->affected_rows === 0) {
                $bookNew->close();
                throw new Exception('Requested timeslot is already taken.');
            }
            $bookNew->close();
        }

        $appStatus = strtolower((string)$req['appointment_status']);
        if (!in_array($appStatus, ['scheduled', 'confirmed', 'missed'], true)) {
            throw new Exception('Only scheduled/confirmed/missed appointments can be rescheduled. Current status: ' . $appStatus);
        }

        $updatedStatus = ($appStatus === 'missed') ? 'confirmed' : $appStatus;

        $updAppt = $conn->prepare('UPDATE appointments SET user_id = ?, appointment_date = ?, timeslot_id = ?, status = ? WHERE appointment_id = ?');
        if (!$updAppt) throw new Exception('Failed to update appointment.');
        $updAppt->bind_param('isisi', $targetDoctorId, $targetDate, $targetTimeslotId, $updatedStatus, $req['appointment_id']);
        $updAppt->execute();
        $updAppt->close();

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewNote = 'Approved by secretary';
        $approvedStatus = 'approved';
        $updReq = $conn->prepare('UPDATE appointment_reschedule_requests SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE request_id = ?');
        if (!$updReq) throw new Exception('Failed to finalize request approval.');
        $updReq->bind_param('sisi', $approvedStatus, $reviewedBy, $reviewNote, $requestId);
        $updReq->execute();
        $updReq->close();

        $conn->commit();
        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.reschedule_request.approve',
                'appointment_reschedule_requests',
                $requestId,
                'Approved and applied to appointment_id=' . (int)$req['appointment_id'] . ', doctor_id=' . $targetDoctorId . ', date=' . $targetDate,
                $reviewedBy
            );
        }
        wf_notify_reschedule_decision(
            $conn,
            (int)$req['appointment_id'],
            (int)$req['patient_id'],
            $targetDoctorId,
            true,
            $targetDate
        );
        $_SESSION['message'] = 'Patient reschedule request approved and applied.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $ignore) {}
        $_SESSION['message'] = 'Failed to approve request: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}

if ($action === 'reject_patient_reschedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNote = trim((string)($_POST['review_note'] ?? 'Rejected by secretary'));
    if ($requestId <= 0) {
        $_SESSION['message'] = 'Invalid reschedule request.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    try {
        if (mb_strlen($reviewNote) > 500) {
            $reviewNote = mb_substr($reviewNote, 0, 500);
        }
        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $rejectedStatus = 'rejected';
        $pendingStatus = 'pending';

        $infoStmt = $conn->prepare('SELECT r.appointment_id, r.patient_id, r.requested_doctor_id, a.user_id AS doctor_id FROM appointment_reschedule_requests r INNER JOIN appointments a ON a.appointment_id = r.appointment_id WHERE r.request_id = ? LIMIT 1');
        if (!$infoStmt) throw new Exception('Could not load request.');
        $infoStmt->bind_param('i', $requestId);
        $infoStmt->execute();
        $reqInfo = $infoStmt->get_result()->fetch_assoc();
        $infoStmt->close();
        if (!$reqInfo) throw new Exception('Request not found.');

        $stmt = $conn->prepare('UPDATE appointment_reschedule_requests SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE request_id = ? AND status = ?');
        if (!$stmt) throw new Exception('Could not reject request.');
        $stmt->bind_param('sisis', $rejectedStatus, $reviewedBy, $reviewNote, $requestId, $pendingStatus);
        $stmt->execute();
        $affected = (int)$stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) throw new Exception('Request is not pending or already handled.');

        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.reschedule_request.reject',
                'appointment_reschedule_requests',
                $requestId,
                'Rejected by secretary. Note: ' . $reviewNote,
                $reviewedBy
            );
        }

        wf_notify_reschedule_decision(
            $conn,
            (int)$reqInfo['appointment_id'],
            (int)$reqInfo['patient_id'],
            (int)($reqInfo['requested_doctor_id'] ?: $reqInfo['doctor_id']),
            false
        );

        $_SESSION['message'] = 'Patient reschedule request rejected.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['message'] = 'Failed to reject request: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}


// Handle DECLINE (cancel appointment)
if ($action === 'decline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $reason = $_POST['decline_reason'] ?? 'Declined by secretary';
    
    if ($appointmentId <= 0) {
        $_SESSION['message'] = 'Invalid appointment.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }
    
    try {
        $curStmt = $conn->prepare('SELECT status FROM appointments WHERE appointment_id = ? LIMIT 1');
        if (!$curStmt) {
            throw new Exception('Unable to validate appointment status.');
        }
        $curStmt->bind_param('i', $appointmentId);
        $curStmt->execute();
        $cur = $curStmt->get_result()->fetch_assoc();
        $curStmt->close();

        if (!$cur) {
            throw new Exception('Appointment not found.');
        }

        $currentStatus = strtolower((string)($cur['status'] ?? 'scheduled'));
        $status = 'cancelled';
        if (!appointment_can_transition_secretary($currentStatus, $status)) {
            throw new Exception('Invalid status transition: ' . $currentStatus . ' -> ' . $status . '.');
        }

        $stmt = $conn->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->bind_param('ssi', $status, $reason, $appointmentId);
            $stmt->execute();
            $stmt->close();
        }

        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.decline',
                'appointments',
                $appointmentId,
                'Declined by secretary. Reason: ' . $reason,
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
        }
        
        $_SESSION['message'] = 'Appointment declined and cancelled.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error declining appointment: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}


// Handle APPROVE (secretary confirms/approves appointment)
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        $_SESSION['message'] = 'Invalid appointment.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    // Get appointment details
    $stmt = $conn->prepare('SELECT appointment_date, status, patient_id, user_id FROM appointments WHERE appointment_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $appt = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$appt) {
        $_SESSION['message'] = 'Appointment not found.';
        $_SESSION['message_type'] = 'danger';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    $apptDate = $appt['appointment_date'];
    $currentStatus = strtolower((string)($appt['status'] ?? 'scheduled'));
    if (!appointment_can_transition_secretary($currentStatus, 'confirmed')) {
        $_SESSION['message'] = 'Cannot approve appointment from current status: ' . $currentStatus . '.';
        $_SESSION['message_type'] = 'danger';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    // Count already approved/confirmed for that date
    $cap_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = ? AND status IN ('confirmed','arrived','checked_in','in_progress','completed')");
    if ($cap_stmt) {
        $cap_stmt->bind_param('s', $apptDate);
        $cap_stmt->execute();
        $cap_res = $cap_stmt->get_result();
        $cap_row = $cap_res ? $cap_res->fetch_assoc() : null;
        $cap_count = (int)($cap_row['cnt'] ?? 0);
        $cap_stmt->close();

        if ($cap_count >= 15) {
            $_SESSION['message'] = 'Cannot approve: the selected date already has maximum approved appointments.';
            $_SESSION['message_type'] = 'danger';
            header('Location: secretary.php?page=appointments&view=' . urlencode($view));
            exit;
        }
    }

    try {
        $status = 'confirmed';
        $stmt = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $status, $appointmentId);
            $stmt->execute();
            $stmt->close();
        }

        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                'appointment.approve',
                'appointments',
                $appointmentId,
                'Approved by secretary; status set to confirmed.',
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
            );
        }

        wf_notify_appointment_approved(
            $conn,
            $appointmentId,
            (int)($appt['patient_id'] ?? 0),
            (int)($appt['user_id'] ?? 0),
            (string)$apptDate
        );

        $_SESSION['message'] = 'Appointment approved.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error approving appointment: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}

// Counts for tabs - ALL APPOINTMENTS (no filtering)
$totalCount = 0;
$todayCount = 0;
$tomorrowCount = 0;
$oneHourCount = 0;
$missedCount = 0;
$noShowCount = 0;
$recordsQueueCount = 0;

$countResult = $conn->query('SELECT COUNT(*) AS c FROM appointments');
if ($countResult && ($r = $countResult->fetch_assoc())) {
    $totalCount = (int)($r['c'] ?? 0);
}

$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM appointments WHERE DATE(appointment_date) = ?');
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        $todayCount = (int)($r['c'] ?? 0);
    }
    $stmt->close();
}

$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM appointments WHERE DATE(appointment_date) = ?');
if ($stmt) {
    $stmt->bind_param('s', $tomorrow);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        $tomorrowCount = (int)($r['c'] ?? 0);
    }
    $stmt->close();
}

$oneHourSql = "SELECT COUNT(DISTINCT a.appointment_id) AS c
               FROM appointments a
               INNER JOIN patient_schedules s ON s.appointment_id = a.appointment_id
               WHERE a.status IN ('scheduled','confirmed')
                 AND s.status IN ('scheduled','rescheduled')
                 AND s.scheduled_start BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)";
$oneHourRes = $conn->query($oneHourSql);
if ($oneHourRes && ($r = $oneHourRes->fetch_assoc())) {
    $oneHourCount = (int)($r['c'] ?? 0);
}

$missedSql = "SELECT COUNT(DISTINCT a.appointment_id) AS c
              FROM appointments a
              INNER JOIN patient_schedules s ON s.appointment_id = a.appointment_id
              WHERE a.status IN ('scheduled','confirmed')
                AND s.status IN ('scheduled','rescheduled')
                AND s.scheduled_start < DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
$missedRes = $conn->query($missedSql);
if ($missedRes && ($r = $missedRes->fetch_assoc())) {
    $missedCount = (int)($r['c'] ?? 0);
}

$noShowRes = $conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'no_show'");
if ($noShowRes && ($r = $noShowRes->fetch_assoc())) {
    $noShowCount = (int)($r['c'] ?? 0);
}

$recordsQueueRes = $conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'awaiting_secretary_records'");
if ($recordsQueueRes && ($r = $recordsQueueRes->fetch_assoc())) {
    $recordsQueueCount = (int)($r['c'] ?? 0);
}

// Fetch appointments - ALL APPOINTMENTS (no filtering)
$appointments = [];
$pendingRescheduleRequests = [];

// Count verified guest bookings
$guestBookingsCount = 0;
$gbCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM bookings WHERE status = 'verified'");
if ($gbCountRes) {
    $gbRow = $gbCountRes->fetch_assoc();
    $guestBookingsCount = (int)($gbRow['cnt'] ?? 0);
}
$appointments_sql = "
    SELECT
        a.*,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        u.first_name as doctor_first_name,
        u.last_name as doctor_last_name,
        -- latest schedule info
        (SELECT s.schedule_id FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_id,
        (SELECT s.status FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_status,
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_start,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_end,
        (SELECT cr.follow_up_date FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id ORDER BY cr.record_id DESC LIMIT 1) AS record_follow_up_date,
        (SELECT COUNT(*) FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id) AS has_history
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON a.user_id = u.user_id
";

if ($view === 'today') {
    $appointments_sql .= " WHERE DATE(a.appointment_date) = CURDATE() ";
} elseif ($view === 'tomorrow') {
    $appointments_sql .= " WHERE DATE(a.appointment_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) ";
} elseif ($view === 'one_hour') {
    $appointments_sql .= " WHERE a.status IN ('scheduled','confirmed')
        AND EXISTS (
            SELECT 1 FROM patient_schedules s2
            WHERE s2.appointment_id = a.appointment_id
              AND s2.status IN ('scheduled','rescheduled')
              AND s2.scheduled_start BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)
        ) ";
} elseif ($view === 'missed') {
    $appointments_sql .= " WHERE a.status IN ('scheduled','confirmed')
        AND EXISTS (
            SELECT 1 FROM patient_schedules s2
            WHERE s2.appointment_id = a.appointment_id
              AND s2.status IN ('scheduled','rescheduled')
              AND s2.scheduled_start < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ) ";
} elseif ($view === 'no_show') {
    $appointments_sql .= " WHERE a.status = 'no_show' ";
}
// Fetch doctors for the booking dropdown (role_id = 2)
$doctors = [];
$doc_stmt = $conn->prepare('SELECT user_id, first_name, last_name FROM users WHERE role_id = 2 AND is_active = 1');
if ($doc_stmt) {
    $doc_stmt->execute();
    $doc_res = $doc_stmt->get_result();
    while ($d = $doc_res->fetch_assoc()) {
        $doctors[] = $d;
    }
    $doc_stmt->close();
}

// Fetch patients for the patient dropdown (secretary selection)
$patients = [];
$pat_stmt = $conn->prepare('SELECT patient_id, first_name, middle_name, last_name, email FROM patients ORDER BY last_name, first_name');
if ($pat_stmt) {
    $pat_stmt->execute();
    $pat_res = $pat_stmt->get_result();
    while ($p = $pat_res->fetch_assoc()) {
        $patients[] = $p;
    }
    $pat_stmt->close();
}

$appointments_sql .= in_array($view, ['today', 'tomorrow', 'one_hour', 'missed'], true)
    ? " ORDER BY a.appointment_date ASC, a.created_at DESC"
    : " ORDER BY a.created_at DESC";

$appointments_result = $conn->query($appointments_sql);
if ($appointments_result) {
    while ($row = $appointments_result->fetch_assoc()) {
        $appointments[] = $row;
    }
}

$pendingReqSql = "SELECT r.request_id, r.appointment_id, r.requested_date, r.requested_timeslot_id, r.request_note, r.created_at,
                         p.first_name, p.last_name,
                         COALESCE(u2.first_name, u1.first_name) AS req_doc_first,
                         COALESCE(u2.last_name, u1.last_name) AS req_doc_last,
                         t.start_time, t.end_time,
                         a.status AS appointment_status
                  FROM appointment_reschedule_requests r
                  INNER JOIN appointments a ON a.appointment_id = r.appointment_id
                  INNER JOIN patients p ON p.patient_id = r.patient_id
                  LEFT JOIN users u1 ON u1.user_id = a.user_id
                  LEFT JOIN users u2 ON u2.user_id = r.requested_doctor_id
                  LEFT JOIN timeslot t ON t.timeslot_id = r.requested_timeslot_id
                  WHERE r.status = 'pending'
                  ORDER BY r.created_at DESC";
$pendingReqRes = $conn->query($pendingReqSql);
if ($pendingReqRes) {
    while ($pr = $pendingReqRes->fetch_assoc()) {
        $pendingRescheduleRequests[] = $pr;
    }
}
?>

<?php $pendingReqCount = (int)count($pendingRescheduleRequests); ?>
<?php
$appointmentsTitleMap = [
    'all' => 'All Appointments',
    'today' => 'Today Appointments',
    'tomorrow' => 'Tomorrow Appointments',
    'one_hour' => 'Upcoming in 1 Hour',
    'missed' => 'Missed Appointments',
    'no_show' => 'No-Show Appointments',
    'reschedule' => 'Reschedule Requests',
    'records_queue' => 'Records Queue',
];
$appointmentsTitle = $appointmentsTitleMap[$view] ?? 'Appointments';

$appointmentsViewFilters = [
    'all' => ['label' => 'All Appointments', 'count' => $totalCount],
    'today' => ['label' => 'Today', 'count' => $todayCount],
    'tomorrow' => ['label' => 'Tomorrow', 'count' => $tomorrowCount],
    'one_hour' => ['label' => 'Upcoming in 1 Hour', 'count' => $oneHourCount],
    'missed' => ['label' => 'Missed', 'count' => $missedCount],
    'no_show' => ['label' => 'No-Show', 'count' => $noShowCount],
    'reschedule' => ['label' => 'Reschedule Requests', 'count' => $pendingReqCount],
    'records_queue' => ['label' => 'Records Queue', 'count' => $recordsQueueCount],
];

// Top-level section tabs
$activeSection = $_GET['section'] ?? 'appointments';
if (!in_array($activeSection, ['appointments', 'new_customers'], true)) {
    $activeSection = 'appointments';
}
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>


<!-- Top-level section tabs -->
<ul class="nav nav-tabs mb-3" id="appointmentSectionTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeSection === 'appointments' ? 'active' : ''; ?>" href="secretary.php?page=appointments&amp;section=appointments&amp;view=<?php echo urlencode($view); ?>" style="font-size:0.85rem;">
            <i class="bi bi-calendar-check"></i> Appointments
            <span class="nav-dot" id="dotAppointments" data-viewed=""></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeSection === 'new_customers' ? 'active' : ''; ?>" href="secretary.php?page=appointments&amp;section=new_customers" style="font-size:0.85rem;">
            <i class="bi bi-person-plus"></i> New Patients
            <span class="nav-dot" id="dotNewCustomers" data-count="<?php echo (int)$guestBookingsCount; ?>"></span>
        </a>
    </li>
</ul>

<?php if ($activeSection === 'new_customers'): ?>

<!-- New Customers / Guest Bookings -->
<div class="card border-0">
    
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <div class="unified-table-search-wrap">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input id="newCustomerSearch" type="search" class="form-control unified-table-search-input"
                    placeholder="Search by name, email, or phone..." aria-label="Search new customers">
                <button type="button" class="unified-table-search-clear" id="newCustomerSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="statusFilterBtn">Status: All</button>
                <ul class="dropdown-menu dropdown-menu-sm">
                    <li><a class="dropdown-item new-cust-status-filter active" href="#" data-filter="all">All</a></li>
                    <li><a class="dropdown-item new-cust-status-filter" href="#" data-filter="pending">Pending</a></li>
                    <li><a class="dropdown-item new-cust-status-filter" href="#" data-filter="processed">Processed</a></li>
                </ul>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="timeFilterBtn">Time: All</button>
                <ul class="dropdown-menu dropdown-menu-sm">
                    <li><a class="dropdown-item new-cust-time-filter active" href="#" data-filter="all">All Times</a></li>
                    <li><a class="dropdown-item new-cust-time-filter" href="#" data-filter="morning">Morning</a></li>
                    <li><a class="dropdown-item new-cust-time-filter" href="#" data-filter="afternoon">Afternoon</a></li>
                </ul>
            </div>
            <?php
                    $guestBookings = [];
                    $gbRes = $conn->query("SELECT b.*, a.appointment_id AS converted_appt_id FROM bookings b LEFT JOIN appointments a ON a.booking_id = b.booking_id WHERE b.status IN ('verified','converted') AND NOT (b.status = 'verified' AND b.preferred_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY)) ORDER BY b.created_at DESC");
                    if ($gbRes) {
                        while ($gbRow = $gbRes->fetch_assoc()) {
                            $guestBookings[] = $gbRow;
                        }
                    }

                    $archivedBookings = [];
                    $abRes = $conn->query("SELECT b.*, a.appointment_id AS converted_appt_id FROM bookings b LEFT JOIN appointments a ON a.booking_id = b.booking_id WHERE b.status = 'verified' AND b.preferred_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY) ORDER BY b.preferred_date DESC");
                    if ($abRes) {
                        while ($abRow = $abRes->fetch_assoc()) {
                            $archivedBookings[] = $abRow;
                        }
                    }
                    ?>
            <small class="text-muted">Showing <span id="newCustomerCount"><?php echo count($guestBookings); ?></span> records</small>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleArchiveBtn" onclick="toggleArchiveView()">
                <i class="bi bi-archive"></i> Archive<?php if (count($archivedBookings) > 0): ?> (<?php echo count($archivedBookings); ?>)<?php endif; ?>
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover" id="newCustomerTable" data-skip-table-search="1" data-skip-datatable="1">
                <thead>
                    <tr>
                        <th>Ref. No.</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Preferred Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($guestBookings)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                No guest bookings yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($guestBookings as $gb): ?>
                            <?php $isConverted = ($gb['status'] === 'converted'); ?>
                            <tr data-status="<?php echo $isConverted ? 'processed' : 'pending'; ?>" data-time="<?php echo htmlspecialchars($gb['preferred_time']); ?>" style="<?php echo $isConverted ? 'opacity:0.7;' : ''; ?>">
                                <td><strong>#<?php echo (int)$gb['booking_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars(trim($gb['first_name'] . ' ' . $gb['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($gb['email']); ?></td>
                                <td><?php echo htmlspecialchars($gb['phone_number'] ?? '—'); ?></td>
                                <td><span class="badge bg-primary"><?php echo date('M j, Y', strtotime($gb['preferred_date'])); ?></span></td>
                                <td><span class="badge bg-<?php echo $gb['preferred_time'] === 'morning' ? 'warning' : 'info'; ?>"><?php echo ucfirst($gb['preferred_time']); ?></span></td>
                                <td>
                                    <?php if ($isConverted): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Processed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, g:i A', strtotime($gb['created_at'])); ?></td>
                                <td>
                                    <?php if ($isConverted): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Processed</span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-success"
                                            onclick='openConvertBookingModal(<?php echo json_encode([
                                                'booking_id' => (int)$gb['booking_id'],
                                                'first_name' => $gb['first_name'],
                                                'last_name' => $gb['last_name'],
                                                'email' => $gb['email'],
                                                'phone_number' => $gb['phone_number'] ?? '',
                                                'date_of_birth' => $gb['date_of_birth'] ?? '',
                                                'preferred_date' => $gb['preferred_date'],
                                                'preferred_time' => $gb['preferred_time'],
                                                'reason' => $gb['reason'] ?? '',
                                            ]); ?>)'>
                                            <i class="bi bi-arrow-right-circle"></i> Process
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Archived Bookings (hidden by default) -->
<div class="card border-0 mt-3" id="archiveSection" style="display:none;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0"><i class="bi bi-archive"></i> Archived Bookings (overdue, unprocessed)</h6>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleArchiveView()">
            <i class="bi bi-arrow-left"></i> Back to Active
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Ref. No.</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Preferred Date</th>
                        <th>Time</th>
                        <th>Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($archivedBookings)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-3">No archived bookings.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($archivedBookings as $ab): ?>
                            <tr style="opacity:0.6;">
                                <td><strong>#<?php echo (int)$ab['booking_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars(trim($ab['first_name'] . ' ' . $ab['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($ab['email']); ?></td>
                                <td><?php echo htmlspecialchars($ab['phone_number'] ?? '—'); ?></td>
                                <td><?php echo date('M j, Y', strtotime($ab['preferred_date'])); ?></td>
                                <td><?php echo ucfirst($ab['preferred_time']); ?></td>
                                <td><?php echo date('M j, g:i A', strtotime($ab['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Process Booking to Appointment Modal -->
<div class="modal fade" id="convertBookingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2 px-3">
                <h5 class="modal-title" style="font-size:0.9rem;">
                    <i class="bi bi-arrow-right-circle"></i> Process New Booked Appointment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="convertBookingForm">
                <input type="hidden" id="convertBookingId" name="booking_id">
                <div class="modal-body py-2 px-3">
                    <div class="alert alert-info mb-2 py-2 px-3" style="font-size:0.82rem;">
                        <strong>Patient:</strong> <span id="convertBookingPatientInfo"></span>
                    </div>

                    <!-- 2-Column: Doctor + Services -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label fw-bold" style="font-size:0.8rem;">Select Doctor *</label>
                            <select id="convertDoctorSelect" name="user_id" class="form-select form-select-sm" required style="font-size:0.82rem;">
                                <option value="">-- Select Doctor --</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold" style="font-size:0.8rem;">Add Service</label>
                            <div class="input-group input-group-sm">
                                <select id="convertServiceSelect" class="form-select form-select-sm" style="font-size:0.82rem;">
                                    <option value="">-- Select Service --</option>
                                </select>
                                <button type="button" class="btn btn-success btn-sm" onclick="addProcessService()" style="font-size:0.78rem;">
                                    <i class="bi bi-plus"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Services Table -->
                    <div class="mb-2">
                        <label class="form-label fw-bold" style="font-size:0.8rem;">Selected Services</label>
                        <div class="table-responsive" style="max-height:180px;overflow-y:auto;">
                            <table class="table table-sm table-bordered mb-0" id="processServicesTable">
                                <thead class="table-light" style="position:sticky;top:0;">
                                    <tr style="font-size:0.75rem;">
                                        <th>Service Name</th>
                                        <th style="width:90px;">Est. Time</th>
                                        <th style="width:90px;">Price</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="processServicesBody">
                                    <tr id="processNoServicesRow">
                                        <td colspan="4" class="text-center text-muted py-2" style="font-size:0.8rem;">
                                            Consultation Only — No additional services
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot id="processServicesFooter" style="display:none;">
                                    <tr style="font-size:0.8rem;">
                                        <td colspan="2" class="text-end fw-bold">Total:</td>
                                        <td class="fw-bold" id="processTotalPrice">₱0</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <small class="text-muted" style="font-size:0.7rem;" id="processTotalTime">Estimated: 0 minutes</small>
                    </div>

                    <!-- Start Time -->
                    <div class="mb-2">
                        <label class="form-label fw-bold" style="font-size:0.8rem;">Available Start Time *</label>
                        <select id="convertTimeslotSelect" name="timeslot_id" class="form-select form-select-sm" required disabled style="font-size:0.82rem;">
                            <option value="">-- Select Doctor first --</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="convertSubmitBtn" class="btn btn-success btn-sm">
                        <i class="bi bi-check-circle"></i> Process Booking
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// New Customer Search & Filters
var activeStatusFilter = 'all';
var activeTimeFilter = 'all';

function filterNewCustomerTable() {
    var input = document.getElementById('newCustomerSearch');
    var clearBtn = document.getElementById('newCustomerSearchClear');
    var filter = input ? input.value.toLowerCase() : '';
    var table = document.getElementById('newCustomerTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var visibleCount = 0;
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        var rowStatus = rows[i].getAttribute('data-status') || '';
        var rowTime = rows[i].getAttribute('data-time') || '';
        var matchSearch = !filter || text.indexOf(filter) > -1;
        var matchStatus = activeStatusFilter === 'all' || rowStatus === activeStatusFilter;
        var matchTime = activeTimeFilter === 'all' || rowTime === activeTimeFilter;
        var show = matchSearch && matchStatus && matchTime;
        rows[i].style.display = show ? '' : 'none';
        if (show) visibleCount++;
    }
    var countEl = document.getElementById('newCustomerCount');
    if (countEl) countEl.textContent = visibleCount;
    if (clearBtn) clearBtn.style.display = filter ? '' : 'none';
}

document.getElementById('newCustomerSearch')?.addEventListener('input', filterNewCustomerTable);
document.getElementById('newCustomerSearchClear')?.addEventListener('click', function() {
    var input = document.getElementById('newCustomerSearch');
    if (input) { input.value = ''; filterNewCustomerTable(); }
});

// Status filter dropdown
document.querySelectorAll('.new-cust-status-filter').forEach(function(item) {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.new-cust-status-filter').forEach(function(i) { i.classList.remove('active'); });
        item.classList.add('active');
        activeStatusFilter = item.getAttribute('data-filter');
        var label = activeStatusFilter === 'all' ? 'All' : activeStatusFilter.charAt(0).toUpperCase() + activeStatusFilter.slice(1);
        document.getElementById('statusFilterBtn').textContent = 'Status: ' + label;
        filterNewCustomerTable();
    });
});

// Time filter dropdown
document.querySelectorAll('.new-cust-time-filter').forEach(function(item) {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.new-cust-time-filter').forEach(function(i) { i.classList.remove('active'); });
        item.classList.add('active');
        activeTimeFilter = item.getAttribute('data-filter');
        var label = activeTimeFilter === 'all' ? 'All' : activeTimeFilter.charAt(0).toUpperCase() + activeTimeFilter.slice(1);
        document.getElementById('timeFilterBtn').textContent = 'Time: ' + label;
        filterNewCustomerTable();
    });
});

// Archive toggle
var archiveCount = <?php echo count($archivedBookings); ?>;
function toggleArchiveView() {
    var mainCard = document.getElementById('newCustomerTable')?.closest('.card');
    var archiveSection = document.getElementById('archiveSection');
    var toggleBtn = document.getElementById('toggleArchiveBtn');
    if (!mainCard || !archiveSection) return;

    if (archiveSection.style.display === 'none') {
        mainCard.style.display = 'none';
        archiveSection.style.display = '';
        if (toggleBtn) toggleBtn.innerHTML = '<i class="bi bi-arrow-left"></i> Back to Active';
    } else {
        archiveSection.style.display = 'none';
        mainCard.style.display = '';
        if (toggleBtn) toggleBtn.innerHTML = '<i class="bi bi-archive"></i> Archive' + (archiveCount > 0 ? ' (' + archiveCount + ')' : '');
    }
}

// Process Booking Modal
var convertBookingData = {};
var processSelectedServices = [];
var processServicesCatalog = [];

function openConvertBookingModal(booking) {
    convertBookingData = booking;
    processSelectedServices = [];
    document.getElementById('convertBookingId').value = booking.booking_id;
    document.getElementById('convertBookingPatientInfo').textContent =
        booking.first_name + ' ' + booking.last_name + ' | ' + booking.email + ' | ' + (booking.phone_number || 'No phone');

    // Reset services table
    renderProcessServicesTable();

    // Reset doctor and timeslot
    document.getElementById('convertDoctorSelect').value = '';
    document.getElementById('convertTimeslotSelect').innerHTML = '<option value="">-- Select Doctor first --</option>';
    document.getElementById('convertTimeslotSelect').disabled = true;
    document.getElementById('convertServiceSelect').innerHTML = '<option value="">-- Select Service --</option>';

    var modal = new bootstrap.Modal(document.getElementById('convertBookingModal'));
    modal.show();
}

// Load services when doctor changes
document.getElementById('convertDoctorSelect')?.addEventListener('change', async function() {
    var doctorId = this.value;
    var serviceSelect = document.getElementById('convertServiceSelect');
    var timeslotSelect = document.getElementById('convertTimeslotSelect');

    processSelectedServices = [];
    renderProcessServicesTable();

    if (!doctorId) {
        serviceSelect.innerHTML = '<option value="">-- Select Service --</option>';
        timeslotSelect.innerHTML = '<option value="">-- Select Doctor first --</option>';
        timeslotSelect.disabled = true;
        return;
    }

    // Load services
    try {
        var resp = await fetch('../assets/api/appointments/manage_services.php?action=services&appointment_id=0');
        var data = await resp.json();
        if (data.success && data.services) {
            processServicesCatalog = data.services;
            serviceSelect.innerHTML = '<option value="">-- Select Service --</option>';
            data.services.forEach(function(s) {
                var opt = document.createElement('option');
                opt.value = s.service_id;
                opt.textContent = s.service_name + ' — ₱' + parseFloat(s.initial_price || 0).toLocaleString();
                opt.dataset.price = s.initial_price || 0;
                opt.dataset.duration = s.default_duration_minutes || 30;
                opt.dataset.name = s.service_name;
                serviceSelect.appendChild(opt);
            });
        }
    } catch (err) {
        console.error('Failed to load services:', err);
    }

    // Load available timeslots for preferred date
    loadProcessTimeslots(doctorId, convertBookingData.preferred_date);
});

function loadProcessTimeslots(doctorId, date) {
    var timeslotSelect = document.getElementById('convertTimeslotSelect');
    if (!doctorId || !date) {
        timeslotSelect.innerHTML = '<option value="">-- Select date first --</option>';
        timeslotSelect.disabled = true;
        return;
    }
    fetch('../assets/api/appointments/get_timeslots.php?doctor_id=' + doctorId + '&date=' + date)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            timeslotSelect.innerHTML = '<option value="">-- Select Start Time --</option>';
            if (data.success && data.slots && data.slots.length > 0) {
                data.slots.forEach(function(slot) {
                    var opt = document.createElement('option');
                    opt.value = slot.timeslot_id;
                    opt.textContent = slot.label;
                    timeslotSelect.appendChild(opt);
                });
                timeslotSelect.disabled = false;
            } else {
                timeslotSelect.innerHTML = '<option value="">No available times</option>';
                timeslotSelect.disabled = true;
            }
        });
}

// Add service to table
function addProcessService() {
    var select = document.getElementById('convertServiceSelect');
    var serviceId = parseInt(select.value);
    if (!serviceId) return;

    var option = select.options[select.selectedIndex];
    var exists = processSelectedServices.find(function(s) { return s.service_id === serviceId; });
    if (exists) {
        notifySecretary('This service is already added.', 'warning');
        return;
    }

    processSelectedServices.push({
        service_id: serviceId,
        name: option.dataset.name,
        price: parseFloat(option.dataset.price || 0),
        duration: parseInt(option.dataset.duration || 30)
    });

    select.value = '';
    renderProcessServicesTable();
}

// Remove service from table
function removeProcessService(serviceId) {
    processSelectedServices = processSelectedServices.filter(function(s) { return s.service_id !== serviceId; });
    renderProcessServicesTable();
}

// Render services table
function renderProcessServicesTable() {
    var tbody = document.getElementById('processServicesBody');
    var tfoot = document.getElementById('processServicesFooter');
    var noRow = document.getElementById('processNoServicesRow');
    var totalTimeEl = document.getElementById('processTotalTime');

    tbody.innerHTML = '';

    if (processSelectedServices.length === 0) {
        tbody.innerHTML = '<tr id="processNoServicesRow"><td colspan="4" class="text-center text-muted py-2" style="font-size:0.8rem;">Consultation Only — No additional services</td></tr>';
        tfoot.style.display = 'none';
        totalTimeEl.textContent = 'Estimated: 0 minutes';
        return;
    }

    var totalPrice = 0;
    var totalTime = 0;

    processSelectedServices.forEach(function(s) {
        totalPrice += s.price;
        totalTime += s.duration;
        var tr = document.createElement('tr');
        tr.style.fontSize = '0.8rem';
        tr.innerHTML = '<td>' + escapeProcessHtml(s.name) + '</td>' +
            '<td>' + s.duration + ' min</td>' +
            '<td>₱' + s.price.toLocaleString() + '</td>' +
            '<td><button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="removeProcessService(' + s.service_id + ')" style="font-size:0.7rem;"><i class="bi bi-x"></i></button></td>';
        tbody.appendChild(tr);
    });

    document.getElementById('processTotalPrice').textContent = '₱' + totalPrice.toLocaleString();
    tfoot.style.display = '';
    totalTimeEl.textContent = 'Estimated: ' + totalTime + ' minutes';
}

function escapeProcessHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Submit handler
document.getElementById('convertBookingForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    var submitBtn = document.getElementById('convertSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing...';

    var bookingId = document.getElementById('convertBookingId').value;
    var doctorId = document.getElementById('convertDoctorSelect').value;
    var timeslotId = document.getElementById('convertTimeslotSelect').value;

    if (!doctorId) {
        notifySecretary('Please select a doctor.', 'warning');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Process Booking';
        return;
    }
    if (!timeslotId) {
        notifySecretary('Please select a start time.', 'warning');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Process Booking';
        return;
    }

    try {
        // 1. Create patient account
        var patientFd = new FormData();
        patientFd.append('first_name', convertBookingData.first_name || '');
        patientFd.append('last_name', convertBookingData.last_name || '');
        patientFd.append('email', convertBookingData.email || '');
        patientFd.append('phone_number', convertBookingData.phone_number || '');
        patientFd.append('date_of_birth', convertBookingData.date_of_birth || '');
        patientFd.append('csrf_token', window.secretaryCsrfToken || '');

        var patientResp = await fetch('../assets/api/patients/create_walkin.php', { method: 'POST', body: patientFd });
        var patientData = await patientResp.json();

        if (!patientData.success || !patientData.patient_id) {
            notifySecretary('Error creating patient: ' + (patientData.message || 'Unknown error'), 'danger');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Process Booking';
            return;
        }

        // 2. Create appointment with services and arrived status
        var apptFd = new FormData();
        apptFd.append('patient_id', patientData.patient_id);
        apptFd.append('appointment_date', convertBookingData.preferred_date || '');
        apptFd.append('user_id', doctorId);
        apptFd.append('reason', convertBookingData.reason || '');
        apptFd.append('is_online_appointment', '0');
        apptFd.append('timeslot_id', timeslotId);
        apptFd.append('status', 'checked_in');
        apptFd.append('booking_id', bookingId);
        apptFd.append('allow_duplicate_day', '1');
        apptFd.append('csrf_token', window.secretaryCsrfToken || '');

        // Add selected services
        processSelectedServices.forEach(function(s, i) {
            apptFd.append('selected_service_ids[' + i + ']', s.service_id);
        });

        var apptResp = await fetch('../assets/api/appointments/create_appointment.php', { method: 'POST', body: apptFd });
        var apptData = await apptResp.json();

        if (apptData.success) {
            // Mark booking as converted
            await fetch('../assets/api/bookings/convert_booking.php?booking_id=' + bookingId, { method: 'POST' });
            notifySecretary('Booking processed! Patient account created. Password sent to: ' + (convertBookingData.email || 'email'), 'success');
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            notifySecretary('Error creating appointment: ' + (apptData.message || 'Unknown error'), 'danger');
        }
    } catch (err) {
        notifySecretary('Error: ' + err.message, 'danger');
    }

    submitBtn.disabled = false;
    submitBtn.textContent = 'Process Booking';
});
</script>

<?php else: ?>

<!-- Appointments Section Nav -->
<nav class="module-section-nav" aria-label="Appointment views">
    <div class="module-section-nav__track module-section-nav__scroll">
        <ul class="nav nav-pills mb-0">
            <?php foreach ($appointmentsViewFilters as $filterKey => $filterOpt): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $view === $filterKey ? 'active' : ''; ?>" href="secretary.php?page=appointments&amp;section=appointments&amp;view=<?php echo urlencode($filterKey); ?>" data-subnav="<?php echo htmlspecialchars($filterKey); ?>">
                        <?php echo htmlspecialchars($filterOpt['label']); ?>
                        <?php if ($filterKey === 'records_queue' && (int)$recordsQueueCount > 0): ?>
                            <span class="nav-dot" id="dotRecordsQueue" data-count="<?php echo (int)$recordsQueueCount; ?>"></span>
                        <?php elseif ($filterKey === 'reschedule' && (int)$pendingReqCount > 0): ?>
                            <span class="nav-dot" id="dotReschedule" data-count="<?php echo (int)$pendingReqCount; ?>"></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php if ($view !== 'all'): ?>
    <div class="mb-3">
        <a href="secretary.php?page=appointments&amp;section=appointments&amp;view=all" class="btn btn-sm btn-outline-secondary">Reset to all appointments</a>
    </div>
<?php endif; ?>

<?php if ($view === 'reschedule'): ?>
<div class="card border-0 mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Pending Patient Reschedule Requests</h2>
        <span class="badge bg-warning text-dark"><?php echo $pendingReqCount; ?> pending</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Requested At</th>
                        <th>Patient</th>
                        <th>Appointment #</th>
                        <th>Requested Date</th>
                        <th>Requested Slot</th>
                        <th>Doctor</th>
                        <th>Note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingRescheduleRequests)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No pending patient reschedule requests.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingRescheduleRequests as $req): ?>
                            <?php
                                $slotText = 'TBD';
                                if (!empty($req['start_time']) && !empty($req['end_time'])) {
                                    $slotText = date('g:i A', strtotime((string)$req['start_time'])) . ' - ' . date('g:i A', strtotime((string)$req['end_time']));
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$req['created_at']); ?></td>
                                <td><?php echo htmlspecialchars(trim((string)$req['first_name'] . ' ' . (string)$req['last_name'])); ?></td>
                                <td>#<?php echo (int)$req['appointment_id']; ?></td>
                                <td><?php echo htmlspecialchars((string)$req['requested_date']); ?></td>
                                <td><?php echo htmlspecialchars($slotText); ?></td>
                                <td><?php echo htmlspecialchars(trim((string)$req['req_doc_first'] . ' ' . (string)$req['req_doc_last'])); ?></td>
                                <td><?php echo htmlspecialchars((string)($req['request_note'] ?? '')); ?></td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <form method="POST" action="secretary.php?page=appointments&action=approve_patient_reschedule&view=<?php echo urlencode($view); ?>" data-confirm="Approve and apply this reschedule request?" data-confirm-title="Approve Reschedule">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <form method="POST" action="secretary.php?page=appointments&action=reject_patient_reschedule&view=<?php echo urlencode($view); ?>" data-confirm="Reject this reschedule request?" data-confirm-title="Reject Reschedule" data-confirm-variant="danger">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">
                                            <input type="hidden" name="review_note" value="Rejected by secretary">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($view === 'records_queue'): ?>

<!-- Records Queue - Secretary Clinical Record Creation -->
<div class="card border-0 mb-3">
    <div class="card-header">
        <h2 class="card-title mb-0">
            <i class="bi bi-file-earmark-medical"></i> Records Queue - Doctor Uploads Pending Clinical Record Creation
        </h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Appointment Date</th>
                        <th>Doctor Photos</th>
                        <th>Doctor Notes</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $recordsQueueSql = "
                        SELECT a.appointment_id, a.patient_id, a.appointment_date, a.status,
                               p.first_name, p.last_name,
                               (SELECT COUNT(*) FROM appointment_media WHERE appointment_id = a.appointment_id) AS photo_count,
                               (SELECT COUNT(*) FROM appointment_doctor_notes WHERE appointment_id = a.appointment_id) AS note_count
                        FROM appointments a
                        LEFT JOIN patients p ON a.patient_id = p.patient_id
                        WHERE a.status = 'awaiting_secretary_records'
                        ORDER BY a.completed_by_doctor_at ASC
                    ";
                    $recordsQueueRes = $conn->query($recordsQueueSql);
                    $hasRecordsQueue = false;
                    
                    if ($recordsQueueRes) {
                        while ($rec = $recordsQueueRes->fetch_assoc()) {
                            $hasRecordsQueue = true;
                            $photoCount = (int)($rec['photo_count'] ?? 0);
                            $noteCount = (int)($rec['note_count'] ?? 0);
                            $patientName = trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? ''));
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($patientName); ?></strong></td>
                        <td><?php echo htmlspecialchars($rec['appointment_date']); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo $photoCount; ?> photo<?php echo $photoCount !== 1 ? 's' : ''; ?></span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $noteCount; ?> note<?php echo $noteCount !== 1 ? 's' : ''; ?></span>
                        </td>
                        <td>
                            <span class="badge bg-warning text-dark">Pending Records</span>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary" onclick="openSecretaryRecordModal(<?php echo $rec['appointment_id']; ?>, <?php echo $rec['patient_id']; ?>)">
                                <i class="bi bi-pencil-square"></i> Create Record
                            </button>
                        </td>
                    </tr>
                    <?php
                        }
                    }
                    
                    if (!$hasRecordsQueue) {
                        echo '<tr><td colspan="6" class="text-center text-muted py-4">No appointments awaiting clinical record creation.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- Appointments List -->
<div class="card border-0">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="card-title mb-0"><?php echo htmlspecialchars($appointmentsTitle); ?></h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
            <i class="bi bi-plus-circle"></i> Book New Appointment
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Patient Name</th>
                        <th>Followup</th>
                        <th>Doctor</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Date</th>
                        <th>Reason</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No appointments found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($appointments as $appointment): ?>
                            <?php
                                $isTodayAppt = false;
                                if (!empty($appointment['appointment_date'])) {
                                    $isTodayAppt = (date('Y-m-d', strtotime($appointment['appointment_date'])) === $today);
                                }
                            ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?>
                                    <?php if (!empty($appointment['is_emergency']) && $appointment['is_emergency']): ?>
                                        <span class="badge bg-danger ms-1">EMERGENCY</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $recordFollowUpDate = trim((string)($appointment['record_follow_up_date'] ?? ''));
                                        $isFollowUpAppointment = !empty($appointment['rescheduled_from']);
                                    ?>
                                    <?php if ($isFollowUpAppointment && !empty($appointment['schedule_status'])): ?>
                                        <div>
                                            <span class="badge bg-<?php echo ($appointment['schedule_status'] === 'scheduled' ? 'primary' : ($appointment['schedule_status'] === 'completed' ? 'success' : 'secondary')); ?>">
                                                <?php echo htmlspecialchars(ucfirst((string)$appointment['schedule_status'])); ?>
                                            </span>
                                            <div class="small text-muted">
                                                <?php echo htmlspecialchars($appointment['schedule_start'] ?? ''); ?>
                                                <?php echo !empty($appointment['schedule_end']) ? '&ndash; ' . htmlspecialchars($appointment['schedule_end']) : ''; ?>
                                            </div>
                                        </div>
                                    <?php elseif ($recordFollowUpDate !== ''): ?>
                                        <div class="small">
                                            <span class="badge bg-info">Follow-up</span>
                                            <div class="text-muted mt-1"><?php echo htmlspecialchars($recordFollowUpDate); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(($appointment['doctor_first_name'] ?? 'N/A') . ' ' . ($appointment['doctor_last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($appointment['email']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['reason']); ?></td>
                                <td>
                                    <?php if ($appointment['is_online_appointment']): ?>
                                        <span class="badge bg-info">Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Walk-In</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php 
                                        $isCompleted = ($appointment['status'] === 'completed');
                                        $isCancelled = ($appointment['status'] === 'cancelled');
                                        $status = $appointment['status'];
                                    ?>
                                    <?php if ($status === 'scheduled'): ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php elseif ($status === 'confirmed'): ?>
                                        <span class="badge bg-info">Confirmed</span>
                                    <?php elseif ($status === 'arrived'): ?>
                                        <span class="badge bg-success">Arrived</span>
                                    <?php elseif ($status === 'checked_in'): ?>
                                        <span class="badge bg-success">Checked-In</span>
                                    <?php elseif ($status === 'completed'): ?>
                                        <span class="badge bg-success">Completed</span>
                                    <?php elseif ($status === 'cancelled'): ?>
                                        <span class="badge bg-danger">Cancelled</span>
                                    <?php elseif ($status === 'in_progress'): ?>
                                        <span class="badge bg-primary">In Progress</span>
                                    <?php elseif ($status === 'awaiting_secretary_records'): ?>
                                        <span class="badge bg-warning text-dark">Awaiting Records</span>
                                    <?php elseif ($status === 'records_complete'): ?>
                                        <span class="badge bg-info">Records Complete</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status))); ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="appointments-actions-cell">
                                    <?php 
                                        $isDisabled = $isCompleted || $isCancelled;
                                    ?>
                                    <div class="appointments-actions-grid">
                                        
                                        
                                        <?php if ($status === 'scheduled' && !$isDisabled): ?>
                                            <button type="button" class="btn btn-sm btn-primary" onclick="confirmAppointment(<?php echo (int)$appointment['appointment_id']; ?>, <?php echo empty($appointment['user_id']) ? 'true' : 'false'; ?>)" title="Approve appointment">
                                                <i class="bi bi-check2-square"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#declineModal" onclick="setDeclineAppointment(<?php echo htmlspecialchars($appointment['appointment_id']); ?>)" title="Decline appointment">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        <?php if (!$isDisabled): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openRescheduleForAppointmentSec(<?php echo json_encode($appointment); ?>)' title="Reschedule appointment">
                                                <i class="bi bi-calendar3"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php elseif (in_array($status, ['confirmed','checked_in']) && !$isDisabled): ?>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#declineModal" onclick="setDeclineAppointment(<?php echo htmlspecialchars($appointment['appointment_id']); ?>)" title="Decline appointment">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        <?php elseif ($status === 'awaiting_secretary_records'): ?>
                                            <button type="button" class="btn btn-sm btn-success" onclick="openSecretaryRecordModal(<?php echo (int)$appointment['appointment_id']; ?>, <?php echo (int)$appointment['patient_id']; ?>)" title="Create clinical record and billing">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php
                                            $isFollowUpAppointment = !empty($appointment['rescheduled_from']);
                                            $recordFollowUpDate = trim((string)($appointment['record_follow_up_date'] ?? ''));
                                            $canRescheduleFollowup = $isFollowUpAppointment
                                                && !in_array($status, ['completed', 'cancelled', 'no_show'], true)
                                                && !empty($appointment['schedule_id'])
                                                && in_array((string)($appointment['schedule_status'] ?? ''), ['scheduled', 'rescheduled'], true);
                                        ?>
                                        <?php if ($canRescheduleFollowup): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-warning"
                                                onclick='openRescheduleModalSec(<?php echo json_encode([
                                                    "schedule_id" => (int)$appointment["schedule_id"],
                                                    "scheduled_start" => (string)($appointment["schedule_start"] ?? ""),
                                                    "scheduled_end" => (string)($appointment["schedule_end"] ?? ""),
                                                ]); ?>)'
                                                title="Reschedule follow-up schedule"
                                            >
                                                <i class="bi bi-calendar-event"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewProfilingModal" onclick="openViewProfilingModal(<?php echo (int)$appointment['patient_id']; ?>, '<?php echo htmlspecialchars($appointment['first_name']); ?>', '<?php echo htmlspecialchars($appointment['last_name']); ?>')" title="View medical history">
                                            <i class="bi bi-file-medical"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
    $secretary_scheduling_embedded = true;
    $secretary_scheduling_view = $view;
    include __DIR__ . '/scheduling.php';
?>
<?php endif; ?>

<style>
.secretary-booking-calendar {
    border: 1px solid #dbe7e4;
    border-radius: 14px;
    background: #f4f8ff;
    padding: 14px;
    overflow: hidden;
}
.secretary-booking-calendar .selected-date-chip {
    display: inline-flex;
    align-items: center;
    font-weight: 600;
    font-size: 0.85rem;
    background: #d1fae5;
    color: #065f46;
    border-radius: 999px;
    padding: 0.35rem 0.8rem;
}
.secretary-booking-calendar .calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 10px;
    font-size: 0.82rem;
    color: #334155;
}
.secretary-booking-calendar .legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.secretary-booking-calendar .legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    display: inline-block;
    margin-right: 5px;
}
.secretary-booking-calendar .legend-open { background: #16a34a; }
.secretary-booking-calendar .legend-busy { background: #f59e0b; }
.secretary-booking-calendar .legend-closed { background: #dc2626; }

#bookCalendar .fc-toolbar-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}
#bookCalendar .fc-button {
    border-radius: 10px;
}
#bookCalendar .fc-daygrid-day-frame {
    border-radius: 8px;
    transition: box-shadow 0.16s ease, transform 0.16s ease, background-color 0.16s ease;
}
#bookCalendar .fc-daygrid-day-number {
    font-weight: 700;
    color: #1f3b5a;
}
#bookCalendar .fc-daygrid-day.book-day-open .fc-daygrid-day-frame {
    background: rgba(22, 163, 74, 0.1);
}
#bookCalendar .fc-daygrid-day.book-day-busy .fc-daygrid-day-frame {
    background: rgba(245, 158, 11, 0.12);
}
#bookCalendar .fc-daygrid-day.book-day-closed .fc-daygrid-day-frame,
#bookCalendar .fc-daygrid-day.book-day-disabled .fc-daygrid-day-frame {
    background: rgba(220, 38, 38, 0.12);
}
#bookCalendar .fc-daygrid-day.book-day-selected {
    box-shadow: inset 0 0 0 2px #0f766e;
}
#bookCalendar .fc-daygrid-day.book-day-open,
#bookCalendar .fc-daygrid-day.book-day-busy {
    cursor: pointer;
}
#bookCalendar .fc-daygrid-day.book-day-open .fc-daygrid-day-frame,
#bookCalendar .fc-daygrid-day.book-day-busy .fc-daygrid-day-frame {
    cursor: pointer;
}
#bookCalendar .fc-daygrid-day.book-day-open:hover,
#bookCalendar .fc-daygrid-day.book-day-busy:hover {
    box-shadow: inset 0 0 0 2px rgba(15, 118, 110, 0.35);
    transform: translateY(-1px);
}
#bookCalendar .book-day-badge {
    display: inline-flex;
    margin-top: 4px;
    padding: 2px 6px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: #334155;
}
#bookCalendar .book-day-badge.state-open {
    color: #166534;
}
#bookCalendar .book-day-badge.state-busy {
    color: #92400e;
}
#bookCalendar .book-day-badge.state-closed {
    color: #991b1b;
}

.appointments-actions-cell {
    width: 92px;
    min-width: 92px;
}

.appointments-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 36px);
    grid-auto-rows: 32px;
    gap: 0.25rem;
    width: calc(72px + 0.25rem);
    margin: 0 auto;
    justify-content: center;
    align-content: start;
}

.appointments-actions-grid > .btn {
    width: 36px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.appointments-actions-grid > .btn i {
    font-size: 0.85rem;
}
</style>
<script src="../assets/vendor/fullcalendar/index.global.min.js"></script>

<!-- Book New Appointment Modal -->
<div class="modal fade" id="bookAppointmentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i> Book New Appointment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
                    <form id="bookAppointmentForm" method="POST" action="#" class="ux-form-shell">
                <div class="modal-body">
                    <!-- Compact top row: Patient + Doctor + Type -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label for="bookPatientSelect" class="form-label fw-semibold small">Patient</label>
                            <select id="bookPatientSelect" name="patient_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <?php $fullname = trim($p['first_name'] . ' ' . ($p['middle_name'] ? $p['middle_name'] . ' ' : '') . $p['last_name']); ?>
                                    <option value="<?php echo htmlspecialchars($p['patient_id']); ?>"><?php echo htmlspecialchars($fullname . ($p['email'] ? ' — ' . $p['email'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="bookDoctorSelect" class="form-label fw-semibold small">Doctor</label>
                            <select id="bookDoctorSelect" name="user_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Doctor --</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="bookAppointmentType" class="form-label fw-semibold small">Type</label>
                            <select id="bookAppointmentType" name="is_online_appointment" class="form-select form-select-sm" required>
                                <option value="0" selected>Walk-In</option>
                                <option value="1">Online</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div id="secretaryServiceSelector" class="service-selector">
                            <small class="text-muted">Select a doctor first to load available services</small>
                        </div>
                    </div>

                        <div class="mb-3">
                            <label for="bookAppointmentDateInput" class="form-label"><strong>Appointment Date</strong></label>
                            <input type="hidden" id="bookAppointmentDateInput" name="appointment_date" required>
                            <div class="secretary-booking-calendar">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Clinic Calendar</span>
                                    <span id="bookSelectedDateText" class="selected-date-chip">No date selected</span>
                                </div>
                                <div id="bookCalendar"></div>
                                <div class="calendar-legend">
                                    <span><span class="legend-dot legend-open"></span>Available</span>
                                    <span><span class="legend-dot legend-busy"></span>With bookings</span>
                                    <span><span class="legend-dot legend-closed"></span>Unavailable / Closed</span>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3" id="bookTimeslotWrapper" style="display:none;">
                            <label for="bookTimeslotSelect" class="form-label"><strong>Time Slot</strong></label>
                            <select id="bookTimeslotSelect" name="timeslot_id" class="form-select compact-control" required disabled>
                                <option value="">-- Select a Time Slot --</option>
                            </select>
                            <small class="text-muted">20-minute slots (8:00-12:00, 1:00-5:00). A slot is required.</small>
                        </div>

                        <div class="mb-3">
                            <label for="bookReason" class="form-label"><strong>Reason for Appointment</strong></label>
                            <textarea class="form-control" id="bookReason" name="reason" rows="3" placeholder="Enter reason for appointment" required></textarea>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="bookSubmitBtn" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Book Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Decline Appointment Modal -->
<div class="modal fade" id="declineModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle"></i> Decline Appointment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="secretary.php?page=appointments&view=all&action=decline" data-confirm="Are you sure you want to decline this appointment?" data-confirm-title="Decline Appointment" data-confirm-variant="danger" data-confirm-ok="Decline">
                <div class="modal-body">
                    <input type="hidden" id="declineAppointmentId" name="appointment_id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <div class="mb-3">
                        <label for="declineReason" class="form-label"><strong>Reason for Declining</strong></label>
                        <textarea class="form-control" id="declineReason" name="decline_reason" rows="3" placeholder="Enter reason for declining this appointment" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle"></i> Decline Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Doctor & Confirm Modal -->
<div class="modal fade" id="assignDoctorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-check"></i> Assign Doctor & Confirm</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">This appointment has no doctor assigned. Select a doctor to confirm.</p>
                <input type="hidden" id="assignDoctorApptId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Assign Doctor *</label>
                    <select id="assignDoctorSelect" class="form-select" required>
                        <option value="">-- Select Doctor --</option>
                        <?php foreach ($doctors_list as $d): ?>
                            <option value="<?php echo (int)$d['user_id']; ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Services <small class="text-muted fw-normal">(optional — doctor can decide during session)</small></label>
                    <div id="assignServiceCheckboxes">
                        <small class="text-muted">Loading services...</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="assignDoctorConfirmBtn">
                    <i class="bi bi-check2-square"></i> Confirm Appointment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- View Patient Profiling Modal -->
<div class="modal fade" id="viewProfilingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-file-medical"></i> Patient Medical History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="profilingPatientName" class="alert alert-light mb-3"></div>
                <div id="profilingContent">
                    <div class="text-center text-muted">
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        Loading medical history...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Initialize DataTable -->
<script>
    window.secretaryCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    function setDeclineAppointment(appointmentId) {
        document.getElementById('declineAppointmentId').value = appointmentId;
    }
    
    function initSecretaryAppointments() {
        function showSecretaryActionFeedback(message, type = 'info') {
            if (typeof window.showToast === 'function') {
                window.showToast(String(message || ''), type || 'info');
            }
        }

        // Handle book appointment form: booking requires existing patient_id
        const bookForm = document.getElementById('bookAppointmentForm');
        if (bookForm) {
            // Patient selection (single dropdown)
            const bookPatientSelect = document.getElementById('bookPatientSelect');
            if (bookPatientSelect) {
                // keep the select value as the patient id submitted by the form
                bookPatientSelect.addEventListener('change', function() {
                    // no-op: value is read directly on submit
                });
            }

            // Confirm appointment via AJAX (calls confirm_appointment.php)
            window.confirmAppointment = async function(appointmentId, needsDoctorAssignment) {
                if (needsDoctorAssignment) {
                    // Show doctor assignment modal
                    document.getElementById('assignDoctorApptId').value = appointmentId;
                    document.getElementById('assignDoctorSelect').value = '';

                    // Load services into checkboxes
                    const svcContainer = document.getElementById('assignServiceCheckboxes');
                    svcContainer.innerHTML = '<small class="text-muted">Loading services...</small>';
                    try {
                        const svcResp = await fetch('../assets/api/appointments/manage_services.php?action=services&appointment_id=0');
                        const svcData = await svcResp.json();
                        if (svcData.success && Array.isArray(svcData.services) && svcData.services.length > 0) {
                            svcContainer.innerHTML = svcData.services.map(s =>
                                '<div class="form-check">' +
                                '<input class="form-check-input" type="checkbox" value="' + s.service_id + '" id="assignSvc_' + s.service_id + '">' +
                                '<label class="form-check-label" for="assignSvc_' + s.service_id + '">' + (s.service_name || '') + ' — ₱' + Number(s.initial_price || 0).toLocaleString() + '</label>' +
                                '</div>'
                            ).join('');
                        } else {
                            svcContainer.innerHTML = '<small class="text-muted">No services available.</small>';
                        }
                    } catch (e) {
                        svcContainer.innerHTML = '<small class="text-muted">Failed to load services.</small>';
                    }

                    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('assignDoctorModal'));
                    modal.show();
                } else {
                    // Standard confirm (doctor already assigned)
                    if (!(await showConfirmModal({
                        title: 'Approve Appointment',
                        message: 'Approve and schedule this appointment?',
                        confirmText: 'Approve'
                    }))) return;
                    try {
                        const fd = new FormData();
                        fd.append('appointment_id', appointmentId);
                        fd.append('csrf_token', secretaryCsrfToken);
                        const resp = await fetch('../assets/api/appointments/confirm_appointment.php', { method: 'POST', body: fd });
                        const j = await resp.json();
                        if (j.success) {
                            showSecretaryActionFeedback(j.message || 'Appointment approved', 'success');
                            window.location.reload();
                        } else {
                            showSecretaryActionFeedback('Approve failed: ' + (j.message || 'unknown'), 'danger');
                        }
                    } catch (err) {
                        console.error(err);
                        showSecretaryActionFeedback('Approve error', 'danger');
                    }
                }
            };

            // Assign Doctor Confirm button handler
            document.getElementById('assignDoctorConfirmBtn')?.addEventListener('click', async function() {
                const apptId = document.getElementById('assignDoctorApptId')?.value;
                const doctorId = document.getElementById('assignDoctorSelect')?.value;
                if (!apptId) return;
                if (!doctorId) {
                    showSecretaryActionFeedback('Please select a doctor to assign.', 'warning');
                    return;
                }
                this.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('appointment_id', apptId);
                    fd.append('doctor_id', doctorId);
                    fd.append('csrf_token', secretaryCsrfToken);
                    const svcChecks = document.querySelectorAll('#assignServiceCheckboxes input[type="checkbox"]:checked');
                    svcChecks.forEach(cb => fd.append('selected_service_ids[]', cb.value));
                    const resp = await fetch('../assets/api/appointments/confirm_appointment.php', { method: 'POST', body: fd });
                    const j = await resp.json();
                    if (j.success) {
                        bootstrap.Modal.getInstance(document.getElementById('assignDoctorModal'))?.hide();
                        showSecretaryActionFeedback(j.message || 'Appointment confirmed with assigned doctor', 'success');
                        window.location.reload();
                    } else {
                        showSecretaryActionFeedback('Failed: ' + (j.message || 'unknown'), 'danger');
                    }
                } catch (err) {
                    console.error(err);
                    showSecretaryActionFeedback('Error confirming appointment', 'danger');
                } finally {
                    this.disabled = false;
                }
            });

            const BOOK_DAYS_AHEAD = 150;
            const bookSelectedDateText = document.getElementById('bookSelectedDateText');
            let bookCalendarInstance = null;
            let bookAvailabilityByDate = {};
            let bookSelectedDayEl = null;

            function toIsoDateLocal(dateObj) {
                const year = dateObj.getFullYear();
                const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                const day = String(dateObj.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            }

            function addDaysIso(isoDate, days) {
                const d = new Date(isoDate + 'T00:00:00');
                d.setDate(d.getDate() + days);
                return toIsoDateLocal(d);
            }

            const todayIso = toIsoDateLocal(new Date());

            function formatBookDisplayDate(isoDate) {
                if (!isoDate) return 'No date selected';
                const d = new Date(isoDate + 'T00:00:00');
                return d.toLocaleDateString(undefined, { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' });
            }

            function setBookSelectedDateText(isoDate) {
                if (bookSelectedDateText) {
                    bookSelectedDateText.textContent = formatBookDisplayDate(isoDate);
                }
            }

            function resetBookTimeslotSelection() {
                const wrapper = document.getElementById('bookTimeslotWrapper');
                const select = document.getElementById('bookTimeslotSelect');
                if (wrapper) wrapper.style.display = 'none';
                if (select) {
                    select.value = '';
                    select.disabled = true;
                    select.required = false;
                    select.innerHTML = '<option value="">-- Select a Time Slot --</option>';
                }
            }

            function clearBookDateSelection() {
                const hid = document.getElementById('bookAppointmentDateInput');
                if (hid) hid.value = '';
                setBookSelectedDateText('');
                if (bookSelectedDayEl) {
                    bookSelectedDayEl.classList.remove('book-day-selected');
                    bookSelectedDayEl = null;
                }
                resetBookTimeslotSelection();
            }

            function isBookDateSelectable(dateStr) {
                if (!dateStr || dateStr < todayIso) {
                    return false;
                }
                const dateObj = new Date(dateStr + 'T00:00:00');
                if (dateObj.getDay() === 0) {
                    return false;
                }
                const item = bookAvailabilityByDate[dateStr];
                return !!(item && item.available);
            }

            function getBookBlockedReason(dateStr) {
                const item = bookAvailabilityByDate[dateStr];
                if (!item) {
                    return 'No schedule available for this date.';
                }
                const reason = String(item.reason || '').toLowerCase();
                if (reason.includes('leave')) {
                    return 'Doctor is on leave for this date.';
                }
                if (reason.includes('schedule')) {
                    return 'Doctor has no available schedule on this date.';
                }
                return 'This date is not available for booking.';
            }

            function decorateBookDayCell(arg) {
                const dateStr = toIsoDateLocal(arg.date);
                const item = bookAvailabilityByDate[dateStr] || null;
                const frame = arg.el.querySelector('.fc-daygrid-day-frame');
                if (!frame) return;

                arg.el.classList.remove('book-day-open', 'book-day-busy', 'book-day-closed', 'book-day-disabled', 'book-day-selected');

                const existingBadge = frame.querySelector('.book-day-badge');
                if (existingBadge) existingBadge.remove();

                const badge = document.createElement('span');
                badge.className = 'book-day-badge';

                if (dateStr < todayIso) {
                    arg.el.classList.add('book-day-disabled');
                    badge.classList.add('state-closed');
                    badge.textContent = 'Past';
                    arg.el.setAttribute('title', 'Past date');
                } else if (arg.date.getDay() === 0) {
                    arg.el.classList.add('book-day-closed');
                    badge.classList.add('state-closed');
                    badge.textContent = 'Closed';
                    arg.el.setAttribute('title', 'Clinic closed');
                } else if (item && item.available) {
                    if ((item.count || 0) > 0) {
                        arg.el.classList.add('book-day-busy');
                        badge.classList.add('state-busy');
                        badge.textContent = item.count + ' booked';
                        arg.el.setAttribute('title', 'Selectable date with existing bookings');
                    } else {
                        arg.el.classList.add('book-day-open');
                        badge.classList.add('state-open');
                        badge.textContent = 'Open';
                        arg.el.setAttribute('title', 'Selectable date');
                    }
                } else {
                    arg.el.classList.add('book-day-closed');
                    badge.classList.add('state-closed');
                    const reason = String(item && item.reason ? item.reason : '').toLowerCase();
                    if (reason.includes('leave')) {
                        badge.textContent = 'On leave';
                        arg.el.setAttribute('title', 'Doctor on leave');
                    } else if (reason.includes('schedule')) {
                        badge.textContent = 'No schedule';
                        arg.el.setAttribute('title', 'No schedule available');
                    } else {
                        badge.textContent = 'Unavailable';
                        arg.el.setAttribute('title', 'Date unavailable');
                    }
                }

                const hid = document.getElementById('bookAppointmentDateInput');
                if (hid && hid.value === dateStr) {
                    arg.el.classList.add('book-day-selected');
                    bookSelectedDayEl = arg.el;
                }

                frame.appendChild(badge);
            }

            function renderBookCalendar() {
                const cal = document.getElementById('bookCalendar');
                if (!cal) return;

                if (bookCalendarInstance) {
                    bookCalendarInstance.destroy();
                    bookCalendarInstance = null;
                    bookSelectedDayEl = null;
                }

                if (typeof FullCalendar === 'undefined') {
                    cal.innerHTML = '<div class="text-danger small">Calendar library failed to load.</div>';
                    return;
                }

                bookCalendarInstance = new FullCalendar.Calendar(cal, {
                    themeSystem: 'bootstrap5',
                    initialView: 'dayGridMonth',
                    fixedWeekCount: false,
                    showNonCurrentDates: false,
                    height: 'auto',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: ''
                    },
                    validRange: {
                        start: todayIso,
                        end: addDaysIso(todayIso, BOOK_DAYS_AHEAD + 1)
                    },
                    dateClick: function(info) {
                        const hid = document.getElementById('bookAppointmentDateInput');
                        if (!hid) return;

                        if (!isBookDateSelectable(info.dateStr)) {
                            showSecretaryActionFeedback(getBookBlockedReason(info.dateStr), 'warning');
                            return;
                        }

                        if (bookSelectedDayEl) {
                            bookSelectedDayEl.classList.remove('book-day-selected');
                        }
                        bookSelectedDayEl = info.dayEl;
                        bookSelectedDayEl.classList.add('book-day-selected');

                        hid.value = info.dateStr;
                        setBookSelectedDateText(info.dateStr);
                        loadTimeslots();
                    },
                    dayCellDidMount: decorateBookDayCell
                });

                bookCalendarInstance.render();
            }

            async function loadAvailableDates(days = BOOK_DAYS_AHEAD) {
                const cal = document.getElementById('bookCalendar');
                const selectedDoctorId = document.getElementById('bookDoctorSelect')?.value || '';
                if (!cal) return;

                if (bookCalendarInstance) {
                    bookCalendarInstance.destroy();
                    bookCalendarInstance = null;
                    bookSelectedDayEl = null;
                }

                clearBookDateSelection();

                try {
                    let url = '../assets/api/appointments/get_available_dates.php?days=' + encodeURIComponent(days);
                    if (selectedDoctorId) {
                        url += '&user_id=' + encodeURIComponent(selectedDoctorId);
                    }

                    const res = await fetch(url);
                    const j = await res.json();

                    if (!j.success || !Array.isArray(j.dates)) {
                        bookAvailabilityByDate = {};
                        renderBookCalendar();
                        return;
                    }

                    bookAvailabilityByDate = {};
                    j.dates.forEach(function(item) {
                        if (!item || !item.date) return;
                        bookAvailabilityByDate[String(item.date)] = {
                            available: !!item.available,
                            count: parseInt(item.count || 0, 10) || 0,
                            reason: String(item.reason || '')
                        };
                    });

                    renderBookCalendar();
                } catch (e) {
                    console.error(e);
                    cal.innerHTML = '<div class="text-danger">Error loading dates</div>';
                }
            }

            // Load dates when modal opens
            const bookModal = document.getElementById('bookAppointmentModal');
            if (bookModal) {
                bookModal.addEventListener('shown.bs.modal', function () {
                    renderBookCalendar();
                    loadAvailableDates(BOOK_DAYS_AHEAD);
                    setTimeout(function() {
                        if (bookCalendarInstance) {
                            bookCalendarInstance.updateSize();
                        }
                    }, 120);
                });
            }

            // Add emergency checkbox to booking form for secretary (if not present)
            try {
                if (!document.getElementById('bookIsEmergency')) {
                    const footer = bookForm.querySelector('.modal-footer');
                    if (footer) {
                        const wrap = document.createElement('div');
                        wrap.style.marginRight = 'auto';
                        wrap.innerHTML = '<div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="bookIsEmergency"><label class="form-check-label ms-1" for="bookIsEmergency">Emergency (priority)</label></div>';
                        footer.insertBefore(wrap, footer.firstChild);
                    }
                }
            } catch(e){ console.warn('add emergency checkbox', e); }

            // Reload timeslots when doctor changes
            const bookDoctorSelect = document.getElementById('bookDoctorSelect');
            if (bookDoctorSelect) {
                bookDoctorSelect.addEventListener('change', function() {
                    loadAvailableDates(BOOK_DAYS_AHEAD);
                    loadTimeslots();
                    // Reset service manager when doctor changes
                    secretaryServiceManager = null;
                    initializeSecretaryServiceManager();
                });
            }

            // Initialize Secretary Service Manager
            let secretaryServiceManager = null;
            // Global duration tracking for timeslot filtering
            window.secretarySelectedServiceDuration = 0;
            
            async function initializeSecretaryServiceManager() {
                console.log('[Secretary Appointments] initializeSecretaryServiceManager called');
                const doctorId = document.getElementById('bookDoctorSelect')?.value;
                const serviceSelector = document.getElementById('secretaryServiceSelector');
                
                console.log('[Secretary Appointments] Doctor ID:', doctorId, 'ServiceSelector exists:', !!serviceSelector);
                
                if (!doctorId || !serviceSelector) {
                    if (serviceSelector) {
                        serviceSelector.innerHTML = '<small class="text-muted">Select a doctor first to load available services</small>';
                    }
                    console.log('[Secretary Appointments] Early return - missing doctorId or serviceSelector');
                    return;
                }
                
                // Wait for AppointmentServiceManager to be defined
                if (typeof AppointmentServiceManager === 'undefined') {
                    console.log('[Secretary Appointments] Waiting for AppointmentServiceManager to load...');
                    serviceSelector.innerHTML = '<small class="text-muted">Loading services module...</small>';
                    
                    // Wait up to 5 seconds for the class to load
                    let waitCount = 0;
                    while (typeof AppointmentServiceManager === 'undefined' && waitCount < 50) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                        waitCount++;
                    }
                    
                    if (typeof AppointmentServiceManager === 'undefined') {
                        console.error('[Secretary Appointments] AppointmentServiceManager failed to load');
                        serviceSelector.innerHTML = '<div class="alert alert-danger small">Error: Service manager failed to load. Please refresh the page.</div>';
                        return;
                    }
                }
                
                console.log('[Secretary Appointments] Creating AppointmentServiceManager');
                
                // Create new manager each time doctor changes
                secretaryServiceManager = new AppointmentServiceManager({
                    appointmentId: 0,
                    mode: 'secretary',
                    csrfToken: secretaryCsrfToken,
                    apiEndpoint: '../assets/api/appointments/manage_services.php',
                    onServicesChanged: function(data) {
                        console.log('Services updated (secretary):', data);
                        window.secretarySelectedServiceDuration = data.totalDuration;  // Store for timeslot filtering
                        updateSecretaryTimeslotRequirements(data.totalDuration);
                    }
                });
                
                const success = await secretaryServiceManager.loadServices();
                console.log('[Secretary Appointments] loadServices result:', success);
                
                if (success) {
                    console.log('[Secretary Appointments] Rendering service selector');
                    secretaryServiceManager.renderServiceSelector('secretaryServiceSelector', 'secretary');
                    attachSecretaryServiceListeners();
                } else {
                    serviceSelector.innerHTML = '<div class="alert alert-danger small">Failed to load services</div>';
                }
            }

            // Attach listeners to secretary service checkboxes
            function attachSecretaryServiceListeners() {
                console.log('[Secretary Appointments] attachSecretaryServiceListeners called');
                const container = document.getElementById('secretaryServiceSelector');
                if (!container) {
                    console.error('[Secretary Appointments] Service selector container not found');
                    return;
                }
                
                const checkboxes = container.querySelectorAll('.service-checkbox');
                console.log('[Secretary Appointments] Found', checkboxes.length, 'checkboxes');
                
                const listContainer = container.querySelector('#serviceListContainer');
                const summaryContainer = container.querySelector('#servicesSummaryContainer');
                const confirmBtn = container.querySelector('#confirmServiceSelectionBtn');
                const clearBtn = container.querySelector('#clearServiceSelectionBtn');
                const editBtn = container.querySelector('#editServiceSelectionBtn');
                
                console.log('[Secretary Appointments] Elements found - listContainer:', !!listContainer, 'summaryContainer:', !!summaryContainer, 'confirmBtn:', !!confirmBtn);
                
                const updateDurationDisplay = () => {
                    const selected = Array.from(checkboxes)
                        .filter(cb => cb.checked)
                        .map(cb => parseInt(cb.value));
                    const selectedSet = new Set(selected.map(id => String(id)));
                    const selectedServices = secretaryServiceManager.services.filter(s => selectedSet.has(String(s.service_id)));
                    const total = selectedServices.reduce((sum, s) => sum + secretaryServiceManager.getServiceDuration(s), 0);

                    secretaryServiceManager.selectedServices = selectedServices;
                    secretaryServiceManager.totalDuration = total;

                    if (typeof window !== 'undefined') {
                        window.secretarySelectedServiceDuration = total;
                        window.secretarySelectedServiceIds = selected.slice();
                    }
                    
                    const durationDisplay = container.querySelector('#total-duration-display');
                    if (durationDisplay) {
                        durationDisplay.textContent = secretaryServiceManager.formatDuration(total);
                    }
                    
                    const timeslotDisplay = container.querySelector('#timeslot-count-display');
                    if (timeslotDisplay) {
                        timeslotDisplay.textContent = secretaryServiceManager.getRequiredTimeslots(total);
                    }

                    if (confirmBtn) {
                        confirmBtn.disabled = selected.length === 0;
                    }
                };
                
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', updateDurationDisplay);
                });

                if (confirmBtn) {
                    confirmBtn.addEventListener('click', () => {
                        updateDurationDisplay();
                        const selected = Array.from(checkboxes)
                            .filter(cb => cb.checked)
                            .map(cb => parseInt(cb.value));

                        if (selected.length === 0) {
                            notifySecretary('Please select at least one service', 'warning');
                            return;
                        }

                        // Update the selected services list in the summary
                        const selectedServicesList = container.querySelector('#selectedServicesList');
                        if (selectedServicesList && secretaryServiceManager.selectedServices.length > 0) {
                            const summaryHtml = secretaryServiceManager.selectedServices.map(service => `
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-primary">${secretaryServiceManager.escapeHtml(service.service_name)}</span>
                                    <span class="text-muted small">${secretaryServiceManager.getServiceDuration(service)} min</span>
                                </div>
                            `).join('');
                            selectedServicesList.innerHTML = summaryHtml;
                        }

                        if (listContainer && summaryContainer) {
                            listContainer.classList.add('d-none');
                            summaryContainer.classList.remove('d-none');
                        }
                        
                        // Reload timeslots if a date is already selected
                        const selectedDate = document.getElementById('bookAppointmentDateInput')?.value;
                        if (selectedDate) {
                            console.log('[Secretary Appointments] Services confirmed, reloading timeslots for date:', selectedDate);
                            loadTimeslots();
                        }
                    });
                }

                if (clearBtn) {
                    clearBtn.addEventListener('click', () => {
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = false;
                        });
                        updateDurationDisplay();
                    });
                }

                if (editBtn) {
                    editBtn.addEventListener('click', () => {
                        if (listContainer && summaryContainer) {
                            listContainer.classList.remove('d-none');
                            summaryContainer.classList.add('d-none');
                        }
                    });
                }

                updateDurationDisplay();
            }

            function updateSecretaryTimeslotRequirements(totalDuration) {
                // Update hint text based on service duration
                const hint = document.querySelector('#bookTimeslotWrapper small');
                if (hint && totalDuration > 0) {
                    const timeslotCount = Math.ceil(totalDuration / 20);
                    hint.textContent = `Services require ${totalDuration} minutes (${timeslotCount} timeslot${timeslotCount === 1 ? '' : 's'}).`;
                }
            }

            // Load services when modal opens (after doctor is selected)
            if (bookModal) {
                bookModal.addEventListener('shown.bs.modal', function () {
                    const doctorId = document.getElementById('bookDoctorSelect')?.value;
                    if (doctorId) {
                        initializeSecretaryServiceManager();
                    }
                });
            }

            // Load available timeslots based on selected doctor + date + service
            window.loadTimeslots = async function() {
                const doctorId = document.getElementById('bookDoctorSelect')?.value;
                const date     = document.getElementById('bookAppointmentDateInput')?.value;
                const wrapper  = document.getElementById('bookTimeslotWrapper');
                const select   = document.getElementById('bookTimeslotSelect');

                if (!doctorId || !date) {
                    if (wrapper) wrapper.style.display = 'none';
                    if (select) {
                        select.value = '';
                        select.disabled = true;
                        select.required = false;
                    }
                    return;
                }
                if (wrapper) wrapper.style.display = '';
                select.disabled = false;
                select.required = true;
                select.innerHTML = '<option value="">Loading...</option>';

                try {
                    let url = `../assets/api/appointments/get_timeslots.php?doctor_id=${encodeURIComponent(doctorId)}&date=${encodeURIComponent(date)}`;
                    // Add duration parameter so API filters timeslots to fit the service duration
                    if (window.secretarySelectedServiceDuration > 0) {
                        url += `&duration=${encodeURIComponent(String(window.secretarySelectedServiceDuration))}`;
                    }
                    
                    const res  = await fetch(url);
                    const data = await res.json();
                    select.innerHTML = '<option value="">-- Select a timeslot --</option>';
                    if (data.success && data.slots.length > 0) {
                        data.slots.forEach(slot => {
                            const opt = document.createElement('option');
                            opt.value = slot.timeslot_id;
                            // Display the label which shows the time range (e.g., "8:00 AM – 11:00 AM")
                            opt.textContent = String(slot.label || 'Time Slot');
                            select.appendChild(opt);
                        });
                    } else {
                        if (window.secretarySelectedServiceDuration > 0) {
                            select.innerHTML = `<option value="">No timeslots can fit ${window.secretarySelectedServiceDuration} minutes on this date</option>`;
                        } else {
                            select.innerHTML = '<option value="">No available timeslots — generate them first</option>';
                        }
                    }
                } catch (err) {
                    select.innerHTML = '<option value="">Error loading timeslots</option>';
                }
            };

            // Booking submit
            bookForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const patientId = bookPatientSelect ? parseInt(bookPatientSelect.value) || 0 : 0;
                const appointmentDate = document.getElementById('bookAppointmentDateInput') ? document.getElementById('bookAppointmentDateInput').value : '';
                const reason = document.getElementById('bookReason').value.trim();
                const userSelect = document.getElementById('bookDoctorSelect');
                const userId = userSelect ? userSelect.value : '';

                if (!appointmentDate || !patientId || !userId) {
                    showSecretaryActionFeedback('Please select a patient, a doctor, and an appointment date.', 'warning');
                    return;
                }

                const timeslotId = document.getElementById('bookTimeslotSelect')?.value || '';
                if (!timeslotId) {
                    showSecretaryActionFeedback('Please select a timeslot before booking.', 'warning');
                    return;
                }

                // Check if services are selected
                const container = document.getElementById('secretaryServiceSelector');
                const selectedCheckboxes = container ? Array.from(container.querySelectorAll('.service-checkbox:checked')) : [];
                const selectedServiceIds = selectedCheckboxes.map(cb => parseInt(cb.value));

                try {
                    let allowDuplicateDay = false;
                    const ad = new FormData();
                    ad.append('patient_id', patientId);
                    ad.append('appointment_date', appointmentDate);
                    ad.append('user_id', userId);
                    ad.append('reason', reason);
                    const apptType = document.getElementById('bookAppointmentType') ? document.getElementById('bookAppointmentType').value : '0';
                    ad.append('is_online_appointment', apptType);
                    const isEmergency = document.getElementById('bookIsEmergency') ? (document.getElementById('bookIsEmergency').checked ? 1 : 0) : 0;
                    ad.append('is_emergency', isEmergency);
                    ad.append('timeslot_id', timeslotId);
                    
                    // Add selected services
                    if (secretaryServiceManager && secretaryServiceManager.totalDuration > 0) {
                        ad.append('total_duration_minutes', secretaryServiceManager.totalDuration);
                    }
                    selectedServiceIds.forEach((id, index) => {
                        ad.append(`selected_service_ids[${index}]`, id);
                    });
                    
                    ad.append('csrf_token', secretaryCsrfToken);

                    let aresp = await fetch('../assets/api/appointments/create_appointment.php', { method: 'POST', body: ad });
                    let ajson = await aresp.json();

                    if (!ajson.success && ajson.code === 'duplicate_day_warning') {
                        const proceed = await showConfirmModal({
                            title: 'Duplicate Appointment',
                            message: (ajson.message || 'Patient already has an appointment for this day.') + '\n\nContinue anyway and allow more than one appointment for this day?',
                            confirmText: 'Continue',
                            variant: 'warning'
                        });
                        if (!proceed) {
                            return;
                        }
                        allowDuplicateDay = true;
                        ad.append('allow_duplicate_day', allowDuplicateDay ? '1' : '0');
                        aresp = await fetch('../assets/api/appointments/create_appointment.php', { method: 'POST', body: ad });
                        ajson = await aresp.json();
                    }

                    if (ajson.success) {
                        showSecretaryActionFeedback('Appointment created successfully', 'success');
                        location.reload();
                    } else {
                        showSecretaryActionFeedback('Error creating appointment: ' + (ajson.message || 'unknown'), 'danger');
                    }
                } catch (err) {
                    console.error(err);
                    showSecretaryActionFeedback('Error: ' + (err.message || err), 'danger');
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', initSecretaryAppointments);
    window.addEventListener('page-loaded', initSecretaryAppointments);
</script>

<!-- Reschedule Modal (secretary) -->
<link rel="stylesheet" href="../assets/vendor/flatpickr/flatpickr.min.css">
<script src="../assets/vendor/flatpickr/flatpickr.min.js"></script>
<div class="modal fade" id="rescheduleModalSec" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rescheduleScheduleIdSec" />
                <input type="hidden" id="rescheduleAppointmentIdSec" />
                <input type="hidden" id="rescheduleDoctorIdSec" />
                <div class="mb-2"><label id="rescheduleStartLabelSec" class="form-label">Start</label><input id="rescheduleStartSec" class="form-control" type="datetime-local"/></div>
                <div class="mb-2" id="rescheduleEndWrapSec"><label class="form-label">End (optional)</label><input id="rescheduleEndSec" class="form-control" type="datetime-local"/></div>
                <div class="mb-2" id="rescheduleTimeslotWrapSec" style="display:none;">
                    <label class="form-label">Time Slot</label>
                    <select id="rescheduleTimeslotSec" class="form-select">
                        <option value="">-- Select available slot --</option>
                    </select>
                    <small class="text-muted">Only available slots are shown to avoid overlaps.</small>
                </div>
                <div class="mb-2"><button id="rescheduleSuggestSec" class="btn btn-sm btn-outline-primary">Suggest slots</button></div>
                <div id="rescheduleSuggestionsSec" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button id="rescheduleSaveBtnSec" type="button" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
window.resModalSec = null;
window.fpStartSec = null; window.fpEndSec = null;
function initRescheduleModalSec() {
    try {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const el = document.getElementById('rescheduleModalSec');
            if (el) resModalSec = new bootstrap.Modal(el);
        }
    } catch (e) { console.warn('bootstrap modal init deferred', e); }
}
document.addEventListener('DOMContentLoaded', initRescheduleModalSec);
window.addEventListener('page-loaded', initRescheduleModalSec);
function initSecPickers() {
    try {
        if (typeof flatpickr !== 'undefined') {
            fpStartSec = flatpickr('#rescheduleStartSec', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
            fpEndSec = flatpickr('#rescheduleEndSec', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
        }
    } catch(e){ console.warn('flatpickr sec init', e); }
}
initSecPickers();
function toLocalInputSec(dtStr) { if (!dtStr) return ''; return dtStr.replace(' ', 'T').replace(/:00$/,''); }
function toDateOnlySec(dtStr) {
    if (!dtStr) return '';
    return String(dtStr).slice(0, 10);
}
function toServerFormatSec(localVal) { 
    if (!localVal) return ''; 
    try {
        if (localVal.indexOf('T') !== -1) return localVal.replace('T',' ');
        // date-only (YYYY-MM-DD) -> append midnight time
        if (/^\d{4}-\d{2}-\d{2}$/.test(localVal)) return localVal + ' 00:00:00';
        return localVal;
    } catch (e) { return localVal; }
}

function notifySecretary(message, type) {
    window.showToast(String(message || ''), type || 'info');
}

async function loadRescheduleTimeslotsSec(preselectTimeslotId) {
    const doctorId = document.getElementById('rescheduleDoctorIdSec').value || '';
    const dateVal = toDateOnlySec(document.getElementById('rescheduleStartSec').value || '');
    const select = document.getElementById('rescheduleTimeslotSec');
    const wrap = document.getElementById('rescheduleTimeslotWrapSec');
    if (!select || !wrap) return;

    if (!doctorId || !dateVal) {
        wrap.style.display = 'none';
        select.innerHTML = '<option value="">-- Select available slot --</option>';
        return;
    }

    wrap.style.display = '';
    select.innerHTML = '<option value="">Loading slots...</option>';

    try {
        const url = '../assets/api/appointments/get_timeslots.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(dateVal);
        const res = await fetch(url);
        const j = await res.json();
        select.innerHTML = '<option value="">-- Select available slot --</option>';
        if (!j.success || !Array.isArray(j.slots) || j.slots.length === 0) {
            select.innerHTML = '<option value="">No available slots for this date</option>';
            return;
        }
        (j.slots || []).forEach(function(slot) {
            const opt = document.createElement('option');
            opt.value = String(slot.timeslot_id || '');
            opt.textContent = String(slot.label || (slot.start_time + ' - ' + slot.end_time));
            if (preselectTimeslotId && Number(preselectTimeslotId) === Number(slot.timeslot_id)) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
    } catch (e) {
        console.error(e);
        select.innerHTML = '<option value="">Error loading slots</option>';
    }
}

function openRescheduleModalSec(schedule) {
    document.getElementById('rescheduleScheduleIdSec').value = schedule.schedule_id || '';
    document.getElementById('rescheduleAppointmentIdSec').value = '';
    document.getElementById('rescheduleDoctorIdSec').value = schedule.user_id || '';
    // restore labels and fields for schedule reschedule
    try { document.getElementById('rescheduleStartLabelSec').textContent = 'Start'; } catch(e){}
    try { document.getElementById('rescheduleStartSec').type = 'datetime-local'; } catch(e){}
    try { document.getElementById('rescheduleEndWrapSec').style.display = ''; } catch(e){}
    try { document.getElementById('rescheduleEndSec').type = 'datetime-local'; } catch(e){}
    try { document.getElementById('rescheduleSuggestSec').style.display = ''; } catch(e){}
    try { document.getElementById('rescheduleTimeslotWrapSec').style.display = 'none'; } catch(e){}
    // if flatpickr is used, reconfigure to show time
    try {
        if (fpStartSec && typeof fpStartSec.set === 'function') fpStartSec.set({ enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
        if (fpEndSec && typeof fpEndSec.set === 'function') fpEndSec.set({ enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
    } catch(e) { console.warn('flatpickr re-enable time failed', e); }
    // ensure datetime inputs for schedule reschedule
    try { document.getElementById('rescheduleStartSec').type = 'datetime-local'; } catch(e){}
    try { document.getElementById('rescheduleEndSec').type = 'datetime-local'; document.getElementById('rescheduleEndSec').parentElement.style.display = ''; } catch(e){}
    try { document.getElementById('rescheduleSuggestSec').style.display = ''; } catch(e){}
    const startLocal = toLocalInputSec(schedule.scheduled_start || '');
    const endLocal = toLocalInputSec(schedule.scheduled_end || '');
    if (fpStartSec && typeof fpStartSec.setDate === 'function') fpStartSec.setDate(startLocal);
    else document.getElementById('rescheduleStartSec').value = startLocal;
    if (fpEndSec && typeof fpEndSec.setDate === 'function') fpEndSec.setDate(endLocal);
    else document.getElementById('rescheduleEndSec').value = endLocal;
    document.getElementById('rescheduleSuggestionsSec').innerHTML = '';
    resModalSec.show();
}
// Open reschedule modal for an appointment (not a schedule)
function openRescheduleForAppointmentSec(appointment) {
    document.getElementById('rescheduleAppointmentIdSec').value = appointment.appointment_id || '';
    document.getElementById('rescheduleScheduleIdSec').value = '';
    document.getElementById('rescheduleDoctorIdSec').value = appointment.user_id || '';
    // use date + timeslot for appointment reschedule
    try { document.getElementById('rescheduleStartLabelSec').textContent = 'Date'; } catch(e){}
    try { document.getElementById('rescheduleStartSec').type = 'date'; } catch(e){}
    try { document.getElementById('rescheduleEndWrapSec').style.display = 'none'; } catch(e){}
    try { document.getElementById('rescheduleSuggestSec').style.display = ''; } catch(e){}
    try { document.getElementById('rescheduleTimeslotWrapSec').style.display = ''; } catch(e){}
    // if flatpickr is used, reconfigure to date-only
    try {
        if (fpStartSec && typeof fpStartSec.set === 'function') fpStartSec.set({ enableTime: false, dateFormat: 'Y-m-d' });
        if (fpEndSec && typeof fpEndSec.set === 'function') fpEndSec.set({ enableTime: false, dateFormat: 'Y-m-d' });
    } catch(e) { console.warn('flatpickr set date-only failed', e); }
    // set date-only value (strip time if present)
    let apptDate = appointment.appointment_date || '';
    if (apptDate.indexOf(' ') !== -1) apptDate = apptDate.split(' ')[0];
    const startLocal = apptDate;
    if (fpStartSec && typeof fpStartSec.setDate === 'function') fpStartSec.setDate(startLocal);
    else document.getElementById('rescheduleStartSec').value = startLocal;
    document.getElementById('rescheduleSuggestionsSec').innerHTML = '';
    loadRescheduleTimeslotsSec(appointment.timeslot_id || 0);
    resModalSec.show();
}

document.getElementById('rescheduleStartSec').addEventListener('change', function() {
    const apptId = document.getElementById('rescheduleAppointmentIdSec').value;
    if (apptId) {
        loadRescheduleTimeslotsSec();
    }
});

document.getElementById('rescheduleSuggestSec').addEventListener('click', function(){
    const btn = this;
    if (btn.dataset.actionLoadingActive === '1') return;
    if (typeof window.clearPendingAction === 'function') window.clearPendingAction();
    runWithActionLoading(btn, async function(){
    const sid = document.getElementById('rescheduleScheduleIdSec').value;
    const apptId = document.getElementById('rescheduleAppointmentIdSec').value;
    const apptDoctor = document.getElementById('rescheduleDoctorIdSec').value || '';
    try {
        let user_id = CURRENT_USER;
        let duration = 30;
        if (sid) {
            const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
            const gs = await g.json();
            if (!gs.success) return notifySecretary(gs.message || 'Could not resolve schedule', 'danger');
            user_id = gs.schedule.user_id || user_id;
            duration = gs.schedule.duration || duration;
        } else if (apptDoctor) {
            user_id = apptDoctor;
        }
        const fd = new FormData(); fd.append('user_id', user_id); fd.append('days_ahead', 14); fd.append('duration', duration);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        const wrap = document.getElementById('rescheduleSuggestionsSec'); wrap.innerHTML = '';
        if (!j.success) return notifySecretary(j.message || 'No suggestions', 'warning');
        (j.slots || []).slice(0,10).forEach(slot => {
            const a = document.createElement('a');
            a.href = '#';
            a.className = 'list-group-item list-group-item-action';
            a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : '');
            a.addEventListener('click', async function(e){
                e.preventDefault();
                if (apptId) {
                    document.getElementById('rescheduleStartSec').value = toDateOnlySec(slot.start);
                    await loadRescheduleTimeslotsSec(slot.timeslot_id || 0);
                    const tsSelect = document.getElementById('rescheduleTimeslotSec');
                    if (tsSelect && slot.timeslot_id) tsSelect.value = String(slot.timeslot_id);
                } else {
                    document.getElementById('rescheduleStartSec').value = slot.start.replace(' ', 'T');
                    document.getElementById('rescheduleEndSec').value = slot.end ? slot.end.replace(' ', 'T') : '';
                }
            });
            wrap.appendChild(a);
        });
    } catch(e){ console.error(e); notifySecretary('Unable to load suggestions. Please try again.', 'danger'); }
    });
});

document.getElementById('rescheduleSaveBtnSec').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleIdSec').value;
    const apptId = document.getElementById('rescheduleAppointmentIdSec').value;
    const doctorId = document.getElementById('rescheduleDoctorIdSec').value || CURRENT_USER;
    const start = toServerFormatSec(document.getElementById('rescheduleStartSec').value);
    const end = toServerFormatSec(document.getElementById('rescheduleEndSec').value);
    if (!start) { notifySecretary('Start date required', 'warning'); return; }
    try {
        if (apptId) {
            const timeslotId = document.getElementById('rescheduleTimeslotSec')?.value || '';
            const dateOnly = toDateOnlySec(start);
            if (!dateOnly) {
                notifySecretary('Please select a date.', 'warning');
                return;
            }
            if (!timeslotId) {
                notifySecretary('Please select an available timeslot.', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('appointment_id', apptId);
            fd.append('user_id', doctorId);
            fd.append('appointment_date', dateOnly);
            fd.append('timeslot_id', timeslotId);
            fd.append('csrf_token', secretaryCsrfToken);
            const r = await fetch('../assets/api/appointments/update_appointment.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.success) {
                notifySecretary(j.message || 'Failed to update appointment', 'danger');
            } else {
                notifySecretary('Appointment updated', 'success');
                resModalSec.hide();
                location.reload();
            }
        } else {
            const fd = new FormData(); fd.append('schedule_id', sid); fd.append('scheduled_start', start); fd.append('scheduled_end', end); fd.append('csrf_token', secretaryCsrfToken);
            const r = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.success) {
                notifySecretary(j.message || 'Failed to reschedule', 'danger');
            } else {
                notifySecretary('Rescheduled', 'success');
                resModalSec.hide();
                location.reload();
            }
        }
    } catch (e) {
        console.error(e);
        notifySecretary('Unable to complete this action. Please try again.', 'danger');
    }
});

// View patient profiling
async function openViewProfilingModal(patientId, firstName, lastName) {
    const modal = document.getElementById('viewProfilingModal');
    const nameDiv = document.getElementById('profilingPatientName');
    const contentDiv = document.getElementById('profilingContent');

    nameDiv.innerHTML = `<strong>Patient:</strong> ${firstName} ${lastName}`;
    contentDiv.innerHTML = '<div class="text-center text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading medical history...</div>';

    try {
        const res = await fetch(`../assets/api/patients/get_profiling.php?patient_id=${encodeURIComponent(patientId)}`);
        const j = await res.json();

        if (!j.success) {
            contentDiv.innerHTML = '<div class="alert alert-warning">No medical history available for this patient.</div>';
            return;
        }

        if (j.patient) {
            let ageText = 'N/A';
            if (j.patient.date_of_birth) {
                const dob = new Date(j.patient.date_of_birth);
                if (!isNaN(dob.getTime())) {
                    const ageDate = new Date(Date.now() - dob.getTime());
                    ageText = Math.abs(ageDate.getUTCFullYear() - 1970);
                }
            }
            nameDiv.innerHTML = `<strong>Patient:</strong> ${j.patient.first_name} ${j.patient.middle_name ? j.patient.middle_name + ' ' : ''}${j.patient.last_name}<br>
             <small class="text-muted">
                <strong>DOB:</strong> ${j.patient.date_of_birth || 'N/A'} (Age: ${ageText}) &nbsp;|&nbsp; 
                <strong>Gender:</strong> ${j.patient.gender || 'N/A'} <br>
                <strong>Phone:</strong> ${j.patient.phone_number || 'N/A'} &nbsp;|&nbsp; 
                <strong>Address:</strong> ${j.patient.address || 'N/A'}
             </small>`;
        }

        if (!j.profiling) {
            contentDiv.innerHTML = '<div class="alert alert-info">Patient medical history has not been filled out yet. Use Manage Patients to add background info.</div>';
            return;
        }

        if (typeof renderBackgroundProfilingFields === 'function') {
            contentDiv.innerHTML = renderBackgroundProfilingFields(j.profiling);
        } else {
            contentDiv.innerHTML = '<div class="alert alert-warning">Unable to render medical history view.</div>';
        }
    } catch (err) {
        console.error(err);
        contentDiv.innerHTML = '<div class="alert alert-danger">Error loading medical history.</div>';
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}
</script>

<!-- Secretary Record Creation Modal -->
<div class="modal fade" id="secretaryRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2 px-3">
                <div class="d-flex align-items-center gap-3 me-auto pe-3">
                    <div id="recordPatientAvatar" style="width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">
                        <i class="bi bi-person-fill" style="font-size:1.2rem;opacity:0.7;"></i>
                    </div>
                    <div>
                        <h6 class="modal-title mb-0" id="secretaryRecordPatientName">Create Clinical Record</h6>
                        <small id="secretaryRecordPatientDetails" class="text-white-50" style="font-size:0.72rem;"></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs nav-tabs-sm" id="recordTabs" role="tablist" style="padding: 0 16px; background: #f8f9fa;">
                    <li class="nav-item"><a class="nav-link active py-2 px-3" data-bs-toggle="tab" href="#tabNotes" role="tab"><i class="bi bi-journal-text"></i> Session Notes</a></li>
                    <li class="nav-item"><a class="nav-link py-2 px-3" data-bs-toggle="tab" href="#tabClinical" role="tab"><i class="bi bi-clipboard2-pulse"></i> Clinical Findings</a></li>
                    <li class="nav-item"><a class="nav-link py-2 px-3" data-bs-toggle="tab" href="#tabOdontogram" role="tab"><i class="bi bi-tooth"></i> Charting</a></li>
                    <li class="nav-item"><a class="nav-link py-2 px-3" data-bs-toggle="tab" href="#tabBilling" role="tab"><i class="bi bi-receipt"></i> Billing</a></li>
                </ul>

                <div class="tab-content p-3" style="max-height: calc(80vh - 120px); overflow-y: auto;">
                    <!-- TAB 1: Notes & Photos -->
                    <div class="tab-pane fade show active" id="tabNotes" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold small">Doctor's Notes</label>
                                <div id="doctorNotesDisplay" class="border rounded p-2 bg-white" style="min-height: 60px; max-height: 120px; overflow-y: auto; white-space: pre-wrap; font-size: 0.85rem;"></div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Doctor's Photos</label>
                                <div id="doctorPhotoGallery" class="d-flex flex-wrap gap-1"></div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label fw-semibold small">Clinical Summary</label>
                            <textarea id="recordSummary" class="form-control form-control-sm" rows="2" placeholder="Treatment results, findings, recommendations..." style="font-size: 0.85rem;"></textarea>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Prescriptions</label>
                                <div id="prescriptionsBody" style="font-size: 0.85rem;"></div>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1 py-0" id="addPrescriptionBtn" style="font-size: 0.78rem;"><i class="bi bi-plus"></i> Add</button>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Follow-up</label>
                                <div class="row g-1">
                                    <div class="col-6"><input type="date" class="form-control form-control-sm" id="followUpDate" style="font-size: 0.8rem;"></div>
                                    <div class="col-3"><input type="number" min="1" class="form-control form-control-sm" id="followUpInterval" placeholder="Mo." style="font-size: 0.8rem;"></div>
                                    <div class="col-3"><input type="number" min="1" class="form-control form-control-sm" id="followUpTotalVisits" placeholder="Visits" style="font-size: 0.8rem;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: Clinical Assessment -->
                    <div class="tab-pane fade" id="tabClinical" role="tabpanel">
                        <form id="secretaryProfilingForm">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><label class="form-label fw-semibold small">Case History</label><textarea class="form-control form-control-sm visit-clinical-field" name="case_history" rows="2" placeholder="Patient case history" style="font-size:0.85rem;"></textarea></div>
                                <div class="col-md-6"><label class="form-label fw-semibold small">Chief Complaint</label><textarea class="form-control form-control-sm visit-clinical-field" name="chief_complaint" rows="2" placeholder="Primary reason for visit" style="font-size:0.85rem;"></textarea></div>
                            </div>

                            <h6 class="small text-uppercase text-muted mb-1 mt-2" style="font-size:0.72rem;">Clinical Assessment</h6>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><label class="form-label small mb-1">Gingiva Consistency</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" value="Firm" id="gf"><label class="form-check-label small" for="gf">Firm</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" value="Hyper plastic" id="gh"><label class="form-check-label small" for="gh">Hyper</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" value="Smooth" id="gs"><label class="form-check-label small" for="gs">Smooth</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Oral Hygiene</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" value="Good" id="og"><label class="form-check-label small" for="og">Good</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" value="Fair" id="of"><label class="form-check-label small" for="of">Fair</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" value="Bad" id="ob"><label class="form-check-label small" for="ob">Bad</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Gingival Color</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingival_color" value="Pink" id="cp"><label class="form-check-label small" for="cp">Pink</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingival_color" value="Bright red" id="cr"><label class="form-check-label small" for="cr">Red</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Lymph Nodes</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="lymph_nodes" value="Not palpable" id="ln"><label class="form-check-label small" for="ln">Not palpable</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="lymph_nodes" value="Palpable" id="lp"><label class="form-check-label small" for="lp">Palpable</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Tongue</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="tongue" value="Normal" id="tn"><label class="form-check-label small" for="tn">Normal</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="tongue" value="Coated" id="tc"><label class="form-check-label small" for="tc">Coated</label></div></div></div>
                            </div>

                            <h6 class="small text-uppercase text-muted mb-1 mt-2" style="font-size:0.72rem;">Additional Clinical Findings</h6>
                            <div class="row g-2">
                                <div class="col-md-4"><label class="form-label small mb-1">Occlusion</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="occlusion" placeholder="e.g. Angle Class I" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Cl. I Type</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_i_type" placeholder="Normal" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Cl.II Division</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_division" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.II SubDiv</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_subdivision" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.II Type</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_type" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.III Div</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_iii_division" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.III SubDiv</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_iii_subdivision" style="font-size:0.82rem;"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Operation if any</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="operation_if_any" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Blood Sugar</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_sugar" placeholder="Value" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">BS Status</label><div class="d-flex gap-2 flex-wrap"><div class="form-check form-check-sm m-0"><input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_normal" id="bsn"><label class="form-check-label small" for="bsn">Normal</label></div><div class="form-check form-check-sm m-0"><input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_high_low" id="bsh"><label class="form-check-label small" for="bsh">High/Low</label></div></div></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Bleeding Time</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="bleeding_time" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Clotting Time</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="clotting_time" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">B.P.</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_pressure" placeholder="e.g. 120/80" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Allergy</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="allergy_notes" placeholder="No" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Blood Diseases</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_diseases" style="font-size:0.82rem;"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Radiographic Interpretation</label><textarea class="form-control form-control-sm visit-clinical-field" name="radiographic_interpretation" rows="2" style="font-size:0.82rem;"></textarea></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Fainting</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="fainting" style="font-size:0.82rem;"></div>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 3: Odontogram -->
                    <div class="tab-pane fade" id="tabOdontogram" role="tabpanel">
                        <div class="d-flex align-items-center mb-2 gap-2">
                            <button type="button" id="openSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark"><i class="bi bi-fullscreen"></i> Full Screen</button>
                            <small class="text-muted">Use 2D chart to set status and comments.</small>
                        </div>
                        <div id="secretaryOdontogramViewport" class="odontogram-viewport">
                            <button type="button" id="closeSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;"><i class="bi bi-x-lg"></i></button>
                            <div id="secretaryOdontogramContainer" class="secretary-odontogram-container">
                                <iframe id="odontogramFrame" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: Billing -->
                    <div class="tab-pane fade" id="tabBilling" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <h6 class="small fw-bold mb-2">Services</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-2">
                                        <thead class="table-light"><tr><th>Service</th><th style="width:120px;">Price</th><th style="width:40px;"></th></tr></thead>
                                        <tbody id="billingServicesBody"><tr id="billingServicesEmptyRow"><td colspan="3" class="text-muted small">No services added.</td></tr></tbody>
                                    </table>
                                </div>
                                <div class="d-flex gap-1 mb-3">
                                    <select id="addServiceSelect" class="form-select form-select-sm" style="max-width:220px; font-size:0.82rem;"><option value="">Add service...</option></select>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0" id="addBillingServiceBtn" style="font-size:0.78rem;"><i class="bi bi-plus"></i></button>
                                </div>

                                <h6 class="small fw-bold mb-2">Additional Charges</h6>
                                <table class="table table-sm align-middle mb-2">
                                    <thead class="table-light"><tr><th>Description</th><th style="width:120px;">Amount</th><th style="width:40px;"></th></tr></thead>
                                    <tbody id="billingChargesBody"></tbody>
                                    <tfoot><tr><td colspan="3"><button type="button" class="btn btn-sm btn-outline-secondary py-0" id="addBillingChargeBtn" style="font-size:0.78rem;"><i class="bi bi-plus"></i> Add Charge</button></td></tr></tfoot>
                                </table>
                            </div>
                            <div class="col-md-5">
                                <h6 class="small fw-bold mb-2">Discount</h6>
                                <div class="mb-2">
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountNone" value="none" checked><label class="form-check-label small" for="discountNone">None</label></div>
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountSenior" value="senior"><label class="form-check-label small" for="discountSenior">Senior (<?php echo (int) billing_senior_discount_percent(); ?>%)</label></div>
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountPwd" value="pwd"><label class="form-check-label small" for="discountPwd">PWD (<?php echo (int) billing_pwd_discount_percent(); ?>%)</label></div>
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountCustom" value="custom"><label class="form-check-label small" for="discountCustom">Custom</label></div>
                                </div>
                                <div id="customDiscountFields" style="display:none;">
                                    <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm mb-1" id="customDiscountPercent" value="20" placeholder="%" style="font-size:0.82rem;">
                                    <input type="text" class="form-control form-control-sm" id="discountNotes" maxlength="255" placeholder="Reason" style="font-size:0.82rem;">
                                </div>

                                <div class="p-2 bg-light border rounded mt-2 mb-2" id="billingTotalsSummary">
                                    <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="billingSubtotalDisplay">₱0.00</span></div>
                                    <div class="d-flex justify-content-between small text-success billing-discount-summary-row" id="billingDiscountRow" style="display:none;"><span id="billingDiscountLabel">Discount</span><span id="billingDiscountDisplay">-₱0.00</span></div>
                                    <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1"><span>Total Due</span><span id="billingTotalDisplay">₱0.00</span></div>
                                </div>

                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="useInstallment"><label class="form-check-label small" for="useInstallment">Installment plan (50% down)</label></div>
                                <div id="installmentOptions" style="display:none;"><input type="number" min="1" max="60" class="form-control form-control-sm" id="installmentTerms" value="6" placeholder="Monthly terms" style="max-width:140px; font-size:0.82rem;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="submitSecretaryRecordBtn"><i class="bi bi-check-lg"></i> Create Record</button>
            </div>
        </div>
    </div>
</div>

<style>
#secretaryRecordModal .secretary-record-patient-name {
    font-size: 1.2rem;
    line-height: 1.35;
    letter-spacing: 0.02em;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.nav-tabs-sm .nav-link {
    font-size: 0.82rem;
    font-weight: 500;
    color: #64748b;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 14px;
}
.nav-tabs-sm .nav-link.active {
    color: #16a34a;
    border-bottom-color: #16a34a;
    background: transparent;
}
.nav-tabs-sm .nav-link:hover {
    color: #16a34a;
    background: transparent;
}
#secretaryRecordModal .form-label { margin-bottom: 2px; font-size: 0.82rem; }
#secretaryRecordModal .form-check-sm .form-check-input { width: 16px; height: 16px; }
#secretaryRecordModal .form-check-sm .form-check-label { font-size: 0.8rem; }

.odontogram-viewport {
    position: relative;
    width: 100%;
}

.secretary-odontogram-container {
    width: 100%;
    height: <?php echo (int) odontogram_embed_height_px(); ?>px;
}

.secretary-odontogram-container iframe,
#odontogramFrame {
    display: block;
    width: 100% !important;
    height: 100% !important;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.odontogram-viewport:fullscreen,
.odontogram-viewport:-webkit-full-screen {
    width: 100vw;
    height: 100vh;
    background: #0b0f19;
    padding: 0;
}

.odontogram-viewport:fullscreen .secretary-odontogram-container,
.odontogram-viewport:-webkit-full-screen .secretary-odontogram-container {
    height: 100% !important;
}

.odontogram-fs-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 30;
}

@media (max-width: 768px) {
    .secretary-odontogram-container {
        height: 520px;
    }
}
@media (max-width: 480px) {
    .secretary-odontogram-container {
        height: 440px;
    }
}
</style>

<script>
window.currentRecordAppointmentId = null;
window.currentRecordPatientId = null;
window.secretaryOdontogramData = {};
window.SECRETARY_ODONTOGRAM_IFRAME_HEIGHT = <?php echo (int) odontogram_embed_height_px(); ?>;
window.BILLING_SENIOR_DISCOUNT_PERCENT = <?php echo json_encode((float) billing_senior_discount_percent()); ?>;
window.BILLING_PWD_DISCOUNT_PERCENT = <?php echo json_encode((float) billing_pwd_discount_percent()); ?>;

window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.type === 'odontogram_update' && ev.data.toothState) {
        secretaryOdontogramData = ev.data.toothState;
    }
});

document.getElementById('useInstallment')?.addEventListener('change', function() {
    const opts = document.getElementById('installmentOptions');
    if (opts) opts.style.display = this.checked ? 'block' : 'none';
});

function addPrescriptionRow(rx = {}) {
    const body = document.getElementById('prescriptionsBody');
    if (!body) return;
    const wrap = document.createElement('div');
    wrap.className = 'row g-2 mb-2 prescription-row';
    wrap.innerHTML = `
        <div class="col-md-3"><input type="text" class="form-control form-control-sm rx-med" value="${rx.medicine_name || ''}" placeholder="Medicine"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-dosage" value="${rx.dosage || ''}" placeholder="Dosage"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-brand" value="${rx.medicine_brand || ''}" placeholder="Brand"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-duration" value="${rx.duration || ''}" placeholder="Duration"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-notes" value="${rx.notes || ''}" placeholder="Notes"></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.prescription-row').remove()"><i class="bi bi-trash"></i></button></div>
    `;
    body.appendChild(wrap);
}

document.getElementById('addPrescriptionBtn')?.addEventListener('click', () => addPrescriptionRow());

function postOdontogramToSecretaryFrame(data) {
    const frame = document.getElementById('odontogramFrame');
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage({ type: 'load_odontogram', toothState: data || {}, readonly: false }, '*');
}

function getSecretaryOdontogramFullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
}

function isSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    return !!viewport && getSecretaryOdontogramFullscreenElement() === viewport;
}

function requestSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    if (!viewport) return;
    const requestFn = viewport.requestFullscreen || viewport.webkitRequestFullscreen || viewport.msRequestFullscreen;
    if (requestFn) requestFn.call(viewport);
}

function exitSecretaryOdontogramFullscreen() {
    const exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
    if (exitFn) exitFn.call(document);
}

function notifySecretaryOdontogramResize() {
    const frame = document.getElementById('odontogramFrame');
    try {
        if (frame?.contentWindow) {
            frame.contentWindow.postMessage({ type: 'odontogram_resize' }, '*');
        }
    } catch (e) {}
}

function syncSecretaryOdontogramFullscreenUI() {
    const closeBtn = document.getElementById('closeSecretaryOdontogramFullscreenBtn');
    const openBtn = document.getElementById('openSecretaryOdontogramFullscreenBtn');
    const container = document.getElementById('secretaryOdontogramContainer');
    const active = isSecretaryOdontogramFullscreen();

    if (closeBtn) closeBtn.style.display = active ? '' : 'none';
    if (openBtn) openBtn.textContent = active ? 'Exit Full Screen' : 'Full Screen';
    if (container) {
        container.style.height = active ? '100%' : (SECRETARY_ODONTOGRAM_IFRAME_HEIGHT + 'px');
    }
    setTimeout(notifySecretaryOdontogramResize, 50);
}

document.getElementById('openSecretaryOdontogramFullscreenBtn')?.addEventListener('click', function () {
    if (isSecretaryOdontogramFullscreen()) {
        exitSecretaryOdontogramFullscreen();
        return;
    }
    requestSecretaryOdontogramFullscreen();
});

document.getElementById('closeSecretaryOdontogramFullscreenBtn')?.addEventListener('click', function () {
    exitSecretaryOdontogramFullscreen();
});

document.addEventListener('fullscreenchange', syncSecretaryOdontogramFullscreenUI);
document.addEventListener('webkitfullscreenchange', syncSecretaryOdontogramFullscreenUI);
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isSecretaryOdontogramFullscreen()) {
        exitSecretaryOdontogramFullscreen();
    }
});

document.getElementById('secretaryRecordModal')?.addEventListener('hidden.bs.modal', function () {
    if (isSecretaryOdontogramFullscreen()) {
        exitSecretaryOdontogramFullscreen();
    }
});

syncSecretaryOdontogramFullscreenUI();

function getAppRoot() {
    const parts = window.location.pathname.split('/').filter(Boolean);
    return parts.length ? ('/' + parts[0]) : '';
}

function resolveMediaUrl(filePath) {
    const path = String(filePath || '').replace(/^\/+/, '');
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    const base = window.MEDIA_BASE_URL || '';
    return base + '/' + path;
}

window.recordBillingCatalog = [];
window.recordBillingServiceIds = new Set();

async function openSecretaryRecordModal(appointmentId, patientId) {
    currentRecordAppointmentId = appointmentId;
    currentRecordPatientId = patientId;

    const patientNameEl = document.getElementById('secretaryRecordPatientName');
    const patientDetailsEl = document.getElementById('secretaryRecordPatientDetails');
    const patientAvatarEl = document.getElementById('recordPatientAvatar');
    if (patientNameEl) {
        patientNameEl.textContent = 'Loading patient...';
    }
    if (patientDetailsEl) patientDetailsEl.textContent = '';
    if (patientAvatarEl) patientAvatarEl.innerHTML = '<i class="bi bi-person-fill" style="font-size:1.2rem;opacity:0.7;"></i>';
    
    try {
        // Fetch patient details
        const patientRes = await fetch('../assets/api/patients/get_profiling.php?patient_id=' + patientId);
        const patientData = await patientRes.json().catch(() => null);

        // Fetch doctor media and notes
        const res = await fetch('../assets/api/appointments/get_doctor_media.php?appointment_id=' + appointmentId);
        const data = await res.json();
        
        if (!data.success) {
            if (patientNameEl) patientNameEl.textContent = '';
            if (patientDetailsEl) patientDetailsEl.textContent = '';
            notifySecretary('Unable to load appointment data. Please try again.', 'danger');
            return;
        }

        // Populate patient header
        if (patientNameEl) {
            const patientName = String(data.appointment?.patient_name || '').trim();
            patientNameEl.textContent = patientName || 'Create Clinical Record';
        }

        if (patientData && patientData.success && patientData.patient) {
            const p = patientData.patient;
            const details = [p.email, p.phone_number, p.address].filter(Boolean);
            if (patientDetailsEl) {
                patientDetailsEl.textContent = details.join(' | ');
            }
            if (patientAvatarEl && p.profile_image) {
                const imgUrl = /^https?:\/\//i.test(p.profile_image) ? p.profile_image : '../' + p.profile_image.replace(/^\/+/, '');
                patientAvatarEl.innerHTML = '<img src="' + escapeHtml(imgUrl) + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
            }
        }
        
        // Display photos
        const photoContainer = document.getElementById('doctorPhotoGallery');
        if (typeof renderMediaGallery === 'function') {
            renderMediaGallery(photoContainer, data.photos || [], function (photo) {
                return resolveMediaUrl(photo.file_path);
            });
        } else if (photoContainer) {
            photoContainer.innerHTML = '<p class="text-muted">No photos uploaded</p>';
        }
        
        // Display notes
        const notesDisplay = document.getElementById('doctorNotesDisplay');
        if (data.notes && data.notes.length > 0) {
            notesDisplay.textContent = data.notes.map(n => n.note_text).join('\n---\n');
        } else {
            notesDisplay.textContent = 'No clinical notes provided by doctor';
        }
        
        secretaryOdontogramData = data.odontogram || {};
        const odontoFrame = document.getElementById('odontogramFrame');
        if (odontoFrame) {
            odontoFrame.onload = function() { postOdontogramToSecretaryFrame(secretaryOdontogramData); };
            postOdontogramToSecretaryFrame(secretaryOdontogramData);
        }

        document.getElementById('recordSummary').value = data.summary || '';
        document.getElementById('followUpDate').value = data.follow_up_date || '';
        const tp = data.treatment_plan || {};
        document.getElementById('followUpInterval').value = tp.follow_up_interval_months || '';
        document.getElementById('followUpTotalVisits').value = tp.follow_up_total_visits || '';

        const rxBody = document.getElementById('prescriptionsBody');
        rxBody.innerHTML = '';
        if (data.prescriptions && data.prescriptions.length) {
            data.prescriptions.forEach(rx => addPrescriptionRow(rx));
        }

        recordBillingCatalog = data.available_services || [];
        populateAddServiceSelect();

        const servicesBody = document.getElementById('billingServicesBody');
        servicesBody.innerHTML = '';
        recordBillingServiceIds.clear();
        const packageServices = (data.selected_services && data.selected_services.length)
            ? data.selected_services
            : (data.billing_items || []).filter(item => (item.service_id || item.item_type === 'service'));
        packageServices.forEach(item => {
            if (item.service_id) {
                addBillingServiceRow(item.service_id, item.description, item.amount);
            }
        });
        updateBillingServicesEmptyRow();

        const chargesBody = document.getElementById('billingChargesBody');
        chargesBody.innerHTML = '';
        const extraCharges = (data.extra_charges && data.extra_charges.length)
            ? data.extra_charges
            : (data.billing_items || []).filter(item => !item.service_id && item.item_type !== 'service');
        extraCharges.forEach(item => addBillingChargeRow(item.description || '', item.amount || ''));

        document.getElementById('useInstallment').checked = false;
        document.getElementById('installmentOptions').style.display = 'none';
        document.getElementById('installmentTerms').value = '6';
        resetBillingDiscountFields();
        updateBillingTotals();

        // Pre-fill visit clinical exam from this appointment's clinical record
        const visitClinical = data.existing_visit_clinical || data.existing_profiling || {};
        Object.keys(visitClinical).forEach(key => {
            const fields = document.querySelectorAll('#secretaryProfilingForm [name="' + key + '"]');
            if (!fields.length) return;
            const field = fields[0];
            if (field.type === 'radio') {
                fields.forEach(function(radio) {
                    radio.checked = visitClinical[key] === radio.value;
                });
            } else if (field.type === 'checkbox') {
                field.checked = visitClinical[key] === true || visitClinical[key] === 1;
            } else {
                field.value = visitClinical[key] || '';
            }
        });

        if (typeof initToggleableClinicalRadios === 'function') {
            initToggleableClinicalRadios(document.getElementById('secretaryProfilingForm'));
        }
        
        const modal = new bootstrap.Modal(document.getElementById('secretaryRecordModal'));
        modal.show();
    } catch (e) {
        console.error(e);
        notifySecretary('Unable to load appointment. Please try again.', 'danger');
    }
}

window.openSecretaryRecordModal = openSecretaryRecordModal;

function escapeBillingHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatBillingAmount(amount) {
    const num = parseFloat(amount);
    return Number.isFinite(num) ? num.toFixed(2) : '0.00';
}

function getBillingSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('#billingServicesBody tr[data-service-id]').forEach(tr => {
        subtotal += parseFloat(tr.querySelector('.billing-service-amount')?.value || '0') || 0;
    });
    document.querySelectorAll('#billingChargesBody tr').forEach(tr => {
        subtotal += parseFloat(tr.querySelector('.billing-charge-amt')?.value || '0') || 0;
    });
    return Math.max(0, subtotal);
}

function getSelectedBillingDiscount() {
    const type = document.querySelector('input[name="billingDiscountType"]:checked')?.value || 'none';
    let percent = 0;
    if (type === 'senior') {
        percent = BILLING_SENIOR_DISCOUNT_PERCENT;
    } else if (type === 'pwd') {
        percent = BILLING_PWD_DISCOUNT_PERCENT;
    } else if (type === 'custom') {
        percent = parseFloat(document.getElementById('customDiscountPercent')?.value || '0') || 0;
        percent = Math.max(0, Math.min(100, percent));
    }
    return { type, percent };
}

function updateBillingTotals() {
    const subtotal = getBillingSubtotal();
    const discount = getSelectedBillingDiscount();
    const discountAmount = (discount.type === 'none' || discount.percent <= 0)
        ? 0
        : Math.round(subtotal * (discount.percent / 100) * 100) / 100;
    const total = Math.max(0, Math.round((subtotal - discountAmount) * 100) / 100);

    const subtotalEl = document.getElementById('billingSubtotalDisplay');
    const discountRow = document.getElementById('billingDiscountRow');
    const discountLabel = document.getElementById('billingDiscountLabel');
    const discountDisplay = document.getElementById('billingDiscountDisplay');
    const totalEl = document.getElementById('billingTotalDisplay');
    const customFields = document.getElementById('customDiscountFields');

    if (subtotalEl) subtotalEl.textContent = '₱' + formatBillingAmount(subtotal);
    if (totalEl) totalEl.textContent = '₱' + formatBillingAmount(total);
    if (customFields) customFields.style.display = discount.type === 'custom' ? '' : 'none';

    if (discountRow && discountLabel && discountDisplay) {
        if (discountAmount > 0) {
            let label = 'Discount';
            if (discount.type === 'senior') label = 'Senior Citizen Discount (' + discount.percent + '%)';
            else if (discount.type === 'pwd') label = 'PWD Discount (' + discount.percent + '%)';
            else if (discount.type === 'custom') label = 'Custom Discount (' + discount.percent + '%)';
            discountLabel.textContent = label;
            discountDisplay.textContent = '-₱' + formatBillingAmount(discountAmount);
            discountRow.style.display = '';
        } else {
            discountRow.style.display = 'none';
        }
    }
}

function resetBillingDiscountFields() {
    const none = document.getElementById('discountNone');
    if (none) none.checked = true;
    const customPercent = document.getElementById('customDiscountPercent');
    if (customPercent) customPercent.value = '20';
    const notes = document.getElementById('discountNotes');
    if (notes) notes.value = '';
    updateBillingTotals();
}

document.querySelectorAll('.billing-discount-type').forEach(el => {
    el.addEventListener('change', updateBillingTotals);
});
document.getElementById('customDiscountPercent')?.addEventListener('input', updateBillingTotals);
document.getElementById('billingChargesBody')?.addEventListener('input', function(ev) {
    if (ev.target?.classList?.contains('billing-charge-amt')) {
        updateBillingTotals();
    }
});

document.getElementById('billingChargesBody')?.addEventListener('click', function(ev) {
    const btn = ev.target.closest('.btn-outline-danger');
    if (!btn || !this.contains(btn)) return;
    const row = btn.closest('tr');
    if (row) {
        row.remove();
        updateBillingTotals();
    }
});

function populateAddServiceSelect() {
    const select = document.getElementById('addServiceSelect');
    if (!select) return;
    select.innerHTML = '<option value="">Add service from price list...</option>';
    recordBillingCatalog.forEach(svc => {
        const sid = parseInt(svc.service_id, 10);
        if (!sid || recordBillingServiceIds.has(sid)) return;
        const opt = document.createElement('option');
        opt.value = String(sid);
        opt.textContent = svc.description + ' — ₱' + formatBillingAmount(svc.amount);
        opt.dataset.description = svc.description;
        opt.dataset.amount = svc.amount;
        select.appendChild(opt);
    });
}

function updateBillingServicesEmptyRow() {
    const tbody = document.getElementById('billingServicesBody');
    const emptyRow = document.getElementById('billingServicesEmptyRow');
    if (!tbody) return;
    if (recordBillingServiceIds.size > 0) {
        if (emptyRow) emptyRow.remove();
        return;
    }
    if (!tbody.querySelector('#billingServicesEmptyRow')) {
        const tr = document.createElement('tr');
        tr.id = 'billingServicesEmptyRow';
        tr.innerHTML = '<td colspan="3" class="text-muted small">No package services added.</td>';
        tbody.appendChild(tr);
    }
}

function addBillingServiceRow(serviceId, desc, amount) {
    const sid = parseInt(serviceId, 10);
    if (!sid || recordBillingServiceIds.has(sid)) return;

    const tbody = document.getElementById('billingServicesBody');
    const tr = document.createElement('tr');
    tr.dataset.serviceId = String(sid);
    tr.innerHTML = `
        <td>
            <span class="billing-service-name">${escapeBillingHtml(desc)}</span>
            <span class="badge bg-light text-muted border ms-1">Package</span>
        </td>
        <td>
            <span class="billing-service-price">₱${formatBillingAmount(amount)}</span>
            <input type="hidden" class="billing-service-amount" value="${formatBillingAmount(amount)}">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger billing-remove-service" title="Remove service">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tr.querySelector('.billing-remove-service')?.addEventListener('click', () => {
        recordBillingServiceIds.delete(sid);
        tr.remove();
        updateBillingServicesEmptyRow();
        populateAddServiceSelect();
        updateBillingTotals();
    });
    tbody.appendChild(tr);
    recordBillingServiceIds.add(sid);
    updateBillingServicesEmptyRow();
    populateAddServiceSelect();
    updateBillingTotals();
}

function addBillingChargeRow(desc = '', amount = '') {
    const tbody = document.getElementById('billingChargesBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="form-control form-control-sm billing-charge-desc" value="${escapeBillingHtml(desc)}" placeholder="E.g. X-ray fee, lab fee"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm billing-charge-amt" value="${amount !== '' ? escapeBillingHtml(amount) : ''}" placeholder="0.00"></td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    updateBillingTotals();
}

document.getElementById('addBillingServiceBtn')?.addEventListener('click', () => {
    const select = document.getElementById('addServiceSelect');
    if (!select || !select.value) {
        notifySecretary('Select a service from the price list first.', 'warning');
        return;
    }
    const opt = select.options[select.selectedIndex];
    addBillingServiceRow(select.value, opt.dataset.description || opt.textContent, opt.dataset.amount || 0);
    select.value = '';
});

document.getElementById('addBillingChargeBtn')?.addEventListener('click', () => {
    addBillingChargeRow();
});

window.secretaryRecordSubmitting = false;
window.secretaryRecordIdempotencyKey = null;

document.getElementById('submitSecretaryRecordBtn')?.addEventListener('click', async function() {
    if (secretaryRecordSubmitting) return;
    secretaryRecordSubmitting = true;

    if (!secretaryRecordIdempotencyKey) {
        secretaryRecordIdempotencyKey = (window.Idempotency && Idempotency.newKey('record')) || ('record-' + Date.now() + '-' + Math.random().toString(36).slice(2));
    }
    
    try {
        // Collect visit clinical exam data for this appointment record
        const visitClinicalData = typeof collectVisitClinicalData === 'function'
            ? collectVisitClinicalData('#secretaryProfilingForm')
            : {};
        if (typeof collectVisitClinicalData !== 'function') {
            document.querySelectorAll('#secretaryProfilingForm .visit-clinical-field').forEach(field => {
                if (field.type === 'checkbox') {
                    visitClinicalData[field.name] = field.checked;
                } else if (field.type === 'radio') {
                    if (field.checked) {
                        visitClinicalData[field.name] = field.value;
                    }
                } else {
                    visitClinicalData[field.name] = field.value || '';
                }
            });
        }
        
        let odontogramData = secretaryOdontogramData || {};
        try {
            const odontoFrame = document.getElementById('odontogramFrame');
            if (odontoFrame && odontoFrame.contentWindow && typeof odontoFrame.contentWindow.getOdontogramData === 'function') {
                odontogramData = odontoFrame.contentWindow.getOdontogramData() || odontogramData;
            }
        } catch (e) {
            console.warn('Could not get odontogram data:', e);
        }

        const billingItems = [];
        document.querySelectorAll('#billingServicesBody tr[data-service-id]').forEach(tr => {
            const serviceId = parseInt(tr.dataset.serviceId, 10);
            const desc = tr.querySelector('.billing-service-name')?.textContent?.trim() || '';
            const amt = parseFloat(tr.querySelector('.billing-service-amount')?.value || '0') || 0;
            if (serviceId > 0) {
                billingItems.push({
                    service_id: serviceId,
                    description: desc,
                    amount: amt,
                    item_type: 'service'
                });
            }
        });
        document.querySelectorAll('#billingChargesBody tr').forEach(tr => {
            const desc = tr.querySelector('.billing-charge-desc')?.value || '';
            const amt = tr.querySelector('.billing-charge-amt')?.value || '';
            if (desc.trim() !== '' || parseFloat(amt) > 0) {
                billingItems.push({
                    description: desc,
                    amount: parseFloat(amt) || 0,
                    item_type: 'extra'
                });
            }
        });

        const prescriptions = [];
        document.querySelectorAll('#prescriptionsBody .prescription-row').forEach(row => {
            const med = row.querySelector('.rx-med')?.value?.trim() || '';
            if (!med) return;
            prescriptions.push({
                medicine_name: med,
                dosage: row.querySelector('.rx-dosage')?.value || '',
                medicine_brand: row.querySelector('.rx-brand')?.value || '',
                duration: row.querySelector('.rx-duration')?.value || '',
                notes: row.querySelector('.rx-notes')?.value || ''
            });
        });

        const fd = new FormData();
        fd.append('appointment_id', currentRecordAppointmentId);
        fd.append('patient_id', currentRecordPatientId);
        fd.append('visit_clinical', JSON.stringify(visitClinicalData));
        fd.append('odontogram', JSON.stringify(odontogramData));
        fd.append('billing_items', JSON.stringify(billingItems));
        const selectedDiscount = getSelectedBillingDiscount();
        fd.append('discount_type', selectedDiscount.type);
        fd.append('discount_percent', String(selectedDiscount.percent));
        fd.append('discount_notes', document.getElementById('discountNotes')?.value || '');
        fd.append('prescriptions', JSON.stringify(prescriptions));
        fd.append('summary', document.getElementById('recordSummary')?.value || '');
        fd.append('follow_up_date', document.getElementById('followUpDate')?.value || '');
        fd.append('follow_up_interval_months', document.getElementById('followUpInterval')?.value || '0');
        fd.append('follow_up_total_visits', document.getElementById('followUpTotalVisits')?.value || '0');
        fd.append('use_installment', document.getElementById('useInstallment')?.checked ? '1' : '0');
        fd.append('installment_terms', document.getElementById('installmentTerms')?.value || '0');
        fd.append('csrf_token', '<?php echo $csrf_token; ?>');
        fd.append('idempotency_key', secretaryRecordIdempotencyKey);
        
        const res = await fetch('../assets/api/appointments/create_secretary_record.php', {
            method: 'POST',
            body: fd
        });

        const responseText = await res.text();
        let json;
        try {
            json = JSON.parse(responseText);
        } catch (parseErr) {
            console.error('create_secretary_record non-JSON response:', responseText);
            notifySecretary('The system is currently busy. Please try again in a moment.', 'danger');
            secretaryRecordSubmitting = false;
            return;
        }
        if (!json.success) {
            notifySecretary(json.message || 'The system encountered an issue. Please try again.', 'danger');
            secretaryRecordSubmitting = false;
            return;
        }
        
        notifySecretary(json.already_processed || json.idempotent_replay ? 'Clinical record was already submitted.' : 'Clinical record created successfully!', 'success');
        bootstrap.Modal.getInstance(document.getElementById('secretaryRecordModal')).hide();
        
        // Reload page to update queue
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        console.error(e);
        notifySecretary('The system is currently busy. Please try again in a moment.', 'danger');
        secretaryRecordSubmitting = false;
    }
});
</script>

<script>
window.CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
window.CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
window.MEDIA_BASE_URL = '<?php echo htmlspecialchars(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')); ?>';
</script>

<script>
(function() {
    // Pyramid red dot system:
    // Root (sidebar) → only disappears when ALL branches are clear
    // Branch (sub-nav) → only disappears when ALL leaves are clear
    // Leaf (filter) → disappears when clicked

    const STORAGE_KEY = 'sec_viewed_tabs';

    function getViewedTabs() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e) { return {}; }
    }

    function setViewedTabs(data) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch(e) {}
    }

    // Show dots for items that haven't been viewed
    document.querySelectorAll('.nav-dot[data-count]').forEach(function(dot) {
        const count = parseInt(dot.dataset.count || '0', 10);
        const view = dot.id === 'dotRecordsQueue' ? 'records_queue' :
                     dot.id === 'dotReschedule' ? 'reschedule' :
                     dot.id === 'dotNewCustomers' ? 'new_customers' :
                     dot.id === 'dotAppointments' ? 'appointments' : '';
        const section = dot.id === 'dotNewCustomers' ? 'new_customers' : 'appointments';

        if (count > 0 && view) {
            const viewed = getViewedTabs();
            const key = section + ':' + view;
            if (!viewed[key]) {
                dot.classList.add('is-active');
            }
        }

        // When clicked, hide permanently
        dot.addEventListener('click', function(e) {
            e.stopPropagation();
            const viewed = getViewedTabs();
            const key = section + ':' + view;
            viewed[key] = Date.now();
            setViewedTabs(viewed);
            dot.classList.remove('is-active');
            updateDots();
        });

        // Also hide when the parent link is clicked
        const parentLink = dot.closest('a');
        if (parentLink) {
            parentLink.addEventListener('click', function() {
                const viewed = getViewedTabs();
                const key = section + ':' + view;
                viewed[key] = Date.now();
                setViewedTabs(viewed);
                dot.classList.remove('is-active');
                updateDots();
            });
        }
    });

    // Pyramid update: check leaves → branch → root
    function updateDots() {
        // 1. Check sub-nav dots (branches)
        const appointmentSubDots = document.querySelectorAll('#dotRecordsQueue, #dotReschedule, #dotNewCustomers');
        let hasAnySubDot = false;
        appointmentSubDots.forEach(function(dot) {
            if (dot.classList.contains('is-active')) hasAnySubDot = true;
        });

        // Update appointments sub-nav dot (branch)
        const apptDot = document.getElementById('dotAppointments');
        if (apptDot) {
            apptDot.classList.toggle('is-active', hasAnySubDot);
        }

        // 2. Check sidebar dot (root) - only if any dot is active
        const sidebarDot = document.getElementById('secretaryAppointmentsDot');
        if (sidebarDot) {
            let hasAnyActiveDot = false;
            document.querySelectorAll('.nav-dot.is-active').forEach(function(dot) {
                if (dot !== sidebarDot) hasAnyActiveDot = true;
            });
            sidebarDot.classList.toggle('is-active', hasAnyActiveDot);
        }
    }

    // Initialize
    updateDots();
})();
</script>
