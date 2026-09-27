<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';

function appointment_can_transition_doctor(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }

    $matrix = [
        'scheduled' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['checked_in', 'cancelled', 'no_show'],
        'arrived' => ['in_progress', 'cancelled'],
        'checked_in' => ['in_progress', 'no_show'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    return in_array($to, $matrix[$from] ?? [], true);
}

function doctor_appointment_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['completed', 'records_complete', 'arrived'], true)) {
        return 'success';
    }
    if ($status === 'awaiting_secretary_records') {
        return 'info';
    }
    if (in_array($status, ['cancelled', 'no_show'], true)) {
        return 'danger';
    }
    if ($status === 'in_progress') {
        return 'primary';
    }
    return 'warning text-dark';
}

function doctor_appointment_status_label(string $status): string
{
    $status = strtolower(trim($status));
    $labels = [
        'awaiting_secretary_records' => 'Awaiting secretary record',
        'in_progress' => 'In session',
        'arrived' => 'Arrived',
        'checked_in' => 'Checked in',
        'confirmed' => 'Confirmed',
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'records_complete' => 'Record complete',
        'cancelled' => 'Cancelled',
        'no_show' => 'No show',
    ];
    if (isset($labels[$status])) {
        return $labels[$status];
    }
    return ucfirst(str_replace('_', ' ', $status));
}

function dr_cal_format_time_label(?string $start, ?string $end): string
{
    if (!$start) return 'All day';
    $fmt = function (string $t): string {
        $h = (int) date('g', strtotime($t));
        $m = date('i', strtotime($t));
        $ap = strtolower(date('A', strtotime($t)));
        return $m === '00' ? $h . $ap : $h . ':' . $m . $ap;
    };
    $label = $fmt($start);
    if ($end) $label .= ' - ' . $fmt($end);
    return $label;
}

// Get logged-in doctor ID
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id <= 0) {
    echo '<div class="alert alert-danger">You must be logged in to view this page.</div>';
    exit;
}

$action = $_GET['action'] ?? '';
$today = date('Y-m-d');

// Define a flag to prevent re-processing header logic
if (!defined('APPOINTMENTS_HEADER_PROCESSED')) {
    define('APPOINTMENTS_HEADER_PROCESSED', true);
    
    // Handle ACCEPT patient (start visit) - set arrival status in_progress and appointment status
    if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!security_validate_csrf_token($csrf)) {
            $_SESSION['message'] = 'Invalid security token. Please refresh and try again.';
            $_SESSION['message_type'] = 'danger';
            header('Location: doctor.php?page=appointments');
            exit;
        }

        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        if ($appointmentId <= 0) {
            $_SESSION['message'] = 'Invalid appointment.';
            $_SESSION['message_type'] = 'warning';
            header('Location: doctor.php?page=appointments');
            exit;
        }

        $conn->begin_transaction();
        try {
            $stStmt = $conn->prepare('SELECT status FROM appointments WHERE appointment_id = ? AND user_id = ? LIMIT 1');
            if (!$stStmt) {
                throw new Exception('Unable to validate appointment status.');
            }
            $stStmt->bind_param('ii', $appointmentId, $doctor_id);
            $stStmt->execute();
            $stRow = $stStmt->get_result()->fetch_assoc();
            $stStmt->close();

            if (!$stRow) {
                throw new Exception('Appointment not found or access denied.');
            }

            $currentStatus = strtolower((string)($stRow['status'] ?? ''));
            $apptStatus = 'in_progress';
            if (!appointment_can_transition_doctor($currentStatus, $apptStatus)) {
                throw new Exception('Cannot start visit from status: ' . $currentStatus);
            }

            $ust = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ? AND user_id = ?');
            if ($ust) {
                $ust->bind_param('sii', $apptStatus, $appointmentId, $doctor_id);
                $ust->execute();
                $ust->close();
            }

            $conn->commit();
            if (function_exists('log_system_event')) {
                log_system_event(
                    $conn,
                    'appointment.start_visit',
                    'appointments',
                    $appointmentId,
                    'Doctor started visit (status=in_progress).',
                    (int)$doctor_id
                );
            }
            $_SESSION['message'] = 'Patient accepted. Visit started.';
            $_SESSION['message_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['message'] = 'Error accepting patient: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }

        header('Location: doctor.php?page=appointments');
        exit;
    }
} // End of header processing conditional

// Fetch all appointments for this doctor (for reference)
$allAppointments = [];
$all_sql = "
    SELECT
        a.appointment_id,
        a.patient_id,
        a.appointment_date,
        a.reason,
        a.notes,
        a.status,
        a.cancellation_reason,
        a.is_online_appointment,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        NULL as appointment_time,
        (SELECT GROUP_CONCAT(s.service_name SEPARATOR '||') FROM selected_services ss INNER JOIN services s ON s.service_id = ss.service_id WHERE ss.appointment_id = a.appointment_id) AS services_rendered
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    -- timeslot removed; appointment_time not available
    WHERE a.user_id = ?
    ORDER BY a.appointment_date DESC, a.created_at DESC
    LIMIT 20
";

$stmt = $conn->prepare($all_sql);
if ($stmt) {
    $stmt->bind_param('i', $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $allAppointments[] = $row;
    }
    $stmt->close();
}

$appointmentsTab = trim((string)($_GET['tab'] ?? 'recent'));
if (!in_array($appointmentsTab, ['recent', 'general', 'followup'], true)) {
    $appointmentsTab = 'recent';
}

$appointmentEntries = [];
$generalSql = "
    SELECT
        a.appointment_id,
        a.patient_id,
        p.first_name,
        p.last_name,
        COALESCE(t.slot_date, a.appointment_date) AS event_date,
        t.start_time,
        t.end_time,
        a.status,
        a.reason,
        (SELECT COUNT(*) FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id) AS has_history
    FROM appointments a
    INNER JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
    WHERE a.user_id = ?
            AND a.status <> 'cancelled'
    ORDER BY COALESCE(t.slot_date, a.appointment_date) DESC, t.start_time DESC, a.created_at DESC
    LIMIT 500
";

$generalStmt = $conn->prepare($generalSql);
if ($generalStmt) {
    $generalStmt->bind_param('i', $doctor_id);
    $generalStmt->execute();
    $generalRes = $generalStmt->get_result();
    while ($row = $generalRes->fetch_assoc()) {
        $row['entry_type'] = 'appointment';
        $appointmentEntries[] = $row;
    }
    $generalStmt->close();
}

$followupSql = "
    SELECT
        s.schedule_id,
        s.appointment_id,
        a.patient_id,
        p.first_name,
        p.last_name,
        DATE(s.scheduled_start) AS event_date,
        TIME(s.scheduled_start) AS start_time,
        TIME(s.scheduled_end) AS end_time,
        s.status,
        '' AS reason,
        (SELECT COUNT(*) FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id) AS has_history
    FROM patient_schedules s
    INNER JOIN appointments a ON a.appointment_id = s.appointment_id
    INNER JOIN patients p ON p.patient_id = a.patient_id
    WHERE s.user_id = ?
      AND s.status IN ('scheduled', 'rescheduled', 'arrived', 'completed')
      AND s.scheduled_start IS NOT NULL
      AND a.rescheduled_from IS NOT NULL
    ORDER BY s.scheduled_start DESC
    LIMIT 500
";

$followupEntries = [];
$followupStmt = $conn->prepare($followupSql);
if ($followupStmt) {
    $followupStmt->bind_param('i', $doctor_id);
    $followupStmt->execute();
    $followupRes = $followupStmt->get_result();
    while ($row = $followupRes->fetch_assoc()) {
        $row['entry_type'] = 'followup';
        $followupEntries[] = $row;
    }
    $followupStmt->close();
}

$generalAppointments = array_merge($appointmentEntries, $followupEntries);

usort($generalAppointments, function ($a, $b) {
    $aDate = (string)($a['event_date'] ?? '');
    $bDate = (string)($b['event_date'] ?? '');
    $aTime = (string)($a['start_time'] ?? '');
    $bTime = (string)($b['start_time'] ?? '');
    $aKey = $aDate . ' ' . $aTime;
    $bKey = $bDate . ' ' . $bTime;
    return strcmp($bKey, $aKey);
});

$calendarCountsByDate = [];
$calendarHoverByDate = [];

foreach ($generalAppointments as $entry) {
    $eventDate = (string)($entry['event_date'] ?? '');
    if ($eventDate === '') {
        continue;
    }

    $firstName = trim((string)($entry['first_name'] ?? ''));
    $lastName = trim((string)($entry['last_name'] ?? ''));
    $patientName = trim($firstName . ' ' . $lastName);
    if ($patientName === '') {
        $patientName = 'Patient';
    }

    $entryType = (string)($entry['entry_type'] ?? 'appointment');
    $isFollowup = ($entryType === 'followup');
    $label = $isFollowup ? 'Follow-up' : 'Appointment';

    $startTime = (string)($entry['start_time'] ?? '');
    $endTime = (string)($entry['end_time'] ?? '');
    $timeLabel = dr_cal_format_time_label($startTime ?: null, $endTime ?: null);

    if (!isset($calendarCountsByDate[$eventDate])) {
        $calendarCountsByDate[$eventDate] = [
            'appointments' => 0,
            'followups' => 0,
        ];
    }
    if ($isFollowup) {
        $calendarCountsByDate[$eventDate]['followups']++;
    } else {
        $calendarCountsByDate[$eventDate]['appointments']++;
    }

    if (!isset($calendarHoverByDate[$eventDate])) {
        $calendarHoverByDate[$eventDate] = [];
    }
    $calendarHoverByDate[$eventDate][] = $label . ': ' . $patientName . ' (' . $timeLabel . ')';
}

// Fetch services for the dropdown
$services = [];
$stmt = $conn->query('SELECT service_id, service_name, initial_price FROM services WHERE is_active = 1 ORDER BY service_name');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $services[] = $row;
    }
}

// Fetch tooth data
$teeth = [];
$stmt = $conn->query('SELECT tooth_id, tooth_number, upper_lower FROM tooth ORDER BY tooth_number');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $teeth[] = $row;
    }
}
?>

<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <span class="module-page-header__crumb">Appointments</span>
        <?php if ($appointmentsTab === 'general'): ?>
            <span class="module-page-header__sep">&gt;</span> Calendar &amp; List
        <?php elseif ($appointmentsTab === 'followup'): ?>
            <span class="module-page-header__sep">&gt;</span> Follow-up
        <?php else: ?>
            <span class="module-page-header__sep">&gt;</span> Recent
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

<nav class="module-section-nav" aria-label="Appointment sections">
    <div class="module-section-nav__track">
        <div class="btn-group" role="group">
            <a class="btn btn-sm <?php echo $appointmentsTab === 'recent' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="doctor.php?page=appointments&tab=recent" title="Recent appointments">
                <i class="bi bi-clock-history me-1"></i> Recent
            </a>
            <a class="btn btn-sm <?php echo $appointmentsTab === 'general' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="doctor.php?page=appointments&tab=general" title="Appointments calendar and list">
                <i class="bi bi-calendar3 me-1"></i> Calendar & List
            </a>
            <a class="btn btn-sm <?php echo $appointmentsTab === 'followup' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="doctor.php?page=appointments&tab=followup" title="Follow-up appointments">
                <i class="bi bi-calendar-range me-1"></i> Follow-up
            </a>
        </div>
    </div>
</nav>

<?php if ($appointmentsTab === 'recent'): ?>
    <div class="row">
        <div class="col-lg-12">
            <div class="card border-0">
                <div class="card-header">
                    <h2 class="card-title mb-0">
                        <i class="bi bi-calendar-check"></i> Recent Appointments
                    </h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle doctor-recent-appointments-table" id="doctorRecentAppointmentsTable" data-search-placeholder="Search patients, dates, or status...">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allAppointments as $appt): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($appt['appointment_date']); ?></td>
                                        <td>
                                            <?php
                                            $apptStatus = (string)($appt['status'] ?? 'scheduled');
                                            $badgeClass = doctor_appointment_status_badge($apptStatus);
                                            ?>
                                            <span class="badge bg-<?php echo htmlspecialchars($badgeClass); ?>">
                                                <?php echo htmlspecialchars(doctor_appointment_status_label($apptStatus)); ?>
                                            </span>
                                        </td>
                                        <td class="record-col-cell">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openDoctorAppointmentDetailsModal(<?php echo htmlspecialchars(json_encode([
                                                "appointment_id"      => (int)$appt["appointment_id"],
                                                "patient_name"        => trim((string)$appt["first_name"] . " " . (string)$appt["last_name"]),
                                                "email"                => (string)($appt["email"] ?? ""),
                                                "phone_number"         => (string)($appt["phone_number"] ?? ""),
                                                "reason"               => (string)($appt["reason"] ?? ""),
                                                "notes"                => (string)($appt["notes"] ?? ""),
                                                "cancellation_reason"  => (string)($appt["cancellation_reason"] ?? ""),
                                                "appointment_date"     => (string)($appt["appointment_date"] ?? ""),
                                                "appointment_time"     => (string)($appt["appointment_time"] ?? ""),
                                                "is_online_appointment" => (bool)($appt["is_online_appointment"] ?? false),
                                                "status"               => $apptStatus,
                                                "services_rendered"    => !empty($appt["services_rendered"]) ? explode('||', (string)$appt["services_rendered"]) : [],
                                            ]), ENT_QUOTES, 'UTF-8'); ?>)' title="View appointment details">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($appointmentsTab === 'general'): ?>
    <div class="card border-0 mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title mb-0"><i class="bi bi-calendar3"></i> Appointments Calendar</h2>
        </div>
        <div class="card-body">
            <div id="doctorAppointmentsCalendar"></div>
        </div>
    </div>

    <div class="card border-0">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="card-title mb-0"><i class="bi bi-list-check"></i> Appointments and Follow-up List</h2>
            <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
                <span id="appointmentsFilterBadge" class="small fw-semibold text-muted" style="display:none;"></span>
                <button id="clearAppointmentsDateFilter" type="button" class="btn btn-sm btn-outline-secondary" style="display:none;">Show All</button>
                <div class="unified-table-search-wrap module-search-inline">
                    <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                    <input type="search" id="doctorGeneralSearchInput" class="form-control unified-table-search-input" placeholder="Search patients, dates, or status..." aria-label="Search appointments">
                    <button type="button" class="unified-table-search-clear" id="doctorGeneralSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle doctor-general-appointments-table" id="doctorGeneralAppointmentsTable" data-skip-table-search="1" data-search-placeholder="Search patients, dates, or status...">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointmentEntries)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No appointments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointmentEntries as $appt): ?>
                                <?php
                                    $eventDate = (string)($appt['event_date'] ?? '');
                                    $startTime = (string)($appt['start_time'] ?? '');
                                    $endTime = (string)($appt['end_time'] ?? '');
                                    $timeLabel = dr_cal_format_time_label($startTime ?: null, $endTime ?: null);
                                    $apptStatus = (string)($appt['status'] ?? 'scheduled');
                                ?>
                                <tr data-event-date="<?php echo htmlspecialchars($eventDate); ?>">
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                            Appointment
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(trim((string)$appt['first_name'] . ' ' . (string)$appt['last_name'])); ?></td>
                                    <td><?php echo htmlspecialchars($eventDate); ?></td>
                                    <td><?php echo htmlspecialchars($timeLabel); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars(doctor_appointment_status_badge($apptStatus)); ?>">
                                            <?php echo htmlspecialchars(doctor_appointment_status_label($apptStatus)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="doctorGeneralNoMatchRow" style="display:none;"><td colspan="5" class="text-center text-muted">No records for selected date.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title mb-0"><i class="bi bi-calendar-range"></i> Follow-up Appointments</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle doctor-general-appointments-table" id="doctorFollowupAppointmentsTable" data-search-placeholder="Search follow-up patients or dates...">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Record</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($followupEntries)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No follow-up appointments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($followupEntries as $appt): ?>
                                <?php
                                    $eventDate = (string)($appt['event_date'] ?? '');
                                    $startTime = (string)($appt['start_time'] ?? '');
                                    $endTime = (string)($appt['end_time'] ?? '');
                                    $timeLabel = 'All day';
                                    if ($startTime !== '') {
                                        $timeLabel = substr($startTime, 0, 5);
                                        if ($endTime !== '') {
                                            $timeLabel .= ' - ' . substr($endTime, 0, 5);
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(trim((string)$appt['first_name'] . ' ' . (string)$appt['last_name'])); ?></td>
                                    <td><?php echo htmlspecialchars($eventDate); ?></td>
                                    <td><?php echo htmlspecialchars($timeLabel); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($appt['status'] === 'completed' ? 'success' : 'warning text-dark'); ?>">
                                            <?php echo htmlspecialchars(ucfirst((string)$appt['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="record-col-cell">
                                        <?php if (!empty($appt['has_history']) && !empty($appt['appointment_id'])): ?>
                                            <button class="btn btn-sm doctor-record-btn doctor-record-btn-clickable" onclick="openHistoryModal(<?php echo (int)$appt['appointment_id']; ?>)">
                                                <i class="bi bi-file-earmark-text"></i> View
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm doctor-record-btn doctor-record-btn-disabled" type="button" disabled aria-disabled="true" title="No clinical record available">
                                                <i class="bi bi-file-earmark-text"></i> View
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
<?php endif; ?>

            <!-- History Modal (read-only) -->
            <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Appointment History</h5>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="histPrintBtn">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                        </div>
                        <div class="modal-body" style="max-height:75vh; overflow:auto;">
                            <div id="historyContent">
                                <div id="histWorkflowBanner" class="alert alert-info py-2 small mb-3" style="display:none;"></div>

                                <!-- Patient Header: Avatar + Name + Details -->
                                <div class="d-flex align-items-center gap-3 mb-3 p-2 hist-patient-header">
                                    <div id="histPatientAvatar" style="flex-shrink:0;"></div>
                                    <div class="min-w-0">
                                        <div id="histPatientName" style="font-size:1rem;font-weight:700;color:#1e293b;"></div>
                                        <div id="histPatientDetails" style="font-size:0.82rem;color:#64748b;"></div>
                                    </div>
                                </div>
                                <!-- Row 1: Appointment Date + Follow-up -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <div class="hist-field"><span class="hist-label">Appointment Date</span><span id="histApptDate" class="hist-value"></span></div>
                                    </div>
                                    <div class="col-md-6" id="histFollowUpWrap">
                                        <div class="hist-field"><span class="hist-label">Follow-up Date</span><span id="histFollowUpDate" class="hist-value badge bg-info text-dark"></span></div>
                                    </div>
                                </div>
                                <!-- Row 2: Background -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-12" id="histBackgroundWrap">
                                        <div class="hist-section">
                                            <span class="hist-section-title">Patient Background (Profiling)</span>
                                            <div id="histBackgroundContent" class="small"></div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Row 3: Notes + Summary -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <div class="hist-section">
                                            <span class="hist-section-title">Clinical Notes</span>
                                            <pre id="histNotes" class="hist-pre"></pre>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="hist-section">
                                            <span class="hist-section-title">Clinical Summary</span>
                                            <pre id="histSummary" class="hist-pre"></pre>
                                        </div>
                                    </div>
                                </div>
                                <!-- Row 4: Prescriptions (full width) -->
                                <div class="mb-2">
                                    <div class="hist-section">
                                        <span class="hist-section-title">Prescriptions</span>
                                        <div id="histPrescriptions" class="table-responsive">
                                            <table class="table table-sm table-bordered mb-0">
                                                <thead class="table-light">
                                                    <tr><th>Medicine</th><th>Brand</th><th>Dosage</th><th>Duration</th><th>Notes</th></tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <!-- Row 5: Services Availed -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-12">
                                        <div class="hist-section">
                                            <span class="hist-section-title">Services Availed</span>
                                            <ul id="histServices" class="mb-0 small"></ul>
                                        </div>
                                    </div>
                                </div>
                                <!-- Row 6: Images (full width) -->
                                <div class="mb-2">
                                    <div class="hist-section">
                                        <span class="hist-section-title">Images</span>
                                        <div id="histImages" class="row g-2"></div>
                                    </div>
                                </div>
                                <!-- Row 7: Odontogram (full width) -->
                                <div class="mb-2">
                                    <div class="hist-section">
                                        <div class="d-flex align-items-center justify-content-between mb-2 gap-2">
                                            <span class="hist-section-title mb-0">Odontogram (read-only)</span>
                                            <button type="button" id="histOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark">Full Screen</button>
                                        </div>
                                        <div id="histOdontogramViewport" class="odontogram-viewport">
                                            <button type="button" id="histOdontogramFullscreenCloseBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                                                <i class="bi bi-x-lg"></i> Close
                                            </button>
                                            <div id="histOdontogramWrap" style="border:1px solid #ddd; height:<?php echo (int) odontogram_embed_height_px(); ?>px;">
                                                <iframe id="histOdontoFrame" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;height:100%;border:0;"></iframe>
                                            </div>
                                            <?php odontogram_render_rotate_prompt(); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

<!-- Appointment Details Modal (recent appointments "View" button) — ported from the
     secretary's "Booking Details" modal (same dpp-header/chip/section layout). Since
     this is a confirmed appointment rather than a pending booking, the reference chip
     shows the appointment number instead of a booking tracking code. -->
<div class="modal fade" id="doctorAppointmentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar2-check"></i> Appointment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="dpp-header">
                    <div class="dpp-avatar" id="docApptDetailsAvatar">?</div>
                    <div>
                        <p class="dpp-name" id="docApptDetailsPatientName"></p>
                        <div class="dpp-chips">
                            <span class="dpp-chip"><i class="bi bi-hash"></i> <span id="docApptDetailsRef"></span></span>
                            <span class="dpp-chip"><i class="bi bi-calendar-event"></i> <span id="docApptDetailsDate"></span></span>
                            <span class="dpp-chip" id="docApptDetailsTimeChip" style="display:none;"><i class="bi bi-clock"></i> <span id="docApptDetailsTime"></span></span>
                            <span class="dpp-chip" id="docApptDetailsTypeChip"></span>
                        </div>
                    </div>
                </div>

                <div id="docApptDetailsStatusBox"></div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-clipboard2-pulse"></i> Visit Details</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Reason for Visit</div>
                        <div class="dpp-field-value" id="docApptDetailsReason"></div>
                    </div>
                    <div class="dpp-field" id="docApptDetailsNotesWrap">
                        <div class="dpp-field-label">Notes</div>
                        <div class="dpp-field-value" id="docApptDetailsNotes"></div>
                    </div>
                </div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-person-lines-fill"></i> Contact Info</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Email</div>
                        <div class="dpp-field-value" id="docApptDetailsEmail"></div>
                    </div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Phone</div>
                        <div class="dpp-field-value" id="docApptDetailsPhone"></div>
                    </div>
                </div>

                <div class="dpp-section" id="docApptDetailsServicesSection" style="display:none;">
                    <div class="dpp-section-title"><i class="bi bi-clipboard2-check"></i> Services</div>
                    <div id="docApptDetailsServicesList"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
/* Compact history modal */
.hist-field {
    background: #f8fafc;
    border-radius: 6px;
    padding: 6px 10px;
    margin-bottom: 4px;
}
.hist-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 1px;
}
.hist-value {
    display: block;
    font-size: 0.85rem;
    color: #1e293b;
    font-weight: 500;
}
.hist-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 10px;
    height: 100%;
}
.hist-section-title {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.hist-pre {
    white-space: pre-wrap;
    background: #fff;
    border: 1px solid #e2e8f0;
    padding: 6px 8px;
    border-radius: 4px;
    font-size: 0.82rem;
    margin-bottom: 0;
    max-height: 120px;
    overflow-y: auto;
}
.doctor-recent-appointments-table thead th {
    white-space: nowrap;
}

.doctor-appointments-tabs .nav-link {
    font-weight: 600;
}

.doctor-general-appointments-table thead th {
    white-space: nowrap;
}

.doctor-general-appointments-table tbody td {
    vertical-align: middle;
}

.doctor-recent-appointments-table tbody td {
    vertical-align: middle;
}

.doctor-recent-appointments-table tbody tr {
    transition: background-color 0.16s ease;
}

.doctor-recent-appointments-table tbody tr:hover {
    background-color: #f3f4f6;
}

.doctor-recent-appointments-table .record-col-cell {
    min-width: 140px;
}

.doctor-record-btn {
    min-width: 92px;
    border-radius: 9px;
    font-weight: 600;
    border-width: 1px;
    transition: background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.doctor-record-btn i {
    margin-right: 4px;
}

.doctor-record-btn-clickable {
    border-color: #0d9488;
    color: #ffffff;
    background: #0d9488;
}

.doctor-record-btn-clickable:hover,
.doctor-record-btn-clickable:focus {
    border-color: #0f766e;
    background: #0f766e;
    color: #ffffff;
    box-shadow: 0 0 0 0.12rem rgba(13, 148, 136, 0.22);
}

.doctor-record-btn-disabled,
.doctor-record-btn-disabled:hover,
.doctor-record-btn-disabled:focus {
    border-color: #b8bfc9;
    color: #7b8491;
    background-color: #f5f6f8;
    cursor: not-allowed;
    box-shadow: none;
}

.tooth-selector {
    min-width: 120px;
}

.tooth-selector select {
    font-size: 0.85rem;
}

.odontogram-viewport {
    position: relative;
}

.odontogram-fs-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 30;
}

.hist-patient-header {
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}</style>

<script>
// Service map for resolving service IDs to names
window.serviceMap = {
    <?php foreach ($services as $s): ?>
        "<?php echo $s['service_id']; ?>": "<?php echo htmlspecialchars(addslashes($s['service_name'])); ?>",
    <?php endforeach; ?>
};

window.CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
window.CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
window.doctorCalendarCountsByDate = <?php echo json_encode($calendarCountsByDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.doctorCalendarHoverByDate = <?php echo json_encode($calendarHoverByDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.generalDateFilter = '';
window.generalMonthFilter = '';
window.generalSearchTerm = '';

function applyGeneralTableFilters(shouldScroll) {
    const table = document.getElementById('doctorGeneralAppointmentsTable');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr[data-event-date]');
    const noMatchRow = document.getElementById('doctorGeneralNoMatchRow');
    let visibleCount = 0;

    rows.forEach(function (row) {
        const rowDate = row.getAttribute('data-event-date') || '';
        const rowMonth = rowDate.length >= 7 ? rowDate.slice(0, 7) : '';
        const rowText = (row.textContent || '').toLowerCase();
        const matchMonth = !generalMonthFilter || rowMonth === generalMonthFilter;
        const matchDate = !generalDateFilter || rowDate === generalDateFilter;
        const matchSearch = !generalSearchTerm || rowText.indexOf(generalSearchTerm) !== -1;
        const match = matchMonth && matchDate && matchSearch;
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    if (noMatchRow) {
        noMatchRow.style.display = (rows.length > 0 && visibleCount === 0) ? '' : 'none';
    }

    const badge = document.getElementById('appointmentsFilterBadge');
    const clearBtn = document.getElementById('clearAppointmentsDateFilter');
    if (badge && clearBtn) {
        if (generalDateFilter) {
            badge.style.display = 'inline-block';
            badge.textContent = 'Filtered by ' + generalDateFilter + ' (' + visibleCount + ')';
            clearBtn.style.display = 'inline-block';
            if (shouldScroll) {
                table.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } else if (generalMonthFilter) {
            badge.style.display = 'inline-block';
            badge.textContent = 'Month: ' + generalMonthFilter + ' (' + visibleCount + ')';
            clearBtn.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
            badge.textContent = '';
            clearBtn.style.display = 'none';
        }
    }
}

function filterGeneralAppointmentsByDate(dateStr, shouldScroll) {
    generalDateFilter = (dateStr || '').trim();
    applyGeneralTableFilters(shouldScroll);
}

function initDoctorAppointmentsCalendar() {
    const calendarEl = document.getElementById('doctorAppointmentsCalendar');
    if (!calendarEl || typeof DentalCalendar === 'undefined') {
        return;
    }

    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

    const calendar = DentalCalendar.create(calendarEl, {
        getDay: function (dateKey) {
            const counts = doctorCalendarCountsByDate[dateKey] || { appointments: 0, followups: 0 };
            const appointmentCount = Number(counts.appointments || 0);
            const followupCount = Number(counts.followups || 0);
            const lines = Array.isArray(doctorCalendarHoverByDate[dateKey]) ? doctorCalendarHoverByDate[dateKey] : [];
            const tags = [];
            if (appointmentCount > 0) tags.push({ text: plural(appointmentCount, 'appointment'), tone: 'info' });
            if (followupCount > 0) tags.push({ text: plural(followupCount, 'follow-up'), tone: 'ok' });
            const capped = lines.slice(0, 10);
            const more = lines.length - capped.length;
            return {
                state: 'available',
                selectable: true,
                tags: tags,
                title: capped.length ? capped.join(', ') + (more > 0 ? ' +' + more + ' more' : '') : (tags.length ? '' : 'No appointments')
            };
        },
        // Clicking a day filters the appointments table to that date.
        onSelect: function (dateKey) {
            filterGeneralAppointmentsByDate(dateKey, true);
        },
        onMonthChange: function (ym) {
            calendar.selected = null;
            generalMonthFilter = ym;
            generalDateFilter = '';
            applyGeneralTableFilters(false);
        },
        legend: [
            { tone: 'info', label: 'Appointments' },
            { tone: 'ok', label: 'Follow-ups' },
            { tone: 'selected', label: 'Filtering table by this day' }
        ]
    });

    generalMonthFilter = calendar.month;
    generalDateFilter = '';
    applyGeneralTableFilters(false);

    const clearBtn = document.getElementById('clearAppointmentsDateFilter');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            generalDateFilter = '';
            generalMonthFilter = '';
            calendar.setSelected(null);
            filterGeneralAppointmentsByDate('');
        });
    }
}

function initDoctorGeneralTableSearch() {
    const searchInput = document.getElementById('doctorGeneralSearchInput');
    const clearBtn = document.getElementById('doctorGeneralSearchClear');
    if (!searchInput) return;

    const applySearch = () => {
        generalSearchTerm = String(searchInput.value || '').trim().toLowerCase();
        if (clearBtn) {
            clearBtn.style.display = generalSearchTerm ? '' : 'none';
        }
        applyGeneralTableFilters(false);
    };

    searchInput.addEventListener('input', applySearch);
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            searchInput.value = '';
            applySearch();
        }
    });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            applySearch();
            searchInput.focus();
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    initDoctorAppointmentsCalendar();
    initDoctorGeneralTableSearch();
});

// Mirrors odontogram/chart-constants.js CHART_LEGEND — the print window can't load the
// odontogram iframe (it's a live editor app, not a static image, and has no tooth data
// of its own once opened in a fresh window), so we render the findings as a plain table
// from the same JSON the iframe would have been fed via postMessage.
var ODONTOGRAM_LEGEND_HIST = {
    C: 'Dental Caries', C1: 'Caries — Vital Pulp Expose', C2: 'Caries — Non-vital Pulp Expose',
    X: 'Indicated for Extraction', RF: 'Retained Root Fragment', Am: 'Amalgam Filling',
    S: 'Silicate Filling', GC: 'Gold Crown', Ab1: 'Bridge Abutment', P: 'Pontic',
    GCI: 'Gold Clasp', GI: 'Gold Inlays', M: 'Missing (Extraction)', Un: 'Unerupted'
};
var ODONTOGRAM_SURFACE_LABELS_HIST = { O: 'Occlusal', M: 'Mesial', D: 'Distal', B: 'Buccal', L: 'Lingual', root: 'Root', whole: 'Whole Tooth' };

function formatOdontogramFindingsHtmlHist(odontogramData) {
    if (!odontogramData || typeof odontogramData !== 'object') return '';
    var teeth = Object.keys(odontogramData).sort();
    var rows = [];
    teeth.forEach(function (fdi) {
        var entry = odontogramData[fdi];
        if (!entry || typeof entry !== 'object') return;
        var parts = [];
        if (entry.surfaces && typeof entry.surfaces === 'object') {
            Object.keys(entry.surfaces).forEach(function (surface) {
                var labels = entry.surfaces[surface];
                if (!Array.isArray(labels) || !labels.length) return;
                var names = labels.map(function (code) { return ODONTOGRAM_LEGEND_HIST[code] || code; }).join(', ');
                parts.push((ODONTOGRAM_SURFACE_LABELS_HIST[surface] || surface) + ': ' + names);
            });
        }
        var comment = String(entry.comment || '').trim();
        if (!parts.length && !comment) return;
        rows.push('<tr><td>#' + escapeHtml(fdi) + '</td><td>' + escapeHtml(parts.join('; ')) + '</td><td>' + escapeHtml(comment) + '</td></tr>');
    });
    if (!rows.length) return '<div class="text-muted small">No findings recorded on the dental chart.</div>';
    return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tooth</th><th>Findings</th><th>Notes</th></tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
}

function populateServicesAvailedList(listEl, services) {
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!Array.isArray(services) || !services.length) {
        const li = document.createElement('li');
        li.className = 'text-muted';
        li.textContent = 'No services recorded for this appointment.';
        listEl.appendChild(li);
        return;
    }
    services.forEach(function(item) {
        const li = document.createElement('li');
        let label = '';
        if (item && typeof item === 'object') {
            label = String(item.description || item.service_name || '').trim();
            if (!label && item.service_id && (item.service_id in serviceMap)) {
                label = serviceMap[item.service_id];
            }
            if (!label && item.service_id) {
                label = 'Service';
            }
            const amount = item.amount !== undefined && item.amount !== null ? Number(item.amount) : null;
            if (amount !== null && !Number.isNaN(amount) && amount > 0) {
                label += ' (PHP ' + amount.toFixed(2) + ')';
            }
        } else {
            const sid = parseInt(item, 10);
            label = (sid in serviceMap) ? serviceMap[sid] : 'Service';
        }
        li.textContent = label;
        listEl.appendChild(li);
    });
}

function postHistoryOdontogram(odontogramData) {
    const odf = document.getElementById('histOdontoFrame');
    if (!odf) return;
    const payload = { type: 'load_odontogram', toothState: odontogramData || {}, readonly: true };
    const send = function() {
        try {
            if (odf.contentWindow) {
                odf.contentWindow.postMessage(payload, '*');
            }
        } catch (e) {}
    };
    if (odf.dataset.historyLoaded === '1') {
        send();
        return;
    }
    odf.onload = function() {
        odf.dataset.historyLoaded = '1';
        send();
    };
    if (odf.contentDocument && odf.contentDocument.readyState === 'complete') {
        odf.dataset.historyLoaded = '1';
        send();
    }
}

function isHistoryOdontogramFullscreen() {
    const viewport = document.getElementById('histOdontogramViewport');
    return !!viewport && window.OdontogramFullscreen.isActive(viewport);
}

function requestHistoryOdontogramFullscreen() {
    const viewport = document.getElementById('histOdontogramViewport');
    if (!viewport) return;
    window.OdontogramFullscreen.request(viewport);
}

function exitHistoryOdontogramFullscreen() {
    const viewport = document.getElementById('histOdontogramViewport');
    window.OdontogramFullscreen.exit(viewport);
}

function syncHistoryOdontogramFullscreenUI() {
    const closeBtn = document.getElementById('histOdontogramFullscreenCloseBtn');
    const openBtn = document.getElementById('histOdontogramFullscreenBtn');
    const active = isHistoryOdontogramFullscreen();
    if (closeBtn) closeBtn.style.display = active ? '' : 'none';
    if (openBtn) openBtn.textContent = active ? 'Exit Full Screen' : 'Full Screen';
}

document.getElementById('histOdontogramFullscreenBtn')?.addEventListener('click', function() {
    if (isHistoryOdontogramFullscreen()) {
        exitHistoryOdontogramFullscreen();
        return;
    }
    requestHistoryOdontogramFullscreen();
});
document.getElementById('histOdontogramFullscreenCloseBtn')?.addEventListener('click', exitHistoryOdontogramFullscreen);
document.addEventListener('fullscreenchange', syncHistoryOdontogramFullscreenUI);
document.addEventListener('webkitfullscreenchange', syncHistoryOdontogramFullscreenUI);
document.addEventListener('odontogram:pseudofullscreenchange', function (e) {
    if (e.target.id === 'histOdontogramViewport') syncHistoryOdontogramFullscreenUI();
});
document.getElementById('historyModal')?.addEventListener('hidden.bs.modal', exitHistoryOdontogramFullscreen);
syncHistoryOdontogramFullscreenUI();

// If page requested to show history on load, trigger modal
<?php if (!empty($_GET['show_history']) && !empty($_GET['appointment_id'])): ?>
document.addEventListener('DOMContentLoaded', function(){
    try { openHistoryModal(<?php echo (int)$_GET['appointment_id']; ?>); } catch(e){}
});
<?php endif; ?>

// Open history modal and fetch clinical record (read-only)
function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Ported from the secretary's openBookingDetailsModal() — same chip/section layout and
// status-box logic, adapted for confirmed appointments: the reference chip shows the
// appointment number (appointments don't have a tracking code) and the vocabulary is
// swapped from booking statuses to appointment statuses.
function openDoctorAppointmentDetailsModal(appt) {
    const statusLabels = {
        scheduled: 'Pending',
        confirmed: 'Confirmed',
        arrived: 'Arrived',
        checked_in: 'Checked-In',
        completed: 'Completed',
        cancelled: 'Cancelled',
        in_progress: 'In Progress',
        no_show: 'No Show',
        awaiting_secretary_records: 'Awaiting Records',
        records_complete: 'Records Complete'
    };
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value && String(value).trim() !== '' ? value : '—';
    };

    const patientName = appt.patient_name || 'Unknown';
    document.getElementById('docApptDetailsPatientName').textContent = patientName;
    const initials = patientName.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '?';
    document.getElementById('docApptDetailsAvatar').textContent = initials;

    setText('docApptDetailsRef', appt.appointment_id ? ('#' + appt.appointment_id) : '');

    let formattedDate = appt.appointment_date;
    if (formattedDate) {
        const d = new Date(String(formattedDate).replace(' ', 'T'));
        if (!Number.isNaN(d.getTime())) {
            formattedDate = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        }
    }
    setText('docApptDetailsDate', formattedDate);

    const timeChip = document.getElementById('docApptDetailsTimeChip');
    const time = (appt.appointment_time || '').trim();
    if (timeChip) timeChip.style.display = time ? '' : 'none';
    setText('docApptDetailsTime', time);

    const typeChip = document.getElementById('docApptDetailsTypeChip');
    if (typeChip) {
        typeChip.innerHTML = appt.is_online_appointment
            ? '<i class="bi bi-laptop"></i> Online'
            : '<i class="bi bi-building"></i> Walk-In';
    }

    setText('docApptDetailsReason', appt.reason);
    const notesWrap = document.getElementById('docApptDetailsNotesWrap');
    const notes = (appt.notes || '').trim();
    if (notesWrap) notesWrap.classList.toggle('d-none', !notes);
    setText('docApptDetailsNotes', notes);

    setText('docApptDetailsEmail', appt.email);
    setText('docApptDetailsPhone', appt.phone_number);

    const statusBox = document.getElementById('docApptDetailsStatusBox');
    const status = String(appt.status || '').toLowerCase();
    const statusLabel = statusLabels[status] || (status || 'Pending');
    if (status === 'cancelled') {
        statusBox.innerHTML = '<div class="dpp-alert-box"><div class="dpp-alert-title"><i class="bi bi-x-circle-fill"></i> Appointment Cancelled</div>'
            + (appt.cancellation_reason ? '<span class="dpp-alert-tag">' + escapeHtml(appt.cancellation_reason) + '</span>' : '<span class="text-muted small">No reason provided.</span>') + '</div>';
    } else if (status === 'no_show') {
        statusBox.innerHTML = '<div class="dpp-alert-box"><div class="dpp-alert-title"><i class="bi bi-exclamation-triangle-fill"></i> Marked as No-Show</div></div>';
    } else if (['completed', 'records_complete'].includes(status)) {
        statusBox.innerHTML = '<div class="dpp-safe-box"><i class="bi bi-check-circle-fill"></i> ' + escapeHtml(statusLabel) + '</div>';
    } else if (['confirmed', 'arrived', 'checked_in', 'in_progress'].includes(status)) {
        statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;"><i class="bi bi-info-circle-fill"></i> ' + escapeHtml(statusLabel) + '</div>';
    } else {
        statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#fffbeb;border-color:#fde68a;color:#92400e;"><i class="bi bi-hourglass-split"></i> ' + escapeHtml(statusLabel) + '.</div>';
    }

    const servicesSection = document.getElementById('docApptDetailsServicesSection');
    const servicesList = document.getElementById('docApptDetailsServicesList');
    const services = Array.isArray(appt.services_rendered) ? appt.services_rendered.filter(Boolean) : [];
    if (services.length > 0) {
        servicesList.innerHTML = services.map(function (s) {
            return '<span class="dpp-chip">' + escapeHtml(s) + '</span>';
        }).join(' ');
        servicesSection.style.display = '';
    } else {
        servicesList.innerHTML = '';
        servicesSection.style.display = 'none';
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('doctorAppointmentDetailsModal')).show();
}

window.currentHistoryRecordData = null;

async function openHistoryModal(appointmentId) {
    const modalEl = document.getElementById('historyModal');
    const bs = new bootstrap.Modal(modalEl);
    // clear
    document.getElementById('histPatientName').textContent = '';
    document.getElementById('histApptDate').textContent = '';
    document.getElementById('histNotes').textContent = '';
    document.getElementById('histServices').innerHTML = '';
    currentHistoryRecordData = null;
    const histPresBodyEl = document.querySelector('#histPrescriptions tbody');
    if (histPresBodyEl && typeof DentalUI !== 'undefined') DentalUI.showSkeleton(histPresBodyEl, 3);
    const workflowBannerReset = document.getElementById('histWorkflowBanner');
    if (workflowBannerReset) {
        workflowBannerReset.style.display = 'none';
        workflowBannerReset.textContent = '';
    }
    exitHistoryOdontogramFullscreen();

        try {
            const res = await fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?appointment_id=' + encodeURIComponent(appointmentId));
            const j = await res.json();
            if (!j.success) {
                notifyDoctor('Could not load history: ' + (j.message || ''), 'danger');
                return;
            }
            const rec = j.record;
            const appointmentMeta = j.appointment || {};
            const isDoctorSessionOnly = !!j.is_doctor_session_only;
            const isFinalizedRecord = !!j.is_finalized_record;
        if (!rec) {
            notifyDoctor('No clinical record found for this appointment.', 'warning');
            return;
        }

        const workflowBanner = document.getElementById('histWorkflowBanner');
        if (workflowBanner) {
            let bannerText = '';
            const apptStatus = String(appointmentMeta.status || '').toLowerCase();
            if (apptStatus === 'awaiting_secretary_records' || isDoctorSessionOnly) {
                bannerText = 'Doctor session completed. This visit is waiting for the secretary to create the official record and follow-up date.';
            } else if (isFinalizedRecord || apptStatus === 'completed') {
                bannerText = 'Official record filed. This history is read-only.';
            } else {
                bannerText = 'Read-only appointment history.';
            }
            workflowBanner.textContent = bannerText;
            workflowBanner.className = 'alert py-2 small mb-3 ' + ((apptStatus === 'awaiting_secretary_records' || isDoctorSessionOnly) ? 'alert-warning' : 'alert-info');
            workflowBanner.style.display = '';
        }

        // show patient header with avatar
        const patientInfo = j.patient_info || {};
        const patientName = patientInfo.name || rec._patient_name || 'Unknown patient';
        document.getElementById('histPatientName').textContent = patientName;

        // Render avatar
        const avatarEl = document.getElementById('histPatientAvatar');
        if (avatarEl) {
            const imgPath = String(patientInfo.profile_image || '').trim();
            if (imgPath !== '') {
                const imgUrl = /^https?:\/\//i.test(imgPath) ? imgPath : '../' + imgPath.replace(/^\/+/, '');
                avatarEl.innerHTML = '<img src="' + escapeHtml(imgUrl) + '" alt="" style="width:46px;height:46px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;">';
            } else {
                avatarEl.innerHTML = '<div style="width:46px;height:46px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;"><i class="bi bi-person-fill" style="font-size:1.2rem;color:#94a3b8;"></i></div>';
            }
        }

        // Render details line: email | phone | address
        const detailsEl = document.getElementById('histPatientDetails');
        if (detailsEl) {
            const parts = [patientInfo.email, patientInfo.phone, patientInfo.address].filter(Boolean);
            detailsEl.textContent = parts.join(' | ') || '';
        }

        document.getElementById('histApptDate').textContent = rec._appointment_date || rec.submitted_at || '';
        document.getElementById('histNotes').textContent = rec.doctor_notes || '';

        const backgroundEl = document.getElementById('histBackgroundContent');
        const backgroundData = rec.patient_profiling || null;

        if (backgroundEl && typeof renderBackgroundProfilingFields === 'function') {
            backgroundEl.innerHTML = renderBackgroundProfilingFields(backgroundData, patientInfo.gender);
        }

        // populate clinical summary
        document.getElementById('histSummary').textContent = isDoctorSessionOnly
            ? 'Secretary has not filed the official summary yet.'
            : (rec.summary || 'No summary provided.');

        // populate prescriptions
        const presBody = document.querySelector('#histPrescriptions tbody');
        presBody.innerHTML = '';
        if (Array.isArray(j.prescriptions) && j.prescriptions.length > 0) {
            j.prescriptions.forEach(p => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtml(p.medicine_name || '')}</td>
                    <td>${escapeHtml(p.medicine_brand || '')}</td>
                    <td>${escapeHtml(p.dosage || '')}</td>
                    <td>${escapeHtml(p.duration || '')}</td>
                    <td>${escapeHtml(p.notes || '')}</td>
                `;
                presBody.appendChild(tr);
            });
        } else {
            presBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No prescriptions</td></tr>';
        }

        // populate follow-up date (secretary sets this when creating the record) —
        // hide the whole field instead of showing a placeholder when no date was set
        const histFollowUpWrap = document.getElementById('histFollowUpWrap');
        if (!isDoctorSessionOnly && rec.follow_up_date) {
            document.getElementById('histFollowUpDate').textContent = rec.follow_up_date;
            histFollowUpWrap.style.display = '';
        } else {
            histFollowUpWrap.style.display = 'none';
        }

        // services availed for this appointment
        populateServicesAvailedList(document.getElementById('histServices'), j.services_availed || []);

        // images
        const imgWrap = document.getElementById('histImages');
        if (typeof renderMediaGallery === 'function') {
            renderMediaGallery(imgWrap, (rec.images || []).map(function (src, idx) {
                return { file_path: src, file_name: 'appointment-photo-' + (idx + 1) + '.jpg' };
            }), function (item) {
                return typeof resolveMediaPath === 'function'
                    ? resolveMediaPath(item.file_path, '../')
                    : item.file_path;
            });
        } else if (imgWrap) {
            imgWrap.innerHTML = '';
            if (Array.isArray(rec.images) && rec.images.length) {
                rec.images.forEach(src => {
                    const a = document.createElement('a');
                    a.href = src;
                    a.target = '_blank';
                    const img = document.createElement('img');
                    img.src = src;
                    img.style.maxWidth = '150px';
                    img.style.maxHeight = '120px';
                    img.className = 'img-thumbnail';
                    a.appendChild(img);
                    imgWrap.appendChild(a);
                });
            }
        }

        // odontogram: send to iframe via postMessage if present (read-only)
        postHistoryOdontogram(rec.odontogram || null);

        currentHistoryRecordData = rec;

        bs.show();
    } catch (e) {
        console.error(e);
        notifyDoctor('Error loading history', 'danger');
    }
}

// Print the currently open history record. The odontogram is a live editor app running in
// an iframe — a fresh print window can't load its tooth-state data into a brand new iframe,
// so we print the findings as a plain table instead (same approach used on the patient
// portal's clinic records print).
document.getElementById('histPrintBtn')?.addEventListener('click', function () {
    if (!currentHistoryRecordData) {
        notifyDoctor('Load a record before printing.', 'warning');
        return;
    }
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) return;

    const contentEl = document.getElementById('historyContent');
    const printBody = document.createElement('div');
    printBody.innerHTML = contentEl.innerHTML;

    const odontoWrapEl = printBody.querySelector('#histOdontogramWrap');
    if (odontoWrapEl) {
        odontoWrapEl.outerHTML = formatOdontogramFindingsHtmlHist(currentHistoryRecordData.odontogram || null);
    }
    printBody.querySelector('#histOdontogramFullscreenBtn')?.remove();
    printBody.querySelector('#histOdontogramFullscreenCloseBtn')?.remove();

    const title = 'Clinical Record — ' + (document.getElementById('histPatientName').textContent || 'Patient');

    printWindow.document.write(
        '<!doctype html><html><head><title>' + escapeHtml(title) + '</title>' +
        '<style>' +
        'body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#212529;}' +
        'h1{font-size:18px;margin:0 0 4px;}' +
        '.hist-patient-header,.hist-field,.hist-section{border:1px solid #dee2e6;border-radius:8px;padding:10px;margin-bottom:12px;background:#fff;}' +
        '.hist-section-title,.hist-label{display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:4px;}' +
        '.hist-value{font-size:14px;} .hist-pre{white-space:pre-wrap;font-size:13px;margin:0;}' +
        'table{width:100%;border-collapse:collapse;} th,td{border:1px solid #dee2e6;padding:6px;text-align:left;font-size:13px;}' +
        'ul{margin:0;padding-left:18px;}' +
        '.row,.g-2{display:block;} .col-md-6,.col-md-4,.col-md-12{display:block;width:100%;}' +
        '.btn,button{display:none !important;}' +
        'img{max-width:150px;max-height:120px;}' +
        '#histWorkflowBanner{display:none;}' +
        '</style></head><body>' +
        '<h1>' + escapeHtml(title) + '</h1>' +
        printBody.innerHTML +
        '</body></html>'
    );
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function () { printWindow.print(); }, 300);
});
</script>
<!-- Reschedule Modal -->
<link rel="stylesheet" href="../assets/vendor/flatpickr/flatpickr.min.css">
<script src="../assets/vendor/flatpickr/flatpickr.min.js"></script>
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rescheduleScheduleId" />
                <div class="mb-2"><label class="form-label">Start</label><input id="rescheduleStart" class="form-control" type="datetime-local"/></div>
                <div class="mb-2"><label class="form-label">End (optional)</label><input id="rescheduleEnd" class="form-control" type="datetime-local"/></div>
            </div>
            <div class="modal-footer">
                <button id="rescheduleSaveBtn" type="button" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>
<script>
window.doctorCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
function notifyDoctor(message, type) {
    window.showToast(String(message || ''), type || 'info');
}
// Created on first use: this script runs while the page is still parsing, before the
// footer loads Bootstrap, so constructing the modal here threw and stopped everything
// below it (including the Save handler) from ever running.
function getRescheduleModal() {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById('rescheduleModal'));
}
window.fpStart = null; window.fpEnd = null;
function initReschedulePickers() {
    try {
        if (typeof flatpickr !== 'undefined') {
            fpStart = flatpickr('#rescheduleStart', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
            fpEnd = flatpickr('#rescheduleEnd', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
        }
    } catch (e) { console.warn('flatpickr init', e); }
}
initReschedulePickers();
function toLocalInput(dtStr) {
    if (!dtStr) return '';
    // dtStr expected 'YYYY-MM-DD HH:MM:SS' or 'YYYY-MM-DD HH:MM'
    const s = dtStr.replace(' ', 'T').replace(/:00$/,'');
    return s;
}
function toServerFormat(localVal) {
    if (!localVal) return '';
    // localVal like '2026-02-25T09:30'
    return localVal.replace('T',' ');
}

async function openRescheduleModal(schedule) {
    document.getElementById('rescheduleScheduleId').value = schedule.schedule_id || '';
    document.getElementById('rescheduleStart').value = toLocalInput(schedule.scheduled_start || '');
    document.getElementById('rescheduleEnd').value = toLocalInput(schedule.scheduled_end || '');
    getRescheduleModal().show();
}

document.getElementById('rescheduleSaveBtn').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleId').value;
    const start = toServerFormat(document.getElementById('rescheduleStart').value);
    const end = toServerFormat(document.getElementById('rescheduleEnd').value);
    if (!sid || !start) return notifyDoctor('Start datetime required', 'warning');
    this.disabled = true;
    try {
        const fd = new FormData();
        fd.append('schedule_id', sid);
        fd.append('scheduled_start', start);
        fd.append('scheduled_end', end);
        fd.append('csrf_token', doctorCsrfToken);
        const res = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.success) return notifyDoctor(j.message || 'Failed to reschedule', 'danger');
        notifyDoctor('Rescheduled', 'success');
        getRescheduleModal().hide();
        // reload page or update UI: simple approach reload
        location.reload();
    } catch (e) { console.error(e); notifyDoctor('Error', 'danger'); }
    this.disabled = false;
});
</script>

<!-- Finish Appointment Modal with Photo Upload & Notes -->
<div class="modal fade" id="finishAppointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Finish Appointment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Photo Gallery Section -->
                <div class="mb-4">
                    <h6 class="mb-3">
                        <i class="fas fa-images"></i> Appointment Photos
                        <span class="badge bg-info ms-2" id="photoCountBadge">0</span>
                    </h6>
                    <div id="photoGalleryContainer" class="row g-2">
                        <p class="text-muted small">No photos uploaded yet</p>
                    </div>
                <!-- Upload Photo Section -->
                <div class="mb-4 p-3 bg-light rounded">
                    <h6 class="mb-3">
                        <i class="fas fa-plus-circle"></i> Add Photos
                    </h6>
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="file" id="finishPhotoUpload" class="form-control" accept="image/*" multiple hidden>
                            <button type="button" class="btn btn-outline-primary w-100" onclick="document.getElementById('finishPhotoUpload').click()">
                                <i class="fas fa-upload"></i> Upload Photos
                            </button>
                        </div>
                        <div class="col-6">
                            <button type="button" class="btn btn-outline-info w-100" id="cameraButton">
                                <i class="fas fa-camera"></i> Take Photo
                            </button>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">Support: JPG, PNG. Max 5MB each</small>
                </div>

                <!-- Doctor Notes Section -->
                <div class="mb-4">
                    <h6 class="mb-2">
                        <i class="fas fa-sticky-note"></i> Clinical Notes
                    </h6>
                    <textarea id="finishAppointmentNotes" class="form-control" rows="4" placeholder="Enter clinical notes, findings, recommendations..." style="resize: vertical;"></textarea>
                    <small class="text-muted d-block mt-1">These notes will be visible to the secretary when creating records</small>
                </div>

                <!-- Additional Charges Section -->
                <div class="mb-4 p-3 bg-light rounded">
                    <h6 class="mb-3">
                        <i class="fas fa-dollar-sign"></i> Additional Charges (Optional)
                    </h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="number" id="finishAdditionalCharge" class="form-control" placeholder="Amount (e.g., 500)">
                        </div>
                        <div class="col-md-8">
                            <input type="text" id="finishChargeReason" class="form-control" placeholder="Reason (e.g., Overtime fee)">
                        </div>
                    </div>
                <!-- Services Section -->
                <div class="mb-4 p-3 bg-light rounded">
                    <h6 class="mb-3">
                        <i class="fas fa-list-check"></i> Services Performed
                    </h6>
                    <div class="row g-2 mb-2">
                        <div class="col-12">
                            <select id="finishServiceSelect" class="form-select" onchange="addFinishService()">
                                <option value="">-- Select Service --</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr><th>Service</th><th style="width:90px;">Price</th><th style="width:40px;"></th></tr>
                            </thead>
                            <tbody id="finishServicesBody">
                                <tr><td colspan="3" class="text-center text-muted small">No services added yet</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted d-block mt-1">Add services performed during this session</small>
                </div>

                <!-- Submit Section -->
                <div class="alert alert-info small" role="alert">
                    <i class="fas fa-info-circle"></i> Once submitted, the appointment will be marked as completed and the secretary will be notified to create records.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="submitFinishAppointmentBtn">
                    <i class="fas fa-check"></i> Finish Appointment
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Hidden video element for camera capture -->
<video id="cameraPreviewVideo" style="display: none; max-width: 100%; max-height: 300px; margin: 10px 0; border: 2px solid #0d9488; border-radius: 5px;"></video>
<canvas id="cameraCaptureCanvas" style="display: none;"></canvas>

<script>
// Finish Appointment Modal Logic
window.finishingAppointmentId = null;
window.uploadedPhotos = [];

function openFinishAppointmentModal(appointmentId) {
    finishingAppointmentId = appointmentId;
    uploadedPhotos = [];
    document.getElementById('photoGalleryContainer').innerHTML = '<p class="text-muted small">No photos uploaded yet</p>';
    document.getElementById('photoCountBadge').textContent = '0';
    document.getElementById('finishAppointmentNotes').value = '';
    document.getElementById('finishPhotoUpload').value = '';
    document.getElementById('finishAdditionalCharge').value = '';
    document.getElementById('finishChargeReason').value = '';
    finishSelectedServices = [];
    renderFinishServicesTable();
    loadFinishServices();
    const modal = new bootstrap.Modal(document.getElementById('finishAppointmentModal'));
    modal.show();
}

// Services management
var finishSelectedServices = [];

function loadFinishServices() {
    var select = document.getElementById('finishServiceSelect');
    if (!select) return;
    select.innerHTML = '<option value="">-- Select Service --</option>';
    fetch('../assets/api/appointments/manage_services.php?action=services&appointment_id=' + finishingAppointmentId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.services) {
                data.services.forEach(function(s) {
                    var opt = document.createElement('option');
                    opt.value = s.service_id;
                    opt.textContent = s.service_name + ' — ₱' + parseFloat(s.initial_price || 0).toLocaleString();
                    opt.dataset.price = s.initial_price || 0;
                    opt.dataset.name = s.service_name;
                    select.appendChild(opt);
                });
            }
        })
        .catch(function(err) { console.error('Failed to load services:', err); });
}

function addFinishService() {
    var select = document.getElementById('finishServiceSelect');
    var serviceId = parseInt(select.value);
    if (!serviceId) return;
    var option = select.options[select.selectedIndex];
    var exists = finishSelectedServices.find(function(s) { return s.service_id === serviceId; });
    if (exists) { notifyDoctor('Service already added.', 'warning'); return; }
    finishSelectedServices.push({
        service_id: serviceId,
        name: option.dataset.name,
        price: parseFloat(option.dataset.price || 0)
    });
    select.value = '';
    renderFinishServicesTable();
}

function removeFinishService(serviceId) {
    finishSelectedServices = finishSelectedServices.filter(function(s) { return s.service_id !== serviceId; });
    renderFinishServicesTable();
}

function renderFinishServicesTable() {
    var tbody = document.getElementById('finishServicesBody');
    if (!tbody) return;
    if (finishSelectedServices.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted small">No services added yet</td></tr>';
        return;
    }
    tbody.innerHTML = '';
    finishSelectedServices.forEach(function(s) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + escapeHtmlFinish(s.name) + '</td><td>₱' + s.price.toLocaleString() + '</td><td><button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="removeFinishService(' + s.service_id + ')"><i class="bi bi-x"></i></button></td>';
        tbody.appendChild(tr);
    });
}

function escapeHtmlFinish(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Resize/compress an image File in-browser before it's ever uploaded.
// This is what actually fixes "invalid response from server" on hosts
// with tight PHP memory limits (e.g. InfinityFree) — the server never
// has to decode a multi-megapixel camera photo into memory.
async function compressImageFile(file, maxDimension = 1600, quality = 0.8) {
    try {
        const bitmap = await createImageBitmap(file).catch(() => null);
        let width, height, drawSource;

        if (bitmap) {
            width = bitmap.width;
            height = bitmap.height;
            drawSource = bitmap;
        } else {
            // Fallback for browsers without createImageBitmap support
            const img = await new Promise((resolve, reject) => {
                const el = new Image();
                el.onload = () => resolve(el);
                el.onerror = reject;
                el.src = URL.createObjectURL(file);
            });
            width = img.naturalWidth;
            height = img.naturalHeight;
            drawSource = img;
        }

        if (!width || !height) {
            return file; // couldn't read dimensions, upload as-is
        }

        if (width > maxDimension || height > maxDimension) {
            const ratio = Math.min(maxDimension / width, maxDimension / height);
            width = Math.round(width * ratio);
            height = Math.round(height * ratio);
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.getContext('2d').drawImage(drawSource, 0, 0, width, height);

        if (bitmap && typeof bitmap.close === 'function') {
            bitmap.close();
        }

        const outType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, outType, quality));
        if (!blob) {
            return file; // compression failed, fall back to original
        }

        // Only use the compressed version if it's actually smaller
        if (blob.size >= file.size) {
            return file;
        }

        const ext = outType === 'image/png' ? 'png' : 'jpg';
        const newName = file.name.replace(/\.[^.]+$/, '') + '.' + ext;
        return new File([blob], newName, { type: outType });
    } catch (err) {
        console.warn('Image compression failed, using original file:', err);
        return file; // never block the upload because compression failed
    }
}

// Photo Upload Handler
document.getElementById('finishPhotoUpload').addEventListener('change', async function(e) {
    const files = Array.from(e.target.files);
    if (!files.length) return;

    for (const file of files) {
        if (!file.type.startsWith('image/')) {
            notifyDoctor('Only image files allowed', 'warning');
            continue;
        }
        if (file.size > 15 * 1024 * 1024) {
            notifyDoctor(`File ${file.name} exceeds 15MB limit`, 'warning');
            continue;
        }
        const compressed = await compressImageFile(file);
        uploadedPhotos.push(compressed);
    }

    updatePhotoGalleryPreview();
    this.value = '';
});

// Camera Capture Handler
document.getElementById('cameraButton').addEventListener('click', async function() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
        const video = document.getElementById('cameraPreviewVideo');
        video.srcObject = stream;
        video.style.display = 'block';
        video.play();
        
        // Add capture button
        const container = document.querySelector('.modal-body');
        let captureBtn = document.getElementById('photoCaptureBtn');
        if (!captureBtn) {
            captureBtn = document.createElement('button');
            captureBtn.id = 'photoCaptureBtn';
            captureBtn.type = 'button';
            captureBtn.className = 'btn btn-sm btn-success mt-2';
            captureBtn.innerHTML = '<i class="fas fa-camera"></i> Capture';
            captureBtn.addEventListener('click', () => capturePhoto(stream));
            video.parentNode.insertBefore(captureBtn, video.nextSibling);
        }
        
        // Add stop button
        let stopBtn = document.getElementById('photoStopBtn');
        if (!stopBtn) {
            stopBtn = document.createElement('button');
            stopBtn.id = 'photoStopBtn';
            stopBtn.type = 'button';
            stopBtn.className = 'btn btn-sm btn-secondary mt-2 ms-2';
            stopBtn.innerHTML = '<i class="fas fa-times"></i> Stop';
            stopBtn.addEventListener('click', () => {
                stream.getTracks().forEach(track => track.stop());
                video.style.display = 'none';
                captureBtn.remove();
                stopBtn.remove();
            });
            captureBtn.parentNode.insertBefore(stopBtn, captureBtn.nextSibling);
        }
        
        this.disabled = true;
    } catch (err) {
        notifyDoctor('Camera access denied or unavailable', 'danger');
        console.error('Camera error:', err);
    }
});

function capturePhoto(stream) {
    const video = document.getElementById('cameraPreviewVideo');
    const canvas = document.getElementById('cameraCaptureCanvas');

    const maxDimension = 1600;
    let width = video.videoWidth;
    let height = video.videoHeight;
    if (width > maxDimension || height > maxDimension) {
        const ratio = Math.min(maxDimension / width, maxDimension / height);
        width = Math.round(width * ratio);
        height = Math.round(height * ratio);
    }
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(video, 0, 0, width, height);

    canvas.toBlob((blob) => {
        const file = new File([blob], `capture_${Date.now()}.jpg`, { type: 'image/jpeg' });
        uploadedPhotos.push(file);
        updatePhotoGalleryPreview();
        notifyDoctor('Photo captured', 'success');
    }, 'image/jpeg', 0.8);
}

function updatePhotoGalleryPreview() {
    const container = document.getElementById('photoGalleryContainer');
    document.getElementById('photoCountBadge').textContent = uploadedPhotos.length;

    if (typeof renderLocalMediaGallery === 'function') {
        renderLocalMediaGallery(container, uploadedPhotos, removeUploadedPhoto);
        return;
    }

    if (uploadedPhotos.length === 0) {
        container.innerHTML = '<p class="text-muted small">No photos uploaded yet</p>';
        return;
    }
    
    container.innerHTML = '';
    uploadedPhotos.forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const col = document.createElement('div');
            col.className = 'col-sm-6 col-md-4 position-relative';
            col.innerHTML = `
                <div style="position: relative; overflow: hidden; border-radius: 8px; background: #f0f0f0; aspect-ratio: 3/2;">
                    <img src="${e.target.result}" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;">
                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onclick="removeUploadedPhoto(${idx})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(col);
        };
        reader.readAsDataURL(file);
    });
}

function removeUploadedPhoto(idx) {
    uploadedPhotos.splice(idx, 1);
    updatePhotoGalleryPreview();
}

// Submit Finish Appointment
window.finishAppointmentSubmitting = false;
window.finishAppointmentIdempotencyKey = null;

document.getElementById('submitFinishAppointmentBtn').addEventListener('click', async function() {
    if (!finishingAppointmentId) return;
    if (finishAppointmentSubmitting) return;
    finishAppointmentSubmitting = true;

    if (!finishAppointmentIdempotencyKey) {
        finishAppointmentIdempotencyKey = (window.Idempotency && Idempotency.newKey('finish')) || ('finish-' + Date.now() + '-' + Math.random().toString(36).slice(2));
    }
    
    this.disabled = true;
    const btnText = this.innerHTML;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    try {
        const fd = new FormData();
        fd.append('appointment_id', finishingAppointmentId);
        fd.append('notes', document.getElementById('finishAppointmentNotes').value);
        fd.append('csrf_token', doctorCsrfToken);
        fd.append('idempotency_key', finishAppointmentIdempotencyKey);
        fd.append('additional_charge', document.getElementById('finishAdditionalCharge').value);
        fd.append('charge_reason', document.getElementById('finishChargeReason').value);

        // Add selected services
        finishSelectedServices.forEach(function(s, i) {
            fd.append('selected_service_ids[' + i + ']', s.service_id);
        });

        // Add photo files
        uploadedPhotos.forEach((file, idx) => {
            fd.append(`photos[]`, file);
        });
        
        const res = await fetch('../assets/api/appointments/finish_appointment.php', {
            method: 'POST',
            body: fd
        });

        let json;
        const ct = (res.headers.get('Content-Type') || res.headers.get('content-type') || '');
        if (ct.indexOf('application/json') !== -1) {
            json = await res.json();
        } else {
            const txt = await res.text();
            console.error('finish_appointment non-JSON response:', txt);
            notifyDoctor('Server error: invalid response from server', 'danger');
            this.disabled = false;
            this.innerHTML = btnText;
            return;
        }
        if (!json.success) {
            notifyDoctor(json.message || 'Failed to finish appointment', 'danger');
            finishAppointmentSubmitting = false;
            this.disabled = false;
            this.innerHTML = btnText;
            return;
        }
        
        notifyDoctor(json.already_processed || json.idempotent_replay ? 'Appointment was already finished.' : 'Appointment finished successfully', 'success');
        bootstrap.Modal.getInstance(document.getElementById('finishAppointmentModal')).hide();
        
        // Reload or update UI
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        console.error(e);
        notifyDoctor('Error saving appointment', 'danger');
        this.disabled = false;
        this.innerHTML = btnText;
    }
});
</script>
