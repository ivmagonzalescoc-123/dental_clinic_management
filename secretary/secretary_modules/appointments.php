<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/capabilities.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include dirname(dirname(__DIR__)) . '/assets/api/appointments/availability_guard.php';
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
$view = $_GET['view'] ?? 'all'; // 'all' | 'today' | 'tomorrow' | 'one_hour' | 'missed' | 'no_show' | 'reschedule'
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
        $reqStmt = $conn->prepare("SELECT r.request_id, r.appointment_id, r.requested_doctor_id, r.requested_date, r.requested_timeslot_id, r.status,
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

    // Get appointment date
    $stmt = $conn->prepare('SELECT appointment_date, status FROM appointments WHERE appointment_id = ?');
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
$doc_stmt = $conn->prepare('SELECT user_id, first_name, last_name, COALESCE(work_type, "regular") AS work_type FROM users WHERE role_id = 2 AND is_active = 1');
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
];
$appointmentsTitle = $appointmentsTitleMap[$view] ?? 'Appointments';
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>


<!-- Tab Navigation (All vs Today) -->
<div class="mb-3">
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'all' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=all'">
                <i class="bi bi-calendar3"></i> All Appointments (<?php echo (int)$totalCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'today' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=today'">
                <i class="bi bi-calendar-check"></i> Today (<?php echo (int)$todayCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'tomorrow' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=tomorrow'">
                <i class="bi bi-calendar2-event"></i> Tomorrow (<?php echo (int)$tomorrowCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'one_hour' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=one_hour'">
                <i class="bi bi-alarm"></i> 1 Hour (<?php echo (int)$oneHourCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'missed' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=missed'">
                <i class="bi bi-exclamation-triangle"></i> Missed (<?php echo (int)$missedCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'no_show' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=no_show'">
                <i class="bi bi-person-x"></i> No-Show (<?php echo (int)$noShowCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'reschedule' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=reschedule'">
                <i class="bi bi-arrow-repeat"></i> Reschedule Requests (<?php echo $pendingReqCount; ?>)
            </button>
        </li>
    </ul>
</div>

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
                                        <form method="POST" action="secretary.php?page=appointments&action=approve_patient_reschedule&view=<?php echo urlencode($view); ?>" onsubmit="return confirm('Approve and apply this reschedule request?');">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <form method="POST" action="secretary.php?page=appointments&action=reject_patient_reschedule&view=<?php echo urlencode($view); ?>" onsubmit="return confirm('Reject this reschedule request?');">
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
<?php else: ?>

<!-- Appointments List -->
<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0"><?php echo htmlspecialchars($appointmentsTitle); ?></h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
            <i class="bi bi-plus-circle"></i> Book New Appointment
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Patient Name</th>
                        <th>Schedule</th>
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
                                    <?php if (!empty($appointment['schedule_status'])): ?>
                                        <div><span class="badge bg-<?php echo ($appointment['schedule_status'] === 'scheduled' ? 'primary' : ($appointment['schedule_status'] === 'completed' ? 'success' : 'secondary')); ?>"><?php echo ($appointment['schedule_status'] === 'scheduled') ? 'followup' : htmlspecialchars(ucfirst($appointment['schedule_status'])); ?></span>
                                        <div class="small text-muted"><?php echo htmlspecialchars($appointment['schedule_start'] ?? ''); ?> <?php echo $appointment['schedule_end'] ? '&ndash; ' . htmlspecialchars($appointment['schedule_end']) : ''; ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">No schedule</span>
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
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
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
                                        <?php endif; ?>

                                        <?php
                                            $canRescheduleFollowup = !empty($appointment['schedule_id']) && in_array((string)($appointment['schedule_status'] ?? ''), ['scheduled', 'rescheduled'], true);
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
<?php endif; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
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
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

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
                                    <?php $doctorType = ((string)($d['work_type'] ?? 'regular') === 'part_time') ? 'Part-time' : 'Regular'; ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name'] . ' (' . $doctorType . ')'); ?></option>
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
            <form method="POST" action="secretary.php?page=appointments&view=all&action=decline" onsubmit="return confirm('Are you sure you want to decline this appointment?');">
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
    const secretaryCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    function setDeclineAppointment(appointmentId) {
        document.getElementById('declineAppointmentId').value = appointmentId;
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        function showSecretaryActionFeedback(message, type = 'info') {
            const holderId = 'secretaryActionFeedbackHolder';
            let holder = document.getElementById(holderId);
            if (!holder) {
                holder = document.createElement('div');
                holder.id = holderId;
                holder.style.position = 'fixed';
                holder.style.top = '84px';
                holder.style.right = '18px';
                holder.style.zIndex = '2000';
                holder.style.minWidth = '260px';
                holder.style.maxWidth = '420px';
                document.body.appendChild(holder);
            }

            const el = document.createElement('div');
            el.className = 'alert alert-' + type + ' alert-dismissible fade show shadow-sm';
            el.role = 'alert';
            el.innerHTML = '<span>' + message + '</span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            holder.appendChild(el);

            window.setTimeout(function() {
                if (el && el.parentNode) {
                    el.classList.remove('show');
                    window.setTimeout(function() {
                        if (el.parentNode) el.parentNode.removeChild(el);
                    }, 180);
                }
            }, 2800);
        }

        if (window.SimpleDataTable) {
            new SimpleDataTable({
                element: document.querySelector('.datatable'),
                searchable: true,
                sortable: true,
                rowsPerPage: 10
            });
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
                if (!confirm('Approve and schedule this appointment?')) return;
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
                    const res  = await fetch(url);
                    const data = await res.json();
                    select.innerHTML = '<option value="">-- Pick a Time Slot --</option>';
                    if (data.success && data.slots.length > 0) {
                        data.slots.forEach(slot => {
                            const opt = document.createElement('option');
                            opt.value = slot.timeslot_id;
                            opt.textContent = slot.label + ' (' + slot.duration_minutes + ' min)';
                            select.appendChild(opt);
                        });
                    } else {
                        select.innerHTML = '<option value="">No available timeslots — generate them first</option>';
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
                    showSecretaryActionFeedback('Please select a 20-minute timeslot before booking.', 'warning');
                    return;
                }

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
                    ad.append('csrf_token', secretaryCsrfToken);

                    let aresp = await fetch('../assets/api/appointments/create_appointment.php', { method: 'POST', body: ad });
                    let ajson = await aresp.json();

                    if (!ajson.success && ajson.code === 'duplicate_day_warning') {
                        const proceed = confirm((ajson.message || 'Patient already has an appointment for this day.') + '\n\nClick OK to override and continue, or Cancel to keep one appointment per day.');
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
let resModalSec = null;
let fpStartSec = null, fpEndSec = null;
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
    if (typeof window.showAppToast === 'function') {
        window.showAppToast(String(message || ''), type || 'info');
        return;
    }
    alert(String(message || ''));
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

document.getElementById('rescheduleSuggestSec').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleIdSec').value;
    const apptId = document.getElementById('rescheduleAppointmentIdSec').value;
    const apptDoctor = document.getElementById('rescheduleDoctorIdSec').value || '';
    const btn = this; btn.disabled = true;
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
    btn.disabled = false;
});

document.getElementById('rescheduleSaveBtnSec').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleIdSec').value;
    const apptId = document.getElementById('rescheduleAppointmentIdSec').value;
    const doctorId = document.getElementById('rescheduleDoctorIdSec').value || CURRENT_USER;
    const start = toServerFormatSec(document.getElementById('rescheduleStartSec').value);
    const end = toServerFormatSec(document.getElementById('rescheduleEndSec').value);
    if (!start) { notifySecretary('Start date required', 'warning'); return; }
    this.disabled = true;
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
    } finally {
        this.disabled = false;
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
        
        if (!j.success || !j.profiling) {
            contentDiv.innerHTML = '<div class="alert alert-warning">No profiling data available for this patient.</div>';
            return;
        }
        
        const pf = j.profiling;
        let html = '<div class="row">';
        
        if (pf.medical_history) {
            html += `<div class="col-md-12 mb-3">
                <strong>Medical History:</strong>
                <div class="alert alert-light p-2">${escapeHtml(pf.medical_history)}</div>
            </div>`;
        }
        
        if (pf.medications) {
            html += `<div class="col-md-6 mb-3">
                <strong>Medications:</strong>
                <div class="alert alert-light p-2">${escapeHtml(pf.medications)}</div>
            </div>`;
        }
        
        if (pf.allergies) {
            html += `<div class="col-md-6 mb-3">
                <strong>Allergies:</strong>
                <div class="alert alert-light p-2">${escapeHtml(pf.allergies)}</div>
            </div>`;
        }
        
        if (pf.smoking) {
            html += `<div class="col-md-6 mb-3">
                <strong>Smoking:</strong>
                <div class="alert alert-light p-2"><span class="badge bg-secondary">${escapeHtml(pf.smoking)}</span></div>
            </div>`;
        }
        
        if (pf.alcohol) {
            html += `<div class="col-md-6 mb-3">
                <strong>Alcohol:</strong>
                <div class="alert alert-light p-2"><span class="badge bg-secondary">${escapeHtml(pf.alcohol)}</span></div>
            </div>`;
        }
        
        if (pf.asa) {
            html += `<div class="col-md-6 mb-3">
                <strong>ASA Class:</strong>
                <div class="alert alert-light p-2"><span class="badge bg-info">${escapeHtml(pf.asa)}</span></div>
            </div>`;
        }
        
        if (pf.last_visit) {
            html += `<div class="col-md-6 mb-3">
                <strong>Last Dental Visit:</strong>
                <div class="alert alert-light p-2">${escapeHtml(pf.last_visit)}</div>
            </div>`;
        }
        
        html += '</div>';
        
        if (html === '<div class="row"></div>') {
            contentDiv.innerHTML = '<div class="alert alert-warning">No profiling fields populated.</div>';
        } else {
            contentDiv.innerHTML = html;
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

<script>
const CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
const CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>
