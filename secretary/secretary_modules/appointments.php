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
        'confirmed' => ['checked_in', 'cancelled', 'no_show'],
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
    $cap_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = ? AND status IN ('confirmed','checked_in','in_progress','completed')");
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
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>


<nav class="module-section-nav" aria-label="Appointment views">
    <div class="module-section-nav__track module-section-nav__scroll">
        <ul class="nav nav-pills mb-0">
            <?php foreach ($appointmentsViewFilters as $filterKey => $filterOpt): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $view === $filterKey ? 'active' : ''; ?>" href="secretary.php?page=appointments&amp;view=<?php echo urlencode($filterKey); ?>">
                        <?php echo htmlspecialchars($filterOpt['label']); ?>
                        <span class="badge bg-light text-dark ms-1"><?php echo (int)$filterOpt['count']; ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php if ($view !== 'all'): ?>
    <div class="mb-3">
        <a href="secretary.php?page=appointments&amp;view=all" class="btn btn-sm btn-outline-secondary">Reset to all appointments</a>
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
<?php else: ?>

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
                                            <button type="button" class="btn btn-sm btn-primary" onclick="confirmAppointment(<?php echo (int)$appointment['appointment_id']; ?>)" title="Approve appointment">
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
                                        
                                        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewProfilingModal" onclick="openViewProfilingModal(<?php echo (int)$appointment['patient_id']; ?>, '<?php echo htmlspecialchars($appointment['first_name']); ?>', '<?php echo htmlspecialchars($appointment['last_name']); ?>')" title="View patient profiling">
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
    border: 1px solid #dce4ef;
    border-radius: 14px;
    background: #f4f8ff;
    padding: 12px;
}
.secretary-booking-calendar .selected-date-chip {
    display: inline-flex;
    align-items: center;
    font-size: 0.82rem;
    font-weight: 700;
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #075985;
}
.secretary-booking-calendar .calendar-legend {
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 0.8rem;
    color: #334155;
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
}
#bookCalendar .fc-daygrid-day-frame {
    border-radius: 8px;
    transition: box-shadow 0.16s ease, transform 0.16s ease, background-color 0.16s ease;
}
#bookCalendar .fc-daygrid-day-number {
    font-weight: 700;
    color: #1f3b5a;
}
#bookCalendar .fc-button {
    border-radius: 10px;
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
    box-shadow: inset 0 0 0 2px #0369a1;
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
    box-shadow: inset 0 0 0 2px rgba(3, 105, 161, 0.35);
}
#bookCalendar .fc-daygrid-day.book-day-open:hover .fc-daygrid-day-frame,
#bookCalendar .fc-daygrid-day.book-day-busy:hover .fc-daygrid-day-frame {
    transform: translateY(-1px);
}
#bookCalendar .book-day-badge {
    display: inline-flex;
    margin-top: 4px;
    padding: 2px 6px;
    border-radius: 999px;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.02em;
}
#bookCalendar .book-day-badge.state-open {
    background: #dcfce7;
    color: #166534;
}
#bookCalendar .book-day-badge.state-busy {
    background: #fef3c7;
    color: #92400e;
}
#bookCalendar .book-day-badge.state-closed {
    background: #fee2e2;
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
                    <!-- Step 1: Patient Info -->
                    <div id="stepPatient" class="modal-step">
                        <!-- Patient selection (single dropdown below) -->

                        <div class="mb-3">
                            <label for="bookPatientSelect" class="form-label"><strong>Select Patient</strong></label>
                            <select id="bookPatientSelect" name="patient_id" class="form-select compact-control" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <?php $fullname = trim($p['first_name'] . ' ' . ($p['middle_name'] ? $p['middle_name'] . ' ' : '') . $p['last_name']); ?>
                                    <option value="<?php echo htmlspecialchars($p['patient_id']); ?>"><?php echo htmlspecialchars($fullname . ($p['email'] ? ' — ' . $p['email'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                    </div>

                    <!-- Step 2: Booking Info -->
                    <div id="stepBooking" class="modal-step" style="display:none;">
                        <div class="mb-3">
                            <label for="bookDoctorSelect" class="form-label"><strong>Select Doctor</strong></label>
                            <select id="bookDoctorSelect" name="user_id" class="form-select compact-control" required>
                                <option value="">-- Select Doctor --</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="bookAppointmentType" class="form-label"><strong>Appointment Type</strong></label>
                            <select id="bookAppointmentType" name="is_online_appointment" class="form-select compact-control" required>
                                <option value="0" selected>Walk-In</option>
                                <option value="1">Online</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-hospital"></i> Select Services
                            </label>
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
                                <div id="bookCalendar">Loading available dates...</div>
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

<!-- View Patient Profiling Modal -->
<div class="modal fade" id="viewProfilingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-file-medical"></i> Patient Profiling</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="profilingPatientName" class="alert alert-light mb-3"></div>
                <div id="profilingContent">
                    <div class="text-center text-muted">
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        Loading profiling data...
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
    
    document.addEventListener('DOMContentLoaded', function() {
        function showSecretaryActionFeedback(message, type = 'info') {
            if (typeof window.showToast === 'function') {
                window.showToast(String(message || ''), type || 'info');
            }
        }

        // Handle book appointment form: booking requires existing patient_id
        const bookForm = document.getElementById('bookAppointmentForm');
        if (bookForm) {
            const stepPatient = document.getElementById('stepPatient');
            const stepBooking = document.getElementById('stepBooking');
            // Show both sections for secretary: select patient + booking details
            if (stepPatient) stepPatient.style.display = '';
            if (stepBooking) stepBooking.style.display = '';

            // Patient selection (single dropdown)
            const bookPatientSelect = document.getElementById('bookPatientSelect');
            if (bookPatientSelect) {
                // keep the select value as the patient id submitted by the form
                bookPatientSelect.addEventListener('change', function() {
                    // no-op: value is read directly on submit
                });
            }

            // Confirm appointment via AJAX (calls confirm_appointment.php)
            window.confirmAppointment = async function(appointmentId) {
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
            };

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
                cal.innerHTML = '<div class="text-muted small p-2">Loading calendar...</div>';

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
    });
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
document.addEventListener('DOMContentLoaded', function() {
    try {
        if (typeof bootstrap !== 'undefined') {
            resModalSec = new bootstrap.Modal(document.getElementById('rescheduleModalSec'));
        }
    } catch (e) { console.warn('bootstrap modal init deferred', e); }
});
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
    } catch(e){ console.error(e); notifySecretary('Error fetching suggestions', 'danger'); }
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
        notifySecretary('Error', 'danger');
    }
});

// View patient profiling
async function openViewProfilingModal(patientId, firstName, lastName) {
    const modal = document.getElementById('viewProfilingModal');
    const nameDiv = document.getElementById('profilingPatientName');
    const contentDiv = document.getElementById('profilingContent');

    nameDiv.innerHTML = `<strong>Patient:</strong> ${firstName} ${lastName}`;
    contentDiv.innerHTML = '<div class="text-center text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading profiling data...</div>';

    try {
        const res = await fetch(`../assets/api/patients/get_profiling.php?patient_id=${encodeURIComponent(patientId)}`);
        const j = await res.json();

        if (!j.success) {
            contentDiv.innerHTML = '<div class="alert alert-warning">No profiling data available for this patient.</div>';
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
            contentDiv.innerHTML = '<div class="alert alert-info">Patient background profiling has not been filled out yet. Use Manage Patients to add background info.</div>';
            return;
        }

        if (typeof renderBackgroundProfilingFields === 'function') {
            contentDiv.innerHTML = renderBackgroundProfilingFields(j.profiling);
        } else {
            contentDiv.innerHTML = '<div class="alert alert-warning">Unable to render profiling view.</div>';
        }
    } catch (err) {
        console.error(err);
        contentDiv.innerHTML = '<div class="alert alert-danger">Error loading profiling data.</div>';
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
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <div class="me-auto pe-3">
                    <h5 class="modal-title mb-0">Create Clinical Record from Doctor Session</h5>
                    <div id="secretaryRecordPatientName" class="mt-2 mb-0 fw-bold text-white secretary-record-patient-name"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <!-- Doctor Media Section -->
                <div class="mb-4 p-3 bg-light rounded">
                    <h6 class="mb-3"><i class="bi bi-image"></i> Doctor's Photos</h6>
                    <div id="doctorPhotoGallery" class="row g-2"></div>
                </div>

                <!-- Doctor Notes Section -->
                <div class="mb-4 p-3 bg-light rounded">
                    <h6 class="mb-2"><i class="bi bi-sticky-note"></i> Doctor's Notes</h6>
                    <div id="doctorNotesDisplay" class="border rounded p-3 bg-white" style="min-height: 80px; white-space: pre-wrap;"></div>
                </div>

                <!-- Clinical Results -->
                <div class="mb-4 p-3 bg-light rounded">
                    <h6 class="mb-3"><i class="bi bi-clipboard2-pulse"></i> Clinical Results & Summary</h6>
                    <textarea id="recordSummary" class="form-control" rows="3" placeholder="Treatment results, findings, recommendations..."></textarea>
                </div>

                <!-- Prescriptions -->
                <div class="mb-4 p-3 border rounded">
                    <h6 class="mb-3"><i class="bi bi-capsule"></i> Prescriptions</h6>
                    <div id="prescriptionsBody"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addPrescriptionBtn">
                        <i class="bi bi-plus"></i> Add Prescription
                    </button>
                </div>

                <!-- Follow-up Schedule -->
                <div class="mb-4 p-3 border rounded border-warning">
                    <h6 class="mb-3 text-warning"><i class="bi bi-calendar-check"></i> Follow-up Schedule</h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Next Follow-up Date</label>
                            <input type="date" class="form-control" id="followUpDate">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Interval (months)</label>
                            <input type="number" min="1" class="form-control" id="followUpInterval" placeholder="e.g. 1 for monthly braces">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Visits</label>
                            <input type="number" min="1" class="form-control" id="followUpTotalVisits" placeholder="e.g. 12">
                        </div>
                    </div>
                </div>

                <!-- Services and Charges Section -->
                <div class="mb-4 p-3 border rounded border-primary">
                    <h6 class="mb-3 text-primary"><i class="bi bi-receipt"></i> Services & Charges (Billing)</h6>
                    <p class="text-muted small mb-3">Package service prices are fixed from the clinic price list. Only additional charges can have a custom amount.</p>

                    <h6 class="small text-uppercase text-muted mb-2">Package Services</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th width="150">Price (PHP)</th>
                                    <th width="60"></th>
                                </tr>
                            </thead>
                            <tbody id="billingServicesBody">
                                <tr id="billingServicesEmptyRow">
                                    <td colspan="3" class="text-muted small">No package services added.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
                        <select id="addServiceSelect" class="form-select form-select-sm" style="max-width:280px;">
                            <option value="">Add service from price list...</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addBillingServiceBtn">
                            <i class="bi bi-plus"></i> Add Service
                        </button>
                    </div>

                    <h6 class="small text-uppercase text-muted mb-2">Additional Charges</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="billingChargesTable">
                            <thead>
                                <tr>
                                    <th>Charge Description</th>
                                    <th width="150">Amount (PHP)</th>
                                    <th width="60"></th>
                                </tr>
                            </thead>
                            <tbody id="billingChargesBody">
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addBillingChargeBtn">
                                            <i class="bi bi-plus"></i> Add Charge
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <h6 class="small text-uppercase text-muted mb-2 mt-3">Discounts</h6>
                    <p class="text-muted small mb-2">Apply senior/PWD discount or a custom rate approved by the doctor.</p>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountNone" value="none" checked>
                                <label class="form-check-label" for="discountNone">No discount</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountSenior" value="senior">
                                <label class="form-check-label" for="discountSenior">Senior Citizen (<?php echo (int) billing_senior_discount_percent(); ?>%)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountPwd" value="pwd">
                                <label class="form-check-label" for="discountPwd">PWD (<?php echo (int) billing_pwd_discount_percent(); ?>%)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountCustom" value="custom">
                                <label class="form-check-label" for="discountCustom">Custom discount</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div id="customDiscountFields" style="display:none;">
                                <label class="form-label">Custom discount (%)</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" id="customDiscountPercent" value="20" placeholder="e.g. 25">
                                <label class="form-label mt-2">Reason / notes</label>
                                <input type="text" class="form-control form-control-sm" id="discountNotes" maxlength="255" placeholder="e.g. Doctor approved additional discount">
                            </div>
                        </div>
                    </div>
                    <div class="p-2 bg-white border rounded mb-3" id="billingTotalsSummary">
                        <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="billingSubtotalDisplay">₱0.00</span></div>
                        <div class="d-flex justify-content-between small text-success billing-discount-summary-row" id="billingDiscountRow" style="display:none;">
                            <span id="billingDiscountLabel">Discount</span><span id="billingDiscountDisplay">-₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>Total Due</span><span id="billingTotalDisplay">₱0.00</span></div>
                    </div>

                    <div class="mt-3 p-2 bg-light rounded">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="useInstallment">
                            <label class="form-check-label" for="useInstallment">Enable installment plan (50% down payment)</label>
                        </div>
                        <div id="installmentOptions" style="display:none;">
                            <label class="form-label">Number of monthly terms (customizable)</label>
                            <input type="number" min="1" max="60" class="form-control form-control-sm" id="installmentTerms" value="6" style="max-width:200px;">
                        </div>
                    </div>
                </div>

                <!-- Patient Profiling Form -->
                <div class="mb-4">
                    <h6 class="mb-3"><i class="bi bi-clipboard2-pulse"></i> Visit Clinical Exam (This Appointment)</h6>
                    <p class="text-muted small">Exam findings for this visit go on the patient history record, not background profiling.</p>
                    <form id="secretaryProfilingForm">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Case History</label>
                                <textarea class="form-control visit-clinical-field" name="case_history" rows="2" placeholder="Patient case history for this visit"></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Chief Complaint</label>
                                <textarea class="form-control visit-clinical-field" name="chief_complaint" rows="2" placeholder="Primary reason for visit"></textarea>
                            </div>
                        </div>

                        <h6 class="small text-uppercase text-muted mb-2">Clinical Assessment <span class="text-lowercase fw-normal">(click option again to unselect)</span></h6>
                        <div class="row mb-3">
                            <div class="col-md-6 col-lg-4 mb-3">
                                <label class="form-label d-block">Consistency Gingiva</label>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" id="gingiva_firm" value="Firm"><label class="form-check-label" for="gingiva_firm">Firm</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" id="gingiva_hyper" value="Hyper plastic"><label class="form-check-label" for="gingiva_hyper">Hyper plastic</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" id="gingiva_smooth" value="Smooth"><label class="form-check-label" for="gingiva_smooth">Smooth</label></div>
                            </div>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <label class="form-label d-block">Oral Hygiene</label>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" id="oral_good" value="Good"><label class="form-check-label" for="oral_good">Good</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" id="oral_fair" value="Fair"><label class="form-check-label" for="oral_fair">Fair</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" id="oral_bad" value="Bad"><label class="form-check-label" for="oral_bad">Bad</label></div>
                            </div>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <label class="form-label d-block">Gingival Color</label>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="gingival_color" id="color_pink" value="Pink"><label class="form-check-label" for="color_pink">Pink</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="gingival_color" id="color_red" value="Bright red"><label class="form-check-label" for="color_red">Bright red</label></div>
                            </div>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <label class="form-label d-block">Lymph Nodes</label>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="lymph_nodes" id="lymph_not" value="Not palpable"><label class="form-check-label" for="lymph_not">Not palpable</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="lymph_nodes" id="lymph_pal" value="Palpable"><label class="form-check-label" for="lymph_pal">Palpable</label></div>
                            </div>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <label class="form-label d-block">Tongue</label>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="tongue" id="tongue_normal" value="Normal"><label class="form-check-label" for="tongue_normal">Normal</label></div>
                                <div class="form-check"><input type="radio" class="form-check-input visit-clinical-field" name="tongue" id="tongue_coated" value="Coated"><label class="form-check-label" for="tongue_coated">Coated</label></div>
                            </div>
                        </div>

                        <h6 class="small text-uppercase text-muted mb-2">Additional Clinical Findings</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Occlusion</label>
                                <input type="text" class="form-control visit-clinical-field" name="occlusion" placeholder="e.g., Angle Class I">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cl. I Type</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_i_type" placeholder="Normal">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cl.II Division</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_ii_division">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cl.II SubDivision</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_ii_subdivision">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cl.II Type</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_ii_type">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cl.III Division</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_iii_division">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cl.III SubDivision</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_iii_subdivision">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cl.III Extra</label>
                                <input type="text" class="form-control visit-clinical-field" name="cl_iii_extra">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Operation if any</label>
                                <input type="text" class="form-control visit-clinical-field" name="operation_if_any">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Blood Sugar</label>
                                <input type="text" class="form-control visit-clinical-field" name="blood_sugar" placeholder="Value">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Blood Sugar Status</label>
                                <div>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_normal">
                                        <label class="form-check-label">Normal</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_high_low">
                                        <label class="form-check-label">High/Low</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_low">
                                        <label class="form-check-label">Low</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bleeding Time</label>
                                <input type="text" class="form-control visit-clinical-field" name="bleeding_time">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Clotting Time</label>
                                <input type="text" class="form-control visit-clinical-field" name="clotting_time">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Radiographic Interpretation</label>
                                <textarea class="form-control visit-clinical-field" name="radiographic_interpretation" rows="2"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Allergy</label>
                                <input type="text" class="form-control visit-clinical-field" name="allergy_notes" placeholder="No">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Blood Diseases</label>
                                <input type="text" class="form-control visit-clinical-field" name="blood_diseases">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fainting</label>
                                <input type="text" class="form-control visit-clinical-field" name="fainting">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">B.P. (Blood Pressure)</label>
                                <input type="text" class="form-control visit-clinical-field" name="blood_pressure">
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Odontogram Section -->
                <div class="mb-4">
                    <h6 class="mb-3"><i class="bi bi-box"></i> Odontogram</h6>
                    <p class="text-muted small">Use the 2D chart to set status and comments. Switch to 3D preview if needed.</p>
                    <div class="d-flex align-items-center mb-2 gap-2">
                        <button type="button" id="openSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark">Full Screen</button>
                    </div>
                    <div id="secretaryOdontogramViewport" class="odontogram-viewport">
                        <button type="button" id="closeSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                            <i class="bi bi-x-lg"></i> Close
                        </button>
                        <div id="secretaryOdontogramContainer" class="secretary-odontogram-container">
                            <iframe id="odontogramFrame" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="submitSecretaryRecordBtn">
                    <i class="bi bi-check-lg"></i> Create Record
                </button>
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
    return getAppRoot() + '/' + path;
}

window.recordBillingCatalog = [];
window.recordBillingServiceIds = new Set();

async function openSecretaryRecordModal(appointmentId, patientId) {
    currentRecordAppointmentId = appointmentId;
    currentRecordPatientId = patientId;

    const patientNameEl = document.getElementById('secretaryRecordPatientName');
    if (patientNameEl) {
        patientNameEl.textContent = 'Loading patient...';
    }
    
    try {
        // Fetch doctor media and notes
        const res = await fetch('../assets/api/appointments/get_doctor_media.php?appointment_id=' + appointmentId);
        const data = await res.json();
        
        if (!data.success) {
            if (patientNameEl) {
                patientNameEl.textContent = '';
            }
            notifySecretary('Error loading appointment data: ' + (data.message || ''), 'danger');
            return;
        }

        if (patientNameEl) {
            const patientName = String(data.appointment?.patient_name || '').trim();
            patientNameEl.textContent = patientName ? 'Patient: ' + patientName : '';
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
        notifySecretary('Error loading appointment: ' + e.message, 'danger');
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
            notifySecretary('Server error: invalid response while saving record.', 'danger');
            secretaryRecordSubmitting = false;
            return;
        }
        if (!json.success) {
            notifySecretary('Error: ' + (json.message || 'Failed to create record'), 'danger');
            secretaryRecordSubmitting = false;
            return;
        }
        
        notifySecretary(json.already_processed || json.idempotent_replay ? 'Clinical record was already submitted.' : 'Clinical record created successfully!', 'success');
        bootstrap.Modal.getInstance(document.getElementById('secretaryRecordModal')).hide();
        
        // Reload page to update queue
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        console.error(e);
        notifySecretary('Error: ' + e.message, 'danger');
        secretaryRecordSubmitting = false;
    }
});
</script>

<script>
window.CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
window.CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>
