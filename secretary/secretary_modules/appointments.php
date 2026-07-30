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
appt_ensure_cancellation_reason_column($conn);
appt_ensure_needs_reassignment_column($conn);
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
$view = $_GET['view'] ?? 'all'; // 'all' | 'today' | 'tomorrow' | 'one_hour' | 'missed' | 'no_show' | 'reschedule'
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
if (!in_array($view, ['all', 'today', 'tomorrow', 'one_hour', 'missed', 'no_show', 'reschedule'], true)) {
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
        $curStmt = $conn->prepare('SELECT status, patient_id, user_id, appointment_date FROM appointments WHERE appointment_id = ? LIMIT 1');
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

        appt_ensure_cancellation_reason_column($conn);

        $stmt = $conn->prepare('UPDATE appointments SET status = ?, cancellation_reason = ? WHERE appointment_id = ?');
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

        if (function_exists('wf_notify_appointment_cancelled')) {
            wf_notify_appointment_cancelled(
                $conn,
                $appointmentId,
                (int)$cur['patient_id'],
                (int)$cur['user_id'],
                (string)$cur['appointment_date'],
                $reason
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
$pat_stmt = $conn->prepare('SELECT patient_id, first_name, middle_name, last_name, email, date_of_birth FROM patients ORDER BY last_name, first_name');
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
];

// Top-level section tabs
$activeSection = $_GET['section'] ?? 'appointments';
if (!in_array($activeSection, ['appointments', 'new_customers'], true)) {
    $activeSection = 'appointments';
}
?>

<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <?php if ($activeSection === 'new_customers'): ?>
            <span class="module-page-header__crumb">Appointments</span> <span class="module-page-header__sep">&gt;</span> New Patients
        <?php else: ?>
            Appointments
        <?php endif; ?>
    </h4>
</div>

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
            <span class="nav-dot<?php echo (int)$guestBookingsCount > 0 ? ' is-active' : ''; ?>" id="dotNewCustomers" data-count="<?php echo (int)$guestBookingsCount; ?>"></span>
        </a>
    </li>
</ul>

<?php if ($activeSection === 'new_customers'): ?>

<!-- New Customers / Guest Bookings -->
<div class="card border-0">
    
    <div class="card-body">
        <nav class="module-section-nav module-section-nav--compact mb-3" aria-label="New patient status">
            <div class="module-section-nav__track">
                <ul class="nav nav-pills mb-0" id="newCustomerStatusTabs">
                    <li class="nav-item"><button type="button" class="nav-link active new-cust-status-filter" data-filter="all">All</button></li>
                    <li class="nav-item"><button type="button" class="nav-link new-cust-status-filter" data-filter="pending">Pending</button></li>
                    <li class="nav-item"><button type="button" class="nav-link new-cust-status-filter" data-filter="approved">Approved</button></li>
                    <li class="nav-item"><button type="button" class="nav-link new-cust-status-filter" data-filter="processed">Processed</button></li>
                    <li class="nav-item"><button type="button" class="nav-link new-cust-status-filter" data-filter="declined">Declined</button></li>
                </ul>
            </div>
        </nav>
        <div class="unified-table-search-toolbar mb-3">
            <div class="unified-table-search-wrap module-search-inline">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input id="newCustomerSearch" type="search" class="form-control unified-table-search-input"
                    placeholder="Search by name, email, or phone..." aria-label="Search new customers">
                <button type="button" class="unified-table-search-clear" id="newCustomerSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
            </div>
            <div class="module-search-inline d-flex align-items-center gap-2">
                <label for="newCustomerDateFilter" class="form-label small text-muted mb-0">Preferred date</label>
                <input type="date" id="newCustomerDateFilter" class="form-control form-control-sm" style="width:auto;" aria-label="Filter by preferred date">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="newCustomerDateFilterClear" title="Clear date filter">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <?php
                $guestBookings = [];
                $gbRes = $conn->query("SELECT b.*, a.appointment_id AS converted_appt_id, a.status AS converted_appt_status FROM bookings b LEFT JOIN appointments a ON a.booking_id = b.booking_id WHERE b.status IN ('verified','approved','converted','cancelled') AND NOT (b.status IN ('verified','approved') AND b.preferred_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY)) ORDER BY b.created_at DESC");
                if ($gbRes) {
                    while ($gbRow = $gbRes->fetch_assoc()) {
                        $guestBookings[] = $gbRow;
                    }
                }

                $archivedBookings = [];
                $abRes = $conn->query("SELECT b.*, a.appointment_id AS converted_appt_id FROM bookings b LEFT JOIN appointments a ON a.booking_id = b.booking_id WHERE b.status IN ('verified','approved') AND b.preferred_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY) ORDER BY b.preferred_date DESC");
                if ($abRes) {
                    while ($abRow = $abRes->fetch_assoc()) {
                        $archivedBookings[] = $abRow;
                    }
                }
                ?>
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
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
                        <th>Preferred Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($guestBookings)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                No guest bookings yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($guestBookings as $gb): ?>
                            <?php
                                // A linked appointment already past creation (checked in, in session, or further)
                                // means this booking is already processed, even if bookings.status never got flipped
                                // to 'converted' (e.g. the convert_booking status update failed after the appointment
                                // was created). Treat both cases as processed so the Process button can't be re-clicked.
                                $alreadyProcessedAppt = in_array($gb['converted_appt_status'] ?? '', [
                                    'checked_in', 'in_progress', 'completed_by_doctor',
                                    'awaiting_secretary_records', 'records_complete', 'completed',
                                ], true);
                                $isConverted = ($gb['status'] === 'converted') || $alreadyProcessedAppt;
                                $isDeclined = ($gb['status'] === 'cancelled');
                                $rowStatus = $isDeclined ? 'declined' : ($isConverted ? 'processed' : ($gb['status'] === 'approved' ? 'approved' : 'pending'));
                            ?>
                            <tr data-status="<?php echo $rowStatus; ?>" data-date="<?php echo htmlspecialchars(date('Y-m-d', strtotime($gb['preferred_date']))); ?>" data-search="<?php echo htmlspecialchars(strtolower(trim($gb['first_name'] . ' ' . $gb['last_name']) . ' ' . $gb['email'] . ' ' . ($gb['phone_number'] ?? ''))); ?>" style="<?php echo ($isConverted || $isDeclined) ? 'opacity:0.7;' : ''; ?>">
                                <td><strong>#<?php echo (int)$gb['booking_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars(trim($gb['first_name'] . ' ' . $gb['last_name'])); ?></td>
                                <td><span class="badge bg-primary"><?php echo date('M j, Y', strtotime($gb['preferred_date'])); ?></span></td>
                                <td>
                                    <?php if ($isDeclined): ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Declined</span>
                                    <?php elseif ($isConverted): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Processed</span>
                                    <?php elseif ($gb['status'] === 'approved'): ?>
                                        <span class="badge bg-info"><i class="bi bi-check-circle"></i> Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#bookingDetailsModal"
                                            onclick='openBookingDetailsModal(<?php echo htmlspecialchars(json_encode([
                                                'booking_id' => (int)$gb['booking_id'],
                                                'name' => trim((string)$gb['first_name'] . ' ' . (string)$gb['last_name']),
                                                'email' => (string)($gb['email'] ?? ''),
                                                'phone_number' => (string)($gb['phone_number'] ?? ''),
                                                'preferred_date' => date('M j, Y', strtotime($gb['preferred_date'])),
                                                'preferred_time' => (string)($gb['preferred_time'] ?? ''),
                                                'created_at' => date('M j, g:i A', strtotime($gb['created_at'])),
                                                'status' => $isDeclined ? 'Declined' : ($isConverted ? 'Processed' : (($gb['status'] === 'approved') ? 'Approved' : 'Pending')),
                                                'reason' => (string)($gb['reason'] ?? ''),
                                                'decline_reason' => (string)($gb['decline_reason'] ?? ''),
                                            ]), ENT_QUOTES, 'UTF-8'); ?>)' title="View details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if (!$isConverted && !$isDeclined): ?>
                                            <?php if ($gb['status'] !== 'approved'): ?>
                                                <button type="button" class="btn btn-sm btn-primary" onclick="approveBooking(<?php echo (int)$gb['booking_id']; ?>)">
                                                    <i class="bi bi-check2-square"></i> Approve
                                                </button>
                                            <?php else: ?>
                                                <span class="badge bg-info align-self-center"><i class="bi bi-check"></i> Approved</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-success"
                                                onclick='openConvertBookingModal(<?php echo htmlspecialchars(json_encode([
                                                    'booking_id' => (int)$gb['booking_id'],
                                                    'first_name' => $gb['first_name'],
                                                    'last_name' => $gb['last_name'],
                                                    'email' => $gb['email'],
                                                    'phone_number' => $gb['phone_number'] ?? '',
                                                    'date_of_birth' => $gb['date_of_birth'] ?? '',
                                                    'preferred_date' => $gb['preferred_date'],
                                                    'preferred_time' => $gb['preferred_time'],
                                                    'reason' => $gb['reason'] ?? '',
                                                ]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                                <i class="bi bi-arrow-right-circle"></i> Process
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick='openRescheduleBookingModal(<?php echo htmlspecialchars(json_encode([
                                                    'booking_id' => (int)$gb['booking_id'],
                                                    'name' => trim((string)$gb['first_name'] . ' ' . (string)$gb['last_name']),
                                                    'preferred_date' => date('Y-m-d', strtotime($gb['preferred_date'])),
                                                    'preferred_time' => (string)($gb['preferred_time'] ?? ''),
                                                ]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                                <i class="bi bi-calendar3"></i> Reschedule
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger"
                                                onclick="openDeclineBookingModal(<?php echo (int)$gb['booking_id']; ?>, '<?php echo htmlspecialchars(addslashes(trim($gb['first_name'] . ' ' . $gb['last_name'])), ENT_QUOTES, 'UTF-8'); ?>')">
                                                <i class="bi bi-x-circle"></i> Decline
                                            </button>
                                        <?php endif; ?>
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

<!-- Decline Booking Modal -->
<div class="modal fade" id="declineBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle"></i> Decline Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Declining booking for <strong id="declineBookingPatientName"></strong>. This will notify the guest by email.</p>
                <input type="hidden" id="declineBookingId">
                <label for="declineBookingReason" class="form-label"><strong>Reason for Declining</strong></label>
                <textarea class="form-control" id="declineBookingReason" rows="3" placeholder="Enter reason for declining this booking" required></textarea>
                <div id="declineBookingAlert" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="declineBookingConfirmBtn">
                    <i class="bi bi-x-circle"></i> Decline Booking
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reschedule Booking Modal -->
<div class="modal fade" id="rescheduleBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar3"></i> Reschedule Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Rescheduling booking for <strong id="rescheduleBookingPatientName"></strong>. This will notify the guest by email.</p>
                <input type="hidden" id="rescheduleBookingId">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label for="rescheduleBookingDate" class="form-label"><strong>New Date</strong></label>
                        <input type="date" class="form-control" id="rescheduleBookingDate" required>
                    </div>
                    <div class="col-md-6">
                        <label for="rescheduleBookingTime" class="form-label"><strong>New Time</strong></label>
                        <input type="time" class="form-control" id="rescheduleBookingTime" required>
                    </div>
                </div>
                <div id="rescheduleBookingAlert" class="alert alert-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="rescheduleBookingConfirmBtn">
                    <i class="bi bi-calendar3"></i> Reschedule Booking
                </button>
            </div>
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
                        <th>Status</th>
                        <th>Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($archivedBookings)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-3">No archived bookings.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($archivedBookings as $ab): ?>
                            <tr style="opacity:0.6;">
                                <td><strong>#<?php echo (int)$ab['booking_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars(trim($ab['first_name'] . ' ' . $ab['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($ab['email']); ?></td>
                                <td><?php echo htmlspecialchars($ab['phone_number'] ?? '—'); ?></td>
                                <td><?php echo date('M j, Y', strtotime($ab['preferred_date'])); ?></td>
                                <td><?php echo htmlspecialchars($ab['preferred_time']); ?></td>
                                <td>
                                    <?php if ($ab['status'] === 'approved'): ?>
                                        <span class="badge bg-info"><i class="bi bi-check-circle"></i> Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, g:i A', strtotime($ab['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Booking Details Modal -->
<div class="modal fade" id="bookingDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-lines-fill"></i> Booking Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="dpp-header">
                    <div class="dpp-avatar" id="bookingDetailsAvatar">?</div>
                    <div>
                        <p class="dpp-name" id="bookingDetailsName"></p>
                        <div class="dpp-chips">
                            <span class="dpp-chip"><i class="bi bi-hash"></i> <span id="bookingDetailsRef"></span></span>
                            <span class="dpp-chip"><i class="bi bi-calendar-event"></i> <span id="bookingDetailsDate"></span></span>
                            <span class="dpp-chip"><i class="bi bi-clock"></i> <span id="bookingDetailsTime"></span></span>
                        </div>
                    </div>
                </div>

                <div id="bookingDetailsStatusBox"></div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-clipboard2-pulse"></i> Booking Request</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Reason for Visit</div>
                        <div class="dpp-field-value" id="bookingDetailsReason"></div>
                    </div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Requested On</div>
                        <div class="dpp-field-value" id="bookingDetailsRequested"></div>
                    </div>
                </div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-person-lines-fill"></i> Contact Info</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Email</div>
                        <div class="dpp-field-value" id="bookingDetailsEmail"></div>
                    </div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Phone</div>
                        <div class="dpp-field-value" id="bookingDetailsPhone"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Process Booking to Appointment Modal -->
<div class="modal fade" id="convertBookingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable" role="document">
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
                    <div class="convert-stepper mb-3" id="convertStepper">
                        <div class="convert-stepper__step is-active" id="convertStepBadge1" data-step="1">
                            <span class="convert-stepper__circle"><i class="bi bi-calendar2-check"></i></span>
                            <span class="convert-stepper__label">Appointment</span>
                        </div>
                        <div class="convert-stepper__track"><span class="convert-stepper__track-fill"></span></div>
                        <div class="convert-stepper__step" id="convertStepBadge2" data-step="2">
                            <span class="convert-stepper__circle"><i class="bi bi-clipboard2-pulse"></i></span>
                            <span class="convert-stepper__label">Profiling</span>
                        </div>
                    </div>

                    <div id="convertStep1">
                        <div class="alert alert-info mb-2 py-2 px-3" style="font-size:0.82rem;">
                            <strong>Patient:</strong> <span id="convertBookingPatientInfo"></span>
                        </div>

                        <!-- Static walk-in note: processing always books the appointment for today -->
                        <div class="mb-2 px-2 py-1" id="convertWalkInNote" style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:6px;font-size:0.78rem;color:#0f766e;font-weight:600;">
                            <i class="bi bi-person-walking"></i> <span id="convertWalkInNoteText">Walk-in Today</span>
                        </div>

                        <!-- Doctor -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.8rem;">Select Doctor *</label>
                            <select id="convertDoctorSelect" name="user_id" class="form-select form-select-sm" required style="font-size:0.82rem;">
                                <option value="">-- Select Doctor --</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Services -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.8rem;">Add Service</label>
                            <div class="input-group input-group-sm">
                                <select id="convertServiceSelect" class="form-select form-select-sm" style="font-size:0.82rem;">
                                    <option value="">-- Select Service --</option>
                                </select>
                                <button type="button" class="btn btn-success btn-sm" onclick="addProcessService()">
                                    <i class="bi bi-plus"></i> Add
                                </button>
                            </div>
                        </div>

                        <!-- Selected Services Table -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.8rem;">Selected Services</label>
                            <div class="table-responsive" style="max-height:150px;overflow-y:auto;">
                                <table class="table table-sm table-bordered mb-0" id="processServicesTable">
                                    <thead class="table-light" style="position:sticky;top:0;">
                                        <tr style="font-size:0.75rem;">
                                            <th>Service Name</th>
                                            <th style="width:70px;">Time</th>
                                            <th style="width:80px;">Price</th>
                                            <th style="width:35px;"></th>
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

                        <!-- Reason (optional) -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.8rem;">Reason for Visit <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea id="convertReason" class="form-control form-control-sm" rows="2" style="font-size:0.82rem;" placeholder="Optional reason or notes..."></textarea>
                        </div>
                    </div>

                    <div id="convertStep2" style="display:none;">
                        <div class="alert alert-secondary mb-2 py-2 px-3" style="font-size:0.78rem;">
                            New patient background/profiling — used by the doctor during the visit.
                        </div>

                        <!-- Gender -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Gender</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_gender" id="pf_gender_male" value="Male"><label class="form-check-label fw-semibold" for="pf_gender_male" style="font-size:0.82rem;color:#334155;">Male</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_gender" id="pf_gender_female" value="Female"><label class="form-check-label fw-semibold" for="pf_gender_female" style="font-size:0.82rem;color:#334155;">Female</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_gender" id="pf_gender_other" value="Other"><label class="form-check-label fw-semibold" for="pf_gender_other" style="font-size:0.82rem;color:#334155;">Other</label></div>
                            </div>
                        </div>

                        <!-- Race -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Race</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_asian" value="Asian"><label class="form-check-label fw-semibold" for="pf_race_asian" style="font-size:0.82rem;color:#334155;">Asian</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_caucasian" value="Caucasian"><label class="form-check-label fw-semibold" for="pf_race_caucasian" style="font-size:0.82rem;color:#334155;">Caucasian</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_black" value="Black/African American"><label class="form-check-label fw-semibold" for="pf_race_black" style="font-size:0.82rem;color:#334155;">Black</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_hispanic" value="Hispanic/Latino"><label class="form-check-label fw-semibold" for="pf_race_hispanic" style="font-size:0.82rem;color:#334155;">Hispanic</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_native" value="Native American"><label class="form-check-label fw-semibold" for="pf_race_native" style="font-size:0.82rem;color:#334155;">Native</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_pacific" value="Pacific Islander"><label class="form-check-label fw-semibold" for="pf_race_pacific" style="font-size:0.82rem;color:#334155;">Pacific</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_mixed" value="Mixed"><label class="form-check-label fw-semibold" for="pf_race_mixed" style="font-size:0.82rem;color:#334155;">Mixed</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_other" value="Other"><label class="form-check-label fw-semibold" for="pf_race_other" style="font-size:0.82rem;color:#334155;">Other</label></div>
                            </div>
                        </div>

                        <!-- Allergies (text) -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Allergies</label>
                            <input type="text" id="pf_allergies" class="form-control" placeholder="e.g. Penicillin, Latex" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                        </div>

                        <!-- Smoking -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Smoking</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_smoking" id="pf_smoking_no" value="Non-smoker"><label class="form-check-label fw-semibold" for="pf_smoking_no" style="font-size:0.82rem;color:#334155;">Non-smoker</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_smoking" id="pf_smoking_former" value="Former smoker"><label class="form-check-label fw-semibold" for="pf_smoking_former" style="font-size:0.82rem;color:#334155;">Former</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_smoking" id="pf_smoking_current" value="Current smoker"><label class="form-check-label fw-semibold" for="pf_smoking_current" style="font-size:0.82rem;color:#334155;">Current</label></div>
                            </div>
                        </div>

                        <!-- Alcohol -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Alcohol</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_alcohol" id="pf_alcohol_no" value="Non-drinker"><label class="form-check-label fw-semibold" for="pf_alcohol_no" style="font-size:0.82rem;color:#334155;">Non-drinker</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_alcohol" id="pf_alcohol_social" value="Social drinker"><label class="form-check-label fw-semibold" for="pf_alcohol_social" style="font-size:0.82rem;color:#334155;">Social</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_alcohol" id="pf_alcohol_regular" value="Regular drinker"><label class="form-check-label fw-semibold" for="pf_alcohol_regular" style="font-size:0.82rem;color:#334155;">Regular</label></div>
                            </div>
                        </div>

                        <!-- Dental Anxiety -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Dental Anxiety</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_none" value="None"><label class="form-check-label fw-semibold" for="pf_anxiety_none" style="font-size:0.82rem;color:#334155;">None</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_mild" value="Mild"><label class="form-check-label fw-semibold" for="pf_anxiety_mild" style="font-size:0.82rem;color:#334155;">Mild</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_moderate" value="Moderate"><label class="form-check-label fw-semibold" for="pf_anxiety_moderate" style="font-size:0.82rem;color:#334155;">Moderate</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_severe" value="Severe"><label class="form-check-label fw-semibold" for="pf_anxiety_severe" style="font-size:0.82rem;color:#334155;">Severe</label></div>
                            </div>
                        </div>

                        <!-- Bleeding Disorder -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Bleeding Disorder</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_none" value="None"><label class="form-check-label fw-semibold" for="pf_bleed_none" style="font-size:0.82rem;color:#334155;">None</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_hemophilia" value="Hemophilia"><label class="form-check-label fw-semibold" for="pf_bleed_hemophilia" style="font-size:0.82rem;color:#334155;">Hemophilia</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_vonw" value="Von Willebrand"><label class="form-check-label fw-semibold" for="pf_bleed_vonw" style="font-size:0.82rem;color:#334155;">Von Willebrand</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_thrombo" value="Thrombocytopenia"><label class="form-check-label fw-semibold" for="pf_bleed_thrombo" style="font-size:0.82rem;color:#334155;">Thrombocytopenia</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_other" value="Other"><label class="form-check-label fw-semibold" for="pf_bleed_other" style="font-size:0.82rem;color:#334155;">Other</label></div>
                            </div>
                        </div>

                        <!-- Pregnancy (female only) -->
                        <div id="pf_pregnancy_wrapper" class="mb-2" style="display:none;">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Pregnancy</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_not" value="Not pregnant"><label class="form-check-label fw-semibold" for="pf_preg_not" style="font-size:0.82rem;color:#334155;">Not pregnant</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_yes" value="Pregnant"><label class="form-check-label fw-semibold" for="pf_preg_yes" style="font-size:0.82rem;color:#334155;">Pregnant</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_post" value="Postpartum"><label class="form-check-label fw-semibold" for="pf_preg_post" style="font-size:0.82rem;color:#334155;">Postpartum</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_nursing" value="Nursing"><label class="form-check-label fw-semibold" for="pf_preg_nursing" style="font-size:0.82rem;color:#334155;">Nursing</label></div>
                            </div>
                        </div>

                        <!-- Textareas -->
                        <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Medical History</label><textarea id="pf_medical_history" class="form-control" rows="1" placeholder="Previous surgeries, chronic conditions..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>
                        <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Medications</label><textarea id="pf_medications" class="form-control" rows="1" placeholder="Current medications and dosages..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>
                        <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Previous Treatment</label><textarea id="pf_previous_dental_treatment" class="form-control" rows="1" placeholder="Previous dental procedures..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>

                        <!-- Conditions (checkboxes) -->
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Conditions</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_hypertensive" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_hypertensive" style="font-size:0.82rem;color:#334155;">Hypertensive</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_diabetic" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_diabetic" style="font-size:0.82rem;color:#334155;">Diabetic</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_asthmatic" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_asthmatic" style="font-size:0.82rem;color:#334155;">Asthmatic</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_heart_disease" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_heart_disease" style="font-size:0.82rem;color:#334155;">Heart Disease</label></div>
                                <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_hiv_positive" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_hiv_positive" style="font-size:0.82rem;color:#334155;">HIV+</label></div>
                            </div>
                        </div>

                        <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Notes</label><textarea id="pf_background_notes" class="form-control" rows="1" placeholder="Additional notes..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="convertBackBtn" class="btn btn-outline-secondary btn-sm" style="display:none;">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                    <button type="button" id="convertNextBtn" class="btn btn-success btn-sm">
                        Next <i class="bi bi-arrow-right"></i>
                    </button>
                    <button type="submit" id="convertSubmitBtn" class="btn btn-success btn-sm" style="display:none;">
                        <i class="bi bi-check-circle"></i> Process Booking
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openBookingDetailsModal(booking) {
    var setText = function(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value && String(value).trim() !== '' ? value : '—';
    };
    var name = booking.name || 'Unknown';
    document.getElementById('bookingDetailsName').textContent = name;
    var initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map(function(w){ return w[0].toUpperCase(); }).join('') || '?';
    document.getElementById('bookingDetailsAvatar').textContent = initials;
    setText('bookingDetailsRef', booking.booking_id ? ('#' + booking.booking_id) : '');
    setText('bookingDetailsDate', booking.preferred_date);
    setText('bookingDetailsTime', booking.preferred_time);
    setText('bookingDetailsEmail', booking.email);
    setText('bookingDetailsPhone', booking.phone_number);
    setText('bookingDetailsReason', booking.reason);
    setText('bookingDetailsRequested', booking.created_at);

    var statusBox = document.getElementById('bookingDetailsStatusBox');
    var status = String(booking.status || '').toLowerCase();
    if (status === 'declined') {
        statusBox.innerHTML = '<div class="dpp-alert-box"><div class="dpp-alert-title"><i class="bi bi-x-circle-fill"></i> Booking Declined</div>'
            + (booking.decline_reason ? '<span class="dpp-alert-tag">' + escapeHtml(booking.decline_reason) + '</span>' : '<span class="text-muted small">No reason provided.</span>') + '</div>';
    } else if (status === 'processed') {
        statusBox.innerHTML = '<div class="dpp-safe-box"><i class="bi bi-check-circle-fill"></i> Processed — converted to an appointment.</div>';
    } else if (status === 'approved') {
        statusBox.innerHTML = '<div class="dpp-safe-box"><i class="bi bi-check-circle-fill"></i> Approved — awaiting clinic visit.</div>';
    } else {
        statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#fffbeb;border-color:#fde68a;color:#92400e;"><i class="bi bi-hourglass-split"></i> Pending secretary approval.</div>';
    }
}

// New Customer Search & Filters
var activeStatusFilter = 'all';

function filterNewCustomerTable() {
    var input = document.getElementById('newCustomerSearch');
    var clearBtn = document.getElementById('newCustomerSearchClear');
    var dateInput = document.getElementById('newCustomerDateFilter');
    var filter = input ? input.value.toLowerCase() : '';
    var activeDate = dateInput ? dateInput.value : '';
    var table = document.getElementById('newCustomerTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var visibleCount = 0;
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase() + ' ' + (rows[i].getAttribute('data-search') || '');
        var rowStatus = rows[i].getAttribute('data-status') || '';
        var rowDate = rows[i].getAttribute('data-date') || '';
        var matchSearch = !filter || text.indexOf(filter) > -1;
        var matchStatus = activeStatusFilter === 'all' || rowStatus === activeStatusFilter;
        var matchDate = !activeDate || rowDate === activeDate;
        var show = matchSearch && matchStatus && matchDate;
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

// Status filter tabs
document.querySelectorAll('.new-cust-status-filter').forEach(function(item) {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.new-cust-status-filter').forEach(function(i) { i.classList.remove('active'); });
        item.classList.add('active');
        activeStatusFilter = item.getAttribute('data-filter');
        filterNewCustomerTable();
    });
});

// Preferred date filter
document.getElementById('newCustomerDateFilter')?.addEventListener('change', filterNewCustomerTable);
document.getElementById('newCustomerDateFilterClear')?.addEventListener('click', function() {
    var dateInput = document.getElementById('newCustomerDateFilter');
    if (dateInput) { dateInput.value = ''; filterNewCustomerTable(); }
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
var convertIsEarlyWalkIn = false;
var processSelectedServices = [];
var processServicesCatalog = [];

function openConvertBookingModal(booking) {
    convertBookingData = booking;
    processSelectedServices = [];
    document.getElementById('convertBookingId').value = booking.booking_id;
    document.getElementById('convertBookingPatientInfo').textContent =
        booking.first_name + ' ' + booking.last_name + ' | ' + booking.email + ' | ' + (booking.phone_number || 'No phone');

    // Populate reason from booking data
    var reasonField = document.getElementById('convertReason');
    if (reasonField) {
        reasonField.value = booking.reason || '';
    }

    // Processing always books the appointment for today. If the patient's preferred date
    // was today, this is just their scheduled online booking happening on time — no note,
    // still counts as online. If their preferred date is any other day (they came in early
    // for a later booking, or came in after a missed date), this is an unscheduled walk-in —
    // show the note and mark it as non-online. bookings.preferred_date is left untouched either way.
    var todayStr = getTodayDateStringSecretary();
    convertIsEarlyWalkIn = !!(booking.preferred_date && booking.preferred_date !== todayStr);
    var noteEl = document.getElementById('convertWalkInNote');
    var noteText = document.getElementById('convertWalkInNoteText');
    if (noteEl) {
        noteEl.style.display = convertIsEarlyWalkIn ? '' : 'none';
    }
    if (noteText && convertIsEarlyWalkIn) {
        noteText.textContent = 'Walk-in Today — original preferred date was ' + booking.preferred_date;
    }

    // Reset services table
    renderProcessServicesTable();

    // Reset doctor and timeslot
    document.getElementById('convertDoctorSelect').value = '';
    document.getElementById('convertTimeslotSelect').innerHTML = '<option value="">-- Select Doctor first --</option>';
    document.getElementById('convertTimeslotSelect').disabled = true;
    document.getElementById('convertServiceSelect').innerHTML = '<option value="">-- Select Service --</option>';

    resetConvertProfilingForm();
    showConvertStep(1);

    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('convertBookingModal'));
    modal.show();
}

// --- 2-step wizard: step 1 (appointment) / step 2 (new-patient profiling) ---
function showConvertStep(step) {
    var step1 = document.getElementById('convertStep1');
    var step2 = document.getElementById('convertStep2');
    var backBtn = document.getElementById('convertBackBtn');
    var nextBtn = document.getElementById('convertNextBtn');
    var submitBtn = document.getElementById('convertSubmitBtn');
    var badge1 = document.getElementById('convertStepBadge1');
    var badge2 = document.getElementById('convertStepBadge2');
    var trackFill = document.querySelector('#convertStepper .convert-stepper__track-fill');

    if (step === 2) {
        step1.style.display = 'none';
        step2.style.display = '';
        backBtn.style.display = '';
        nextBtn.style.display = 'none';
        submitBtn.style.display = '';
        badge1.className = 'convert-stepper__step is-done';
        badge2.className = 'convert-stepper__step is-active';
        if (trackFill) trackFill.style.width = '100%';
    } else {
        step1.style.display = '';
        step2.style.display = 'none';
        backBtn.style.display = 'none';
        nextBtn.style.display = '';
        submitBtn.style.display = 'none';
        badge1.className = 'convert-stepper__step is-active';
        badge2.className = 'convert-stepper__step';
        if (trackFill) trackFill.style.width = '0%';
    }
}

function resetConvertProfilingForm() {
    document.querySelectorAll('#convertStep2 input[type="radio"]').forEach(function(r) { r.checked = false; });
    document.querySelectorAll('#convertStep2 input[type="checkbox"]').forEach(function(c) { c.checked = false; });
    document.querySelectorAll('#convertStep2 textarea, #convertStep2 input[type="text"]').forEach(function(t) { t.value = ''; });
    updateConvertPregnancyVisibility('');
}

function getConvertRadioValue(name) {
    var checked = document.querySelector('#convertStep2 input[name="' + name + '"]:checked');
    return checked ? checked.value : '';
}

function updateConvertPregnancyVisibility(gender) {
    var wrapper = document.getElementById('pf_pregnancy_wrapper');
    if (!wrapper) return;
    var isFemale = gender === 'Female';
    wrapper.style.display = isFemale ? '' : 'none';
    if (!isFemale) {
        document.querySelectorAll('input[name="pf_pregnancy_status"]').forEach(function(r) { r.checked = false; });
    }
}

document.querySelectorAll('input[name="pf_gender"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        updateConvertPregnancyVisibility(this.value);
    });
});

document.getElementById('convertNextBtn')?.addEventListener('click', function() {
    var form = document.getElementById('convertBookingForm');
    var doctorField = document.getElementById('convertDoctorSelect');
    var timeslotField = document.getElementById('convertTimeslotSelect');
    if (!doctorField.value || !timeslotField.value) {
        form.reportValidity();
        return;
    }
    showConvertStep(2);
});

document.getElementById('convertBackBtn')?.addEventListener('click', function() {
    showConvertStep(1);
});

// Closes the Process Booking modal safely. Guards against a Bootstrap quirk where
// calling .hide() on a modal instance whose backdrop state got out of sync throws
// "Cannot read properties of null/undefined (reading 'backdrop')" — if that happens,
// fall back to removing the backdrop/body classes manually so the UI doesn't get stuck.
function closeConvertBookingModal() {
    var modalEl = document.getElementById('convertBookingModal');
    if (!modalEl) return;
    try {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    } catch (err) {
        console.warn('Modal hide() failed, cleaning up manually:', err);
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.querySelectorAll('.modal-backdrop').forEach(function(el) { el.remove(); });
    }
}

function getTodayDateStringSecretary() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
}

// Approve a booking
async function approveBooking(bookingId) {
    if (typeof showConfirmModal === 'function') {
        if (!(await showConfirmModal({
            title: 'Approve Booking',
            message: 'Approve this booking request? The patient will see "Booking Confirmed" in their tracker.',
            confirmText: 'Approve'
        }))) return;
    }

    try {
        const resp = await fetch('../assets/api/bookings/approve_booking.php?booking_id=' + bookingId, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(window.secretaryCsrfToken || '')
        });
        const data = await resp.json();
        if (data.success) {
            if (typeof showArrToast === 'function') {
                showArrToast('Booking approved.', 'success');
            } else if (typeof showToast === 'function') {
                showToast('Booking approved.', 'success');
            }
            location.reload();
        } else {
            if (typeof showArrToast === 'function') {
                showArrToast(data.message || 'Failed to approve.', 'danger');
            } else if (typeof showToast === 'function') {
                showToast(data.message || 'Failed to approve.', 'danger');
            }
        }
    } catch(e) {
        if (typeof showArrToast === 'function') {
            showArrToast('Error: ' + e.message, 'danger');
        } else if (typeof showToast === 'function') {
            showToast('Error: ' + e.message, 'danger');
        }
    }
}

function openDeclineBookingModal(bookingId, patientName) {
    document.getElementById('declineBookingId').value = bookingId;
    document.getElementById('declineBookingPatientName').textContent = patientName || '';
    document.getElementById('declineBookingReason').value = '';
    var alertEl = document.getElementById('declineBookingAlert');
    alertEl.style.display = 'none';
    alertEl.textContent = '';
    var modalEl = document.getElementById('declineBookingModal');
    (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
}

function time12To24(t) {
    var m = String(t || '').match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var min = m[2];
    var mer = m[3].toUpperCase();
    if (mer === 'AM') { if (h === 12) h = 0; } else { if (h !== 12) h += 12; }
    return String(h).padStart(2, '0') + ':' + min;
}

function time24To12(t) {
    var m = String(t || '').match(/^(\d{1,2}):(\d{2})/);
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var min = m[2];
    var mer = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + min + ' ' + mer;
}

function openRescheduleBookingModal(booking) {
    document.getElementById('rescheduleBookingId').value = booking.booking_id;
    document.getElementById('rescheduleBookingPatientName').textContent = booking.name || '';
    document.getElementById('rescheduleBookingDate').value = booking.preferred_date || '';
    document.getElementById('rescheduleBookingTime').value = time12To24(booking.preferred_time || '');
    var alertEl = document.getElementById('rescheduleBookingAlert');
    alertEl.style.display = 'none';
    alertEl.textContent = '';
    var modalEl = document.getElementById('rescheduleBookingModal');
    (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
}

document.getElementById('rescheduleBookingConfirmBtn')?.addEventListener('click', async function() {
    var bookingId = document.getElementById('rescheduleBookingId').value;
    var newDate = document.getElementById('rescheduleBookingDate').value;
    var newTime24 = document.getElementById('rescheduleBookingTime').value;
    var alertEl = document.getElementById('rescheduleBookingAlert');
    if (!newDate || !newTime24) {
        alertEl.textContent = 'Please select a new date and time.';
        alertEl.style.display = '';
        return;
    }
    this.disabled = true;
    try {
        const fd = new FormData();
        fd.append('booking_id', bookingId);
        fd.append('preferred_date', newDate);
        fd.append('preferred_time', time24To12(newTime24));
        fd.append('csrf_token', window.secretaryCsrfToken || '');
        const resp = await fetch('../assets/api/bookings/reschedule_booking.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('rescheduleBookingModal'))?.hide();
            if (typeof showArrToast === 'function') {
                showArrToast('Booking rescheduled.', 'success');
            } else if (typeof showToast === 'function') {
                showToast('Booking rescheduled.', 'success');
            }
            location.reload();
        } else {
            alertEl.textContent = data.message || 'Failed to reschedule booking.';
            alertEl.style.display = '';
        }
    } catch (e) {
        alertEl.textContent = 'Error: ' + e.message;
        alertEl.style.display = '';
    } finally {
        this.disabled = false;
    }
});

document.getElementById('declineBookingConfirmBtn')?.addEventListener('click', async function() {
    var bookingId = document.getElementById('declineBookingId').value;
    var reason = document.getElementById('declineBookingReason').value.trim();
    var alertEl = document.getElementById('declineBookingAlert');
    if (!reason) {
        alertEl.textContent = 'Please enter a reason for declining this booking.';
        alertEl.style.display = '';
        return;
    }
    this.disabled = true;
    try {
        const fd = new FormData();
        fd.append('booking_id', bookingId);
        fd.append('decline_reason', reason);
        fd.append('csrf_token', window.secretaryCsrfToken || '');
        const resp = await fetch('../assets/api/bookings/decline_booking.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('declineBookingModal'))?.hide();
            if (typeof showArrToast === 'function') {
                showArrToast('Booking declined.', 'success');
            } else if (typeof showToast === 'function') {
                showToast('Booking declined.', 'success');
            }
            location.reload();
        } else {
            alertEl.textContent = data.message || 'Failed to decline booking.';
            alertEl.style.display = '';
        }
    } catch (e) {
        alertEl.textContent = 'Error: ' + e.message;
        alertEl.style.display = '';
    } finally {
        this.disabled = false;
    }
});

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

    // Load available timeslots for today — processing always books a walk-in for today.
    loadProcessTimeslots(doctorId, getTodayDateStringSecretary());
});

function loadProcessTimeslots(doctorId, date) {
    var timeslotSelect = document.getElementById('convertTimeslotSelect');
    if (!doctorId || !date) {
        timeslotSelect.innerHTML = '<option value="">-- Select date first --</option>';
        timeslotSelect.disabled = true;
        return;
    }
    var duration = processSelectedServices.reduce(function(sum, s) { return sum + (s.duration || 0); }, 0);
    var url = '../assets/api/appointments/get_timeslots.php?doctor_id=' + doctorId + '&date=' + date;
    if (duration > 0) {
        url += '&duration=' + duration;
    }
    fetch(url)
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
    refreshProcessTimeslots();
}

// Remove service from table
function removeProcessService(serviceId) {
    processSelectedServices = processSelectedServices.filter(function(s) { return s.service_id !== serviceId; });
    renderProcessServicesTable();
    refreshProcessTimeslots();
}

// Re-fetch the timeslot dropdown for the currently selected doctor, factoring in duration.
function refreshProcessTimeslots() {
    var doctorId = document.getElementById('convertDoctorSelect').value;
    if (!doctorId) return;
    loadProcessTimeslots(doctorId, getTodayDateStringSecretary());
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
            '<td><button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="removeProcessService(' + s.service_id + ')"><i class="bi bi-x"></i></button></td>';
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
    if (submitBtn.disabled) return; // guard against double-submit while already processing

    var bookingId = document.getElementById('convertBookingId').value;
    var doctorId = document.getElementById('convertDoctorSelect').value;
    var timeslotId = document.getElementById('convertTimeslotSelect').value;
    var appointmentDate = getTodayDateStringSecretary();

    if (!doctorId) {
        notifySecretary('Please select a doctor.', 'warning');
        return;
    }
    if (!timeslotId) {
        notifySecretary('Please select a start time.', 'warning');
        return;
    }

    DentalUI.btnLoading(submitBtn, 'Processing...');

    try {
        // 1. Create patient account — profiling (allergies, hypertensive/diabetic/etc.) and
        // gender are sent in this SAME request rather than as separate follow-up fetch calls,
        // so they're saved atomically with the account instead of being a silent point of
        // failure if a later step throws or the network hiccups. The server only applies them
        // when it's actually creating a new patient (a matched existing patient keeps their
        // existing profile).
        var profiling = {
            race: getConvertRadioValue('pf_race'),
            medical_history: document.getElementById('pf_medical_history').value.trim(),
            medications: document.getElementById('pf_medications').value.trim(),
            allergies: document.getElementById('pf_allergies').value.trim(),
            smoking: getConvertRadioValue('pf_smoking'),
            alcohol: getConvertRadioValue('pf_alcohol'),
            hypertensive: document.getElementById('pf_hypertensive').checked,
            diabetic: document.getElementById('pf_diabetic').checked,
            asthmatic: document.getElementById('pf_asthmatic').checked,
            heart_disease: document.getElementById('pf_heart_disease').checked,
            hiv_positive: document.getElementById('pf_hiv_positive').checked,
            bleeding_disorder: getConvertRadioValue('pf_bleeding_disorder'),
            pregnancy_status: getConvertRadioValue('pf_pregnancy_status'),
            previous_dental_treatment: document.getElementById('pf_previous_dental_treatment').value.trim(),
            dental_anxiety: getConvertRadioValue('pf_dental_anxiety'),
            background_notes: document.getElementById('pf_background_notes').value.trim()
        };

        var patientFd = new FormData();
        patientFd.append('first_name', convertBookingData.first_name || '');
        patientFd.append('last_name', convertBookingData.last_name || '');
        patientFd.append('email', convertBookingData.email || '');
        patientFd.append('phone_number', convertBookingData.phone_number || '');
        patientFd.append('date_of_birth', convertBookingData.date_of_birth || '');
        patientFd.append('gender', getConvertRadioValue('pf_gender'));
        patientFd.append('profiling', JSON.stringify(profiling));
        patientFd.append('csrf_token', window.secretaryCsrfToken || '');

        var patientResp = await fetch('../assets/api/patients/create_walkin.php', { method: 'POST', body: patientFd });
        var patientData = await patientResp.json();

        if (!patientData.success || !patientData.patient_id) {
            notifySecretary('Error creating patient: ' + (patientData.message || 'Unknown error'), 'danger');
            DentalUI.btnRestore(submitBtn);
            return;
        }

        var patientWasNew = !patientData.already_exists;

        // 2. Create appointment with services and arrived status
        var apptFd = new FormData();
        apptFd.append('patient_id', patientData.patient_id);
        // Processing always books the appointment for today — bookings.preferred_date is left untouched.
        apptFd.append('appointment_date', appointmentDate);
        apptFd.append('user_id', doctorId);
        var modalReason = document.getElementById('convertReason')?.value?.trim() || '';
        apptFd.append('reason', modalReason || convertBookingData.reason || '');
        // Preferred date matched today: on-schedule online booking. Any other date: unscheduled walk-in.
        apptFd.append('is_online_appointment', convertIsEarlyWalkIn ? '0' : '1');
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
            // Mark booking as converted (best-effort — the appointment itself is already
            // created at this point, so a failure here shouldn't block the success flow,
            // but it's worth logging since it means bookings.status won't reflect reality).
            try {
                var convertResp = await fetch('../assets/api/bookings/convert_booking.php?booking_id=' + bookingId, { method: 'POST' });
                var convertData = await convertResp.json();
                if (!convertData.success || !convertData.updated) {
                    console.warn('Booking status was not marked converted:', convertData);
                }
            } catch (convertErr) {
                console.warn('Failed to mark booking converted:', convertErr);
            }

            // Profiling and gender are now saved atomically inside create_walkin.php (step 1
            // above) — no separate follow-up call here to silently fail.

            closeConvertBookingModal();

            notifySecretary('Booking processed! Patient account created. Password sent to: ' + (convertBookingData.email || 'email'), 'success');
            DentalUI.btnRestore(submitBtn);
            return;
        } else {
            // Appointment failed — clean up orphaned patient if we just created them
            if (patientWasNew && patientData.patient_id) {
                try {
                    await fetch('../assets/api/patients/delete_patient.php?patient_id=' + patientData.patient_id, { method: 'POST' });
                } catch(e) { /* best effort cleanup */ }
            }
            notifySecretary('Error creating appointment: ' + (apptData.message || 'Unknown error'), 'danger');
        }
    } catch (err) {
        notifySecretary('Error: ' + err.message, 'danger');
    }

    DentalUI.btnRestore(submitBtn);
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
                        <?php if ($filterKey === 'reschedule' && (int)$pendingReqCount > 0): ?>
                            <span class="nav-dot is-active" id="dotReschedule" data-count="<?php echo (int)$pendingReqCount; ?>"></span>
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
                        <th>No.</th>
                        <th>Patient Name</th>
                        <th>Doctor</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
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
                                <td><?php echo (int)$appointment['appointment_id']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?>
                                    <?php if (!empty($appointment['is_emergency']) && $appointment['is_emergency']): ?>
                                        <span class="badge bg-danger ms-1">EMERGENCY</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(($appointment['doctor_first_name'] ?? 'N/A') . ' ' . ($appointment['doctor_last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
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
                                    <?php if (!empty($appointment['needs_reassignment']) && !in_array($status, ['completed', 'cancelled', 'no_show'], true)): ?>
                                        <br><span class="badge bg-danger mt-1"><i class="bi bi-exclamation-triangle"></i> Doctor on Leave — Needs Reassignment</span>
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
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openRescheduleForAppointmentSec(<?php echo htmlspecialchars(json_encode($appointment), ENT_QUOTES, 'UTF-8'); ?>)' title="Reschedule appointment">
                                                <i class="bi bi-calendar3"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php elseif (in_array($status, ['confirmed','checked_in']) && !$isDisabled): ?>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#declineModal" onclick="setDeclineAppointment(<?php echo htmlspecialchars($appointment['appointment_id']); ?>)" title="Decline appointment">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openRescheduleForAppointmentSec(<?php echo htmlspecialchars(json_encode($appointment), ENT_QUOTES, 'UTF-8'); ?>)' title="Reschedule appointment">
                                                <i class="bi bi-calendar3"></i>
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
                                                onclick='openRescheduleModalSec(<?php echo htmlspecialchars(json_encode([
                                                    "schedule_id" => (int)$appointment["schedule_id"],
                                                    "scheduled_start" => (string)($appointment["schedule_start"] ?? ""),
                                                    "scheduled_end" => (string)($appointment["schedule_end"] ?? ""),
                                                ]), ENT_QUOTES, 'UTF-8'); ?>)'
                                                title="Reschedule follow-up schedule"
                                            >
                                                <i class="bi bi-calendar-event"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewProfilingModal" onclick="openViewProfilingModal(<?php echo (int)$appointment['patient_id']; ?>, '<?php echo htmlspecialchars($appointment['first_name']); ?>', '<?php echo htmlspecialchars($appointment['last_name']); ?>')" title="View medical history">
                                            <i class="bi bi-file-medical"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#appointmentDetailsModal" onclick='openAppointmentDetailsModal(<?php echo htmlspecialchars(json_encode([
                                            "appointment_id" => (int)$appointment["appointment_id"],
                                            "patient_name" => trim((string)$appointment["first_name"] . " " . (string)$appointment["last_name"]),
                                            "email" => (string)($appointment["email"] ?? ""),
                                            "phone_number" => (string)($appointment["phone_number"] ?? ""),
                                            "reason" => (string)($appointment["reason"] ?? ""),
                                            "cancellation_reason" => (string)($appointment["cancellation_reason"] ?? ""),
                                            "doctor_name" => trim((string)($appointment["doctor_first_name"] ?? "N/A") . " " . (string)($appointment["doctor_last_name"] ?? "")),
                                            "appointment_date" => (string)($appointment["appointment_date"] ?? ""),
                                            "is_online_appointment" => (bool)$appointment["is_online_appointment"],
                                            "status" => (string)($appointment["status"] ?? ""),
                                            "is_followup" => (bool)$isFollowUpAppointment,
                                            "schedule_status" => (string)($appointment["schedule_status"] ?? ""),
                                            "schedule_start" => (string)($appointment["schedule_start"] ?? ""),
                                            "schedule_end" => (string)($appointment["schedule_end"] ?? ""),
                                            "record_follow_up_date" => $recordFollowUpDate,
                                        ]), ENT_QUOTES, 'UTF-8'); ?>)' title="View details">
                                            <i class="bi bi-eye"></i>
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
#convertBookingModal .modal-dialog {
    max-height: 90vh;
    margin-top: 5vh;
    margin-bottom: 5vh;
}
@media (min-width: 576px) {
    #convertBookingModal .modal-dialog {
        max-width: 550px; /* ~10% wider than Bootstrap's default 500px */
    }
}
#convertBookingModal .modal-content {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
#convertBookingModal .modal-body {
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
    max-height: calc(90vh - 110px);
    scrollbar-width: thin;
}
#convertBookingModal .modal-body::-webkit-scrollbar {
    width: 8px;
}
#convertBookingModal .modal-body::-webkit-scrollbar-thumb {
    background: #c7d2e0;
    border-radius: 4px;
}
#convertBookingModal .modal-footer {
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    background: #fff;
    z-index: 2;
}
[data-theme="dark"] #convertBookingModal .modal-body::-webkit-scrollbar-thumb {
    background: #3a4a63;
}
[data-theme="dark"] #convertBookingModal .modal-footer {
    background: #16223a;
}
[data-theme="dark"] #convertWalkInNote {
    background: rgba(45, 212, 191, 0.14) !important;
    border-color: rgba(45, 212, 191, 0.3) !important;
    color: #5eead4 !important;
}

/* Process Booking wizard stepper */
.convert-stepper {
    display: flex;
    align-items: center;
}
.convert-stepper__step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    flex: 0 0 auto;
    width: 84px;
}
.convert-stepper__circle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #eef1f6;
    color: #94a3b8;
    border: 2px solid #dde3ee;
    font-size: 0.85rem;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}
.convert-stepper__label {
    font-size: 0.7rem;
    font-weight: 600;
    color: #94a3b8;
    letter-spacing: 0.01em;
    transition: color 0.2s ease;
}
.convert-stepper__track {
    flex: 1 1 auto;
    height: 2px;
    background: #dde3ee;
    margin: 0 4px 18px;
    border-radius: 2px;
    overflow: hidden;
}
.convert-stepper__track-fill {
    display: block;
    height: 100%;
    width: 0%;
    background: #198754;
    transition: width 0.25s ease;
}
.convert-stepper__step.is-active .convert-stepper__circle {
    background: #198754;
    border-color: #198754;
    color: #fff;
    box-shadow: 0 0 0 4px rgba(25, 135, 84, 0.15);
}
.convert-stepper__step.is-active .convert-stepper__label {
    color: #198754;
}
.convert-stepper__step.is-done .convert-stepper__circle {
    background: #d1f3e0;
    border-color: #198754;
    color: #198754;
}
.convert-stepper__step.is-done .convert-stepper__label {
    color: #198754;
}
[data-theme="dark"] .convert-stepper__circle {
    background: #1c2a3f;
    border-color: #2c3d57;
    color: #7d8ba3;
}
[data-theme="dark"] .convert-stepper__label {
    color: #7d8ba3;
}
[data-theme="dark"] .convert-stepper__track {
    background: #2c3d57;
}
[data-theme="dark"] .convert-stepper__step.is-active .convert-stepper__circle {
    box-shadow: 0 0 0 4px rgba(45, 212, 145, 0.18);
}
[data-theme="dark"] .convert-stepper__step.is-done .convert-stepper__circle {
    background: rgba(25, 135, 84, 0.25);
}
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

/* Dark mode — the booking calendar, legend chip, and day tints above are hardcoded
   light colors with no theme-aware variant, so they stay glaringly bright against
   the dark sidebar/page otherwise. */
[data-theme="dark"] .secretary-booking-calendar {
    border-color: #2f3d52;
    background: #16223a;
}
[data-theme="dark"] .secretary-booking-calendar .selected-date-chip {
    background: rgba(45, 212, 191, 0.16);
    color: #5eead4;
}
[data-theme="dark"] .secretary-booking-calendar .calendar-legend {
    color: #94a3b8;
}
[data-theme="dark"] #bookCalendar .fc-toolbar-title {
    color: #e2e8f0;
}
[data-theme="dark"] #bookCalendar .fc-daygrid-day-number {
    color: #cbd5e1;
}
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-open .fc-daygrid-day-frame {
    background: rgba(22, 163, 74, 0.18);
}
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-busy .fc-daygrid-day-frame {
    background: rgba(245, 158, 11, 0.18);
}
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-closed .fc-daygrid-day-frame,
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-disabled .fc-daygrid-day-frame {
    background: rgba(220, 38, 38, 0.18);
}
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-selected {
    box-shadow: inset 0 0 0 2px #2dd4bf;
}
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-open:hover,
[data-theme="dark"] #bookCalendar .fc-daygrid-day.book-day-busy:hover {
    box-shadow: inset 0 0 0 2px rgba(45, 212, 191, 0.45);
}
[data-theme="dark"] #bookCalendar .book-day-badge.state-open {
    color: #4ade80;
}
[data-theme="dark"] #bookCalendar .book-day-badge.state-busy {
    color: #fbbf24;
}
[data-theme="dark"] #bookCalendar .book-day-badge.state-closed {
    color: #f87171;
}
[data-theme="dark"] #bookCalendar .fc-scrollgrid,
[data-theme="dark"] #bookCalendar table {
    border-color: #2f3d52 !important;
}
[data-theme="dark"] #bookCalendar .fc-col-header-cell {
    background: #1c2c47;
    color: #94a3b8;
}
[data-theme="dark"] #bookCalendar td,
[data-theme="dark"] #bookCalendar th {
    border-color: #2f3d52 !important;
}

.appointments-actions-cell {
    width: auto;
    min-width: 160px;
}

.appointments-actions-grid {
    display: flex;
    flex-flow: row nowrap;
    gap: 0.25rem;
    align-items: center;
    justify-content: flex-start;
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
                                    <?php $dob = $p['date_of_birth'] ? date('M j, Y', strtotime($p['date_of_birth'])) : ''; ?>
                                    <option value="<?php echo htmlspecialchars($p['patient_id']); ?>"><?php echo htmlspecialchars($fullname . ($dob ? ' | ' . $dob : '')); ?></option>
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
                        <?php foreach ($doctors as $d): ?>
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
            <div class="modal-body" id="profilingContent">
                <div class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    Loading medical history...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Appointment Details Modal -->
<div class="modal fade" id="appointmentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar2-check"></i> Appointment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="dpp-header">
                    <div class="dpp-avatar" id="apptDetailsAvatar">?</div>
                    <div>
                        <p class="dpp-name" id="apptDetailsPatientName"></p>
                        <div class="dpp-chips">
                            <span class="dpp-chip"><i class="bi bi-hash"></i> <span id="apptDetailsId"></span></span>
                            <span class="dpp-chip"><i class="bi bi-calendar-event"></i> <span id="apptDetailsDate"></span></span>
                            <span class="dpp-chip" id="apptDetailsTypeChip"></span>
                        </div>
                    </div>
                </div>

                <div id="apptDetailsStatusBox"></div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-clipboard2-pulse"></i> Appointment Info</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Doctor</div>
                        <div class="dpp-field-value" id="apptDetailsDoctor"></div>
                    </div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Chief Complaint</div>
                        <div class="dpp-field-value" id="apptDetailsReason"></div>
                    </div>
                    <div class="dpp-field" id="apptDetailsFollowupRow" style="display:none;">
                        <div class="dpp-field-label">Follow-up</div>
                        <div class="dpp-field-value" id="apptDetailsFollowup"></div>
                    </div>
                </div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-person-lines-fill"></i> Contact Info</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Email</div>
                        <div class="dpp-field-value" id="apptDetailsEmail"></div>
                    </div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Phone</div>
                        <div class="dpp-field-value" id="apptDetailsPhone"></div>
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

    function openAppointmentDetailsModal(appt) {
        const statusLabels = {
            scheduled: 'Pending',
            confirmed: 'Confirmed',
            arrived: 'Arrived',
            checked_in: 'Checked-In',
            completed: 'Completed',
            cancelled: 'Cancelled',
            in_progress: 'In Progress',
            awaiting_secretary_records: 'Awaiting Records',
            records_complete: 'Records Complete'
        };
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value && String(value).trim() !== '' ? value : '—';
        };

        const patientName = appt.patient_name || 'Unknown';
        document.getElementById('apptDetailsPatientName').textContent = patientName;
        const initials = patientName.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '?';
        document.getElementById('apptDetailsAvatar').textContent = initials;
        setText('apptDetailsId', appt.appointment_id ? ('#' + appt.appointment_id) : '');
        setText('apptDetailsDoctor', appt.doctor_name);
        setText('apptDetailsDate', appt.appointment_date);
        setText('apptDetailsEmail', appt.email);
        setText('apptDetailsPhone', appt.phone_number);
        setText('apptDetailsReason', appt.reason);

        const typeChip = document.getElementById('apptDetailsTypeChip');
        typeChip.innerHTML = appt.is_online_appointment
            ? '<i class="bi bi-laptop"></i> Online'
            : '<i class="bi bi-building"></i> Walk-In';

        const statusBox = document.getElementById('apptDetailsStatusBox');
        const statusLabel = statusLabels[appt.status] || appt.status;
        if (appt.status === 'cancelled') {
            statusBox.innerHTML = '<div class="dpp-alert-box"><div class="dpp-alert-title"><i class="bi bi-x-circle-fill"></i> Appointment Cancelled</div>'
                + (appt.cancellation_reason ? '<span class="dpp-alert-tag">' + escapeHtml(appt.cancellation_reason) + '</span>' : '<span class="text-muted small">No reason provided.</span>') + '</div>';
        } else if (['completed', 'records_complete'].includes(appt.status)) {
            statusBox.innerHTML = '<div class="dpp-safe-box"><i class="bi bi-check-circle-fill"></i> ' + escapeHtml(statusLabel) + '</div>';
        } else if (['confirmed', 'arrived', 'checked_in', 'in_progress'].includes(appt.status)) {
            statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;"><i class="bi bi-info-circle-fill"></i> ' + escapeHtml(statusLabel) + '</div>';
        } else {
            statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#fffbeb;border-color:#fde68a;color:#92400e;"><i class="bi bi-hourglass-split"></i> ' + escapeHtml(statusLabel) + '</div>';
        }

        const followupRow = document.getElementById('apptDetailsFollowupRow');
        if (appt.is_followup && appt.schedule_status) {
            let followupText = appt.schedule_status;
            if (appt.schedule_start) {
                followupText += ' — ' + appt.schedule_start + (appt.schedule_end ? ' – ' + appt.schedule_end : '');
            }
            setText('apptDetailsFollowup', followupText);
            followupRow.style.display = '';
        } else if (appt.record_follow_up_date) {
            setText('apptDetailsFollowup', appt.record_follow_up_date);
            followupRow.style.display = '';
        } else {
            followupRow.style.display = 'none';
        }
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

            // Reset to a clean state each time the modal is opened — don't carry over
            // a doctor/services selection from a previous booking or a cancelled one.
            const bookModal = document.getElementById('bookAppointmentModal');
            if (bookModal) {
                bookModal.addEventListener('show.bs.modal', function () {
                    const doctorSelect = document.getElementById('bookDoctorSelect');
                    if (doctorSelect) doctorSelect.value = '';

                    secretaryServiceManager = null;
                    window.secretarySelectedServiceDuration = 0;

                    const timeslotSelect = document.getElementById('bookTimeslotSelect');
                    const timeslotWrapper = document.getElementById('bookTimeslotWrapper');
                    if (timeslotSelect) {
                        timeslotSelect.innerHTML = '<option value="">-- Select a timeslot --</option>';
                        timeslotSelect.disabled = true;
                    }
                    if (timeslotWrapper) timeslotWrapper.style.display = 'none';
                });

                bookModal.addEventListener('shown.bs.modal', function () {
                    renderBookCalendar();
                    loadAvailableDates(BOOK_DAYS_AHEAD);
                    // Services aren't doctor-specific — load them right away so the
                    // secretary can start picking services before choosing a doctor.
                    initializeSecretaryServiceManager();
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
                    // Services are independent of doctor — keep whatever the secretary
                    // already selected instead of wiping it out on doctor change.
                    if (!secretaryServiceManager) {
                        initializeSecretaryServiceManager();
                    }
                });
            }

            // Initialize Secretary Service Manager
            let secretaryServiceManager = null;
            // Global duration tracking for timeslot filtering
            window.secretarySelectedServiceDuration = 0;
            
            async function initializeSecretaryServiceManager() {
                console.log('[Secretary Appointments] initializeSecretaryServiceManager called');
                const serviceSelector = document.getElementById('secretaryServiceSelector');

                console.log('[Secretary Appointments] ServiceSelector exists:', !!serviceSelector);

                if (!serviceSelector) {
                    console.log('[Secretary Appointments] Early return - missing serviceSelector');
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

                // Selected services live on the service manager instance (dropdown UI,
                // not plain checkboxes) — read from there instead of querying the DOM.
                const selectedServiceIds = secretaryServiceManager
                    ? secretaryServiceManager.selectedServices.map(s => parseInt(s.service_id))
                    : [];

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
                <div class="mb-2" id="rescheduleDoctorWrapSec" style="display:none;">
                    <label class="form-label">Doctor</label>
                    <select id="rescheduleDoctorSelectSec" class="form-select">
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo (int)$doc['user_id']; ?>"><?php echo htmlspecialchars(trim($doc['first_name'] . ' ' . $doc['last_name'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Change the doctor here to reassign this appointment.</small>
                </div>
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
    document.getElementById('rescheduleDoctorWrapSec').style.display = 'none';
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
    const doctorWrap = document.getElementById('rescheduleDoctorWrapSec');
    const doctorSelect = document.getElementById('rescheduleDoctorSelectSec');
    if (doctorWrap) doctorWrap.style.display = '';
    if (doctorSelect) doctorSelect.value = String(appointment.user_id || '');
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

document.getElementById('rescheduleDoctorSelectSec')?.addEventListener('change', function() {
    document.getElementById('rescheduleDoctorIdSec').value = this.value;
    loadRescheduleTimeslotsSec();
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

// View patient profiling — same rich card renderer the doctor portal uses
async function openViewProfilingModal(patientId, firstName, lastName) {
    const contentDiv = document.getElementById('profilingContent');
    const fallbackName = `${firstName} ${lastName}`;

    contentDiv.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading medical history...</div>';

    try {
        const res = await fetch(`../assets/api/patients/get_profiling.php?patient_id=${encodeURIComponent(patientId)}`);
        const j = await res.json();

        if (!j.success) {
            contentDiv.innerHTML = '<div class="alert alert-warning">No medical history available for this patient.</div>';
            return;
        }

        if (typeof renderDoctorPatientProfile === 'function') {
            contentDiv.innerHTML = renderDoctorPatientProfile(j.patient || {}, j.profiling || {}, fallbackName);
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
