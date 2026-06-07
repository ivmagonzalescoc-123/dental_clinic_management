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
        'checked_in' => ['in_progress', 'no_show'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    return in_array($to, $matrix[$from] ?? [], true);
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
        a.status,
        p.first_name,
        p.last_name,
        NULL as appointment_time,
        (SELECT COUNT(*) FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id) AS has_history,
        -- latest schedule summary for this appointment (if any)
        (SELECT s.status FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_status,
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_start,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_end
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
    $timeLabel = 'All day';
    if ($startTime !== '') {
        $timeLabel = substr($startTime, 0, 5);
        if ($endTime !== '') {
            $timeLabel .= ' - ' . substr($endTime, 0, 5);
        }
    }

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

// Fetch treatments
$treatments = [];
$stmt = $conn->query('SELECT treatment_id, treatment_name FROM treatments ORDER BY treatment_name');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $treatments[] = $row;
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

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<div class="mb-3">
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
                                    <th>Followup</th>
                                    <th>Record</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allAppointments as $appt): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($appt['appointment_date']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $appt['status'] === 'completed' ? 'success' : ($appt['status'] === 'cancelled' ? 'danger' : 'warning'); ?>">
                                                <?php echo htmlspecialchars(ucfirst($appt['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($appt['schedule_status'])): ?>
                                                <span class="badge bg-<?php echo ($appt['schedule_status'] === 'scheduled' ? 'primary' : ($appt['schedule_status'] === 'completed' ? 'success' : 'secondary')); ?>">
                                                    <?php echo ($appt['schedule_status'] === 'scheduled') ? 'Followup' : htmlspecialchars(ucfirst($appt['schedule_status'])); ?>
                                                </span>
                                                <div class="small text-muted"><?php echo htmlspecialchars($appt['schedule_start'] ?? ''); ?> <?php echo $appt['schedule_end'] ? '&ndash; ' . htmlspecialchars($appt['schedule_end']) : ''; ?></div>
                                            <?php else: ?>
                                                <span class="text-muted small">No schedule</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="record-col-cell">
                                            <?php if (!empty($appt['has_history'])): ?>
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
            <div class="small text-muted">Hover a day to preview names. Click a date to filter the table below.</div>
        </div>
        <div class="card-body">
            <div id="doctorAppointmentsCalendar"></div>
            <div id="doctorCalendarLegend" class="small text-muted mt-2"></div>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointmentEntries)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No appointments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointmentEntries as $appt): ?>
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
                                    $apptStatus = (string)($appt['status'] ?? 'scheduled');
                                    $apptId = (int)($appt['appointment_id'] ?? 0);
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
                                        <span class="badge bg-<?php echo ($apptStatus === 'completed' ? 'success' : ($apptStatus === 'cancelled' ? 'danger' : ($apptStatus === 'in_progress' ? 'info' : 'warning text-dark'))); ?>">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$apptStatus))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($apptStatus === 'in_progress'): ?>
                                            <button type="button" class="btn btn-sm btn-primary" onclick="openFinishAppointmentModal(<?php echo $apptId; ?>)" title="Finish appointment and upload photos/notes">
                                                <i class="fas fa-check"></i> Finish
                                            </button>
                                        <?php elseif ($apptStatus === 'completed' || $apptStatus === 'completed_by_doctor' || $apptStatus === 'awaiting_secretary_records' || $apptStatus === 'records_complete'): ?>
                                            <span class="text-muted small">Done</span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="doctorGeneralNoMatchRow" style="display:none;"><td colspan="6" class="text-center text-muted">No records for selected date.</td></tr>
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
            <div class="small text-muted">All scheduled follow-up entries assigned to you.</div>
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
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height:75vh; overflow:auto;">
                            <div id="historyContent">
                                <div class="mb-3"><strong>Patient:</strong> <span id="histPatientName"></span></div>
                                <div class="mb-3"><strong>Appointment Date:</strong> <span id="histApptDate"></span></div>

                                <div class="mb-3" id="histBackgroundWrap">
                                    <h6>Patient Background (Profiling)</h6>
                                    <div id="histBackgroundContent" class="small"></div>
                                </div>

                                <div class="mb-3" id="histVisitClinicalWrap">
                                    <h6>Visit Clinical Exam (This Appointment)</h6>
                                    <div id="histVisitClinicalContent" class="small"></div>
                                </div>

                                <div class="mb-3"><strong>Clinical Notes:</strong>
                                    <pre id="histNotes" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Clinical Summary:</strong>
                                    <pre id="histSummary" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Prescriptions:</strong>
                                    <div id="histPrescriptions" class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Medicine Name</th>
                                                    <th>Brand</th>
                                                    <th>Dosage</th>
                                                    <th>Duration</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="mb-3"><strong>Follow-up Date:</strong> <span id="histFollowUpDate" class="badge bg-info text-dark"></span></div>
                                <div class="mb-3"><strong>Services Availed:</strong>
                                    <ul id="histServices"></ul>
                                </div>
                                <div class="mb-3" id="histTreatmentWrap" style="display:none;"><strong>Treatment Plan:</strong>
                                    <pre id="histTreatment" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3" id="histMaterialsWrap" style="display:none;"><strong>Materials Used:</strong>
                                    <pre id="histMaterials" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Images:</strong>
                                    <div id="histImages" class="row g-2"></div>
                                </div>

                                <div class="mb-3"><strong>Related Schedules</strong>
                                    <div id="histSchedules" class="list-group small"></div>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2 gap-2">
                                        <label class="form-label mb-0"><strong>Odontogram (read-only)</strong></label>
                                        <div class="d-flex gap-2">
                                            <button type="button" id="histOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark">Full Screen</button>
                                        </div>
                                    </div>
                                    <div id="histOdontogramViewport" class="odontogram-viewport">
                                        <button type="button" id="histOdontogramFullscreenCloseBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                                            <i class="bi bi-x-lg"></i> Close
                                        </button>
                                        <div id="histOdontogramWrap" style="border:1px solid #ddd; height:<?php echo (int) odontogram_embed_height_px(); ?>px;">
                                            <iframe id="histOdontoFrame" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;height:100%;border:0;"></iframe>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<style>
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

#doctorAppointmentsCalendar {
    min-height: 560px;
}

#doctorAppointmentsCalendar .fc-toolbar-title {
    font-size: 1.05rem;
    font-weight: 700;
}

#doctorAppointmentsCalendar .fc-daygrid-day-frame {
    position: relative;
}

#doctorAppointmentsCalendar .fc-daygrid-day-number {
    font-weight: 700;
}

.doctor-day-counts {
    position: absolute;
    right: 4px;
    bottom: 4px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    align-items: flex-end;
    pointer-events: none;
}

.doctor-booking-count {
    border-radius: 999px;
    color: #111827;
    background: #e5e7eb;
    font-size: 0.64rem;
    font-weight: 700;
    min-width: 54px;
    line-height: 1.25;
    text-align: center;
    padding: 2px 6px;
}

.doctor-booking-count.type-appointment {
    background: #dcfce7;
    color: #166534;
}

.doctor-booking-count.type-followup {
    background: #dbeafe;
    color: #1d4ed8;
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
    border-color: #1173c1;
    color: #ffffff;
    background: #1173c1;
}

.doctor-record-btn-clickable:hover,
.doctor-record-btn-clickable:focus {
    border-color: #0a4f88;
    background: #0a4f88;
    color: #ffffff;
    box-shadow: 0 0 0 0.12rem rgba(17, 115, 193, 0.22);
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

</style>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
// Service map for resolving service IDs to names
const serviceMap = {
    <?php foreach ($services as $s): ?>
        "<?php echo $s['service_id']; ?>": "<?php echo htmlspecialchars(addslashes($s['service_name'])); ?>",
    <?php endforeach; ?>
};

// Current session role/user for client-side role checks
const CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
const CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
const doctorCalendarCountsByDate = <?php echo json_encode($calendarCountsByDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const doctorCalendarHoverByDate = <?php echo json_encode($calendarHoverByDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
let generalDateFilter = '';
let generalMonthFilter = '';
let generalSearchTerm = '';

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
    if (!calendarEl || typeof FullCalendar === 'undefined') {
        return;
    }

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        height: 'auto',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth'
        },
        dayCellDidMount: function (info) {
            const y = info.date.getFullYear();
            const m = String(info.date.getMonth() + 1).padStart(2, '0');
            const d = String(info.date.getDate()).padStart(2, '0');
            const dateKey = y + '-' + m + '-' + d;
            const counts = doctorCalendarCountsByDate[dateKey] || { appointments: 0, followups: 0 };
            const appointmentCount = Number(counts.appointments || 0);
            const followupCount = Number(counts.followups || 0);
            const lines = Array.isArray(doctorCalendarHoverByDate[dateKey]) ? doctorCalendarHoverByDate[dateKey] : [];

            if (appointmentCount > 0 || followupCount > 0) {
                const badgesWrap = document.createElement('div');
                badgesWrap.className = 'doctor-day-counts';

                if (appointmentCount > 0) {
                    const appointmentBadge = document.createElement('span');
                    appointmentBadge.className = 'doctor-booking-count type-appointment';
                    appointmentBadge.textContent = 'A: ' + String(appointmentCount);
                    badgesWrap.appendChild(appointmentBadge);
                }

                if (followupCount > 0) {
                    const followupBadge = document.createElement('span');
                    followupBadge.className = 'doctor-booking-count type-followup';
                    followupBadge.textContent = 'F: ' + String(followupCount);
                    badgesWrap.appendChild(followupBadge);
                }

                const frame = info.el.querySelector('.fc-daygrid-day-frame');
                if (frame) {
                    frame.appendChild(badgesWrap);
                }
            }

            if (lines.length > 0) {
                const capped = lines.slice(0, 10);
                const moreCount = lines.length - capped.length;
                const title = [
                    'Appointments: ' + appointmentCount,
                    'Follow-up: ' + followupCount,
                    capped.join('\n'),
                    moreCount > 0 ? ('+' + moreCount + ' more') : ''
                ].filter(Boolean).join('\n');
                info.el.setAttribute('title', title);
            }
        },
        dateClick: function (info) {
            filterGeneralAppointmentsByDate(info.dateStr, true);
        },
        datesSet: function (info) {
            const activeDate = info.view && info.view.currentStart ? info.view.currentStart : info.start;
            const y = activeDate.getFullYear();
            const m = String(activeDate.getMonth() + 1).padStart(2, '0');
            generalMonthFilter = y + '-' + m;
            generalDateFilter = '';
            applyGeneralTableFilters(false);
        }
    });

    calendar.render();

    const legendEl = document.getElementById('doctorCalendarLegend');
    if (legendEl) {
        const totalDays = Object.keys(doctorCalendarCountsByDate).length;
        legendEl.textContent = 'Legend: A = appointments, F = follow-up schedules. Hover a day to see names and time. Days with at least one booking: ' + totalDays + '.';
    }

    const clearBtn = document.getElementById('clearAppointmentsDateFilter');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            generalDateFilter = '';
            generalMonthFilter = '';
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

function tryParseJson(value) {
    if (typeof value !== 'string') return value;
    const trimmed = value.trim();
    if (!trimmed) return '';
    try {
        return JSON.parse(trimmed);
    } catch (e) {
        return value;
    }
}

function formatPrimitive(value) {
    if (value === null || value === undefined) return '';
    return String(value).trim();
}

function formatMaterialsDisplay(materials) {
    const parsed = tryParseJson(materials);

    if (!parsed || (typeof parsed === 'string' && !parsed.trim())) {
        return 'No materials recorded.';
    }

    if (typeof parsed === 'string') {
        return parsed;
    }

    if (Array.isArray(parsed)) {
        if (!parsed.length) return 'No materials recorded.';

        const lines = parsed.map(function(item, index) {
            if (item && typeof item === 'object') {
                const name = formatPrimitive(item.name || item.material || item.item || ('Material ' + (index + 1)));
                const qty = item.qty !== undefined ? item.qty : item.quantity;
                const price = item.price !== undefined ? item.price : item.cost;

                let line = name;
                if (qty !== undefined && qty !== null && String(qty).trim() !== '') {
                    line += ' x' + qty;
                }
                if (price !== undefined && price !== null && String(price).trim() !== '') {
                    line += ' (PHP ' + price + ')';
                }
                return '- ' + line;
            }
            return '- ' + formatPrimitive(item);
        }).filter(function(line) {
            return line !== '- ';
        });

        return lines.length ? lines.join('\n') : 'No materials recorded.';
    }

    if (typeof parsed === 'object') {
        const keys = Object.keys(parsed);
        if (!keys.length) return 'No materials recorded.';

        const lines = keys.map(function(key) {
            const value = parsed[key];
            if (value !== null && typeof value === 'object') {
                return key + ': ' + JSON.stringify(value);
            }
            return key + ': ' + formatPrimitive(value);
        });
        return lines.join('\n');
    }

    return String(parsed);
}

function isClinicalFieldEmpty(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed === '' || trimmed === '{}' || trimmed === '[]';
    }
    if (Array.isArray(value)) return value.length === 0;
    if (typeof value === 'object') return Object.keys(value).length === 0;
    return false;
}

function formatTreatmentPlanDisplay(plan) {
    if (isClinicalFieldEmpty(plan)) return '';
    if (typeof plan === 'string') return plan.trim();
    if (typeof plan === 'object' && plan !== null) {
        const months = plan.follow_up_interval_months;
        const visits = plan.follow_up_total_visits;
        const parts = [];
        if (months !== undefined && months !== null && String(months).trim() !== '' && Number(months) > 0) {
            parts.push('Follow-up interval: every ' + months + ' month(s)');
        }
        if (visits !== undefined && visits !== null && String(visits).trim() !== '' && Number(visits) > 0) {
            parts.push('Total follow-up visits: ' + visits);
        }
        if (parts.length) return parts.join('\n');
        return JSON.stringify(plan, null, 2);
    }
    return String(plan);
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

function getHistoryOdontogramFullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
}

function isHistoryOdontogramFullscreen() {
    const viewport = document.getElementById('histOdontogramViewport');
    return !!viewport && getHistoryOdontogramFullscreenElement() === viewport;
}

function requestHistoryOdontogramFullscreen() {
    const viewport = document.getElementById('histOdontogramViewport');
    if (!viewport) return;
    const requestFn = viewport.requestFullscreen || viewport.webkitRequestFullscreen || viewport.msRequestFullscreen;
    if (requestFn) requestFn.call(viewport);
}

function exitHistoryOdontogramFullscreen() {
    const exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
    if (exitFn) exitFn.call(document);
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
async function openHistoryModal(appointmentId) {
    const modalEl = document.getElementById('historyModal');
    const bs = new bootstrap.Modal(modalEl);
    // clear
    document.getElementById('histPatientName').textContent = '';
    document.getElementById('histApptDate').textContent = '';
    document.getElementById('histNotes').textContent = '';
    document.getElementById('histServices').innerHTML = '';
    document.getElementById('histTreatment').textContent = '';
    document.getElementById('histMaterials').textContent = '';
    document.getElementById('histTreatmentWrap').style.display = 'none';
    document.getElementById('histMaterialsWrap').style.display = 'none';
    exitHistoryOdontogramFullscreen();

        try {
            const res = await fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?appointment_id=' + encodeURIComponent(appointmentId));
            const j = await res.json();
            if (!j.success) {
                notifyDoctor('Could not load history: ' + (j.message || ''), 'danger');
                return;
            }
            const rec = j.record;
            const schedules = j.schedules || [];
        if (!rec) {
            notifyDoctor('No clinical record found for this appointment.', 'warning');
            return;
        }
        // show patient+date
        document.getElementById('histPatientName').textContent = rec._patient_name || 'Unknown patient';
        document.getElementById('histApptDate').textContent = rec._appointment_date || rec.submitted_at || '';
        document.getElementById('histNotes').textContent = rec.doctor_notes || '';

        const backgroundEl = document.getElementById('histBackgroundContent');
        const visitClinicalEl = document.getElementById('histVisitClinicalContent');
        const backgroundData = rec.patient_profiling || null;
        const visitClinicalData = rec.visit_clinical || rec.profiling || null;

        if (backgroundEl && typeof renderBackgroundProfilingFields === 'function') {
            backgroundEl.innerHTML = renderBackgroundProfilingFields(backgroundData);
        }
        if (visitClinicalEl && typeof renderVisitClinicalFields === 'function') {
            visitClinicalEl.innerHTML = renderVisitClinicalFields(visitClinicalData);
        }

        // populate clinical summary
        document.getElementById('histSummary').textContent = rec.summary || 'No summary provided.';

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

        // populate follow-up date
        document.getElementById('histFollowUpDate').textContent = rec.follow_up_date || 'None';

        // services availed for this appointment
        populateServicesAvailedList(document.getElementById('histServices'), j.services_availed || []);

        // treatment plan and materials (only when recorded)
        const treatmentWrap = document.getElementById('histTreatmentWrap');
        const materialsWrap = document.getElementById('histMaterialsWrap');
        const treatmentText = formatTreatmentPlanDisplay(rec.treatment_plan);
        const materialsText = formatMaterialsDisplay(rec.materials_used);
        const materialsEmpty = isClinicalFieldEmpty(rec.materials_used) || materialsText === 'No materials recorded.';

        if (!isClinicalFieldEmpty(rec.treatment_plan) && treatmentText !== '') {
            document.getElementById('histTreatment').textContent = treatmentText;
            treatmentWrap.style.display = '';
        } else {
            document.getElementById('histTreatment').textContent = '';
            treatmentWrap.style.display = 'none';
        }

        if (!materialsEmpty) {
            document.getElementById('histMaterials').textContent = materialsText;
            materialsWrap.style.display = '';
        } else {
            document.getElementById('histMaterials').textContent = '';
            materialsWrap.style.display = 'none';
        }

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

        // schedules: list all related schedules with expand/collapse and actions
        try {
            const wrap = document.getElementById('histSchedules');
            wrap.innerHTML = '';
            if (Array.isArray(schedules) && schedules.length) {
                schedules.forEach(s => {
                    const id = s.schedule_id || Math.random().toString(36).substr(2,6);
                    const start = s.scheduled_start ? s.scheduled_start : '(no start)';
                    const end = s.scheduled_end ? (' — ' + s.scheduled_end) : '';

                    const item = document.createElement('div');
                    item.className = 'list-group-item';

                    const header = document.createElement('div');
                    header.className = 'd-flex w-100 justify-content-between align-items-center';
                    header.innerHTML = `<div><strong>${(s.status||'').toUpperCase()}</strong> <span class="text-muted">${start}${end}</span></div>`;

                    const toggleBtn = document.createElement('a');
                    toggleBtn.setAttribute('data-bs-toggle','collapse');
                    toggleBtn.href = '#sch-details-' + id;
                    toggleBtn.className = 'btn btn-sm btn-outline-secondary';
                    toggleBtn.textContent = 'Details';
                    header.appendChild(toggleBtn);
                    item.appendChild(header);

                    const details = document.createElement('div');
                    details.className = 'collapse mt-2';
                    details.id = 'sch-details-' + id;

                    let inner = `<div class="small text-muted">Created: ${s.created_at || 'N/A'}</div>`;
                    if (s.priority) inner += `<div class="small text-muted">Priority: ${s.priority}</div>`;
                    inner += `<div class="pt-2">Schedule notes: ${s.notes || ''}</div>`;

                    // actions container
                    inner += '<div class="mt-2">';

                    // Mark Arrived - only secretary/admin allowed (server enforces too)
                    if (CURRENT_ROLE === 'secretary' || CURRENT_ROLE === 'admin') {
                        inner += `<button class="btn btn-sm btn-success me-1" data-action="arrive" data-sid="${s.schedule_id}">Mark Arrived</button>`;
                    }

                    // Cancel - admin/secretary can cancel any; doctors can cancel their own (server enforces)
                    if (['admin','secretary','doctor'].includes(CURRENT_ROLE)) {
                        inner += `<button class="btn btn-sm btn-danger me-1" data-action="cancel" data-sid="${s.schedule_id}">Cancel</button>`;
                    }

                    // Reschedule - show if admin/secretary or doctor-owner
                    if (CURRENT_ROLE === 'admin' || CURRENT_ROLE === 'secretary' || (CURRENT_ROLE === 'doctor' && s.user_id == CURRENT_USER)) {
                        inner += `<button class="btn btn-sm btn-warning me-1" data-action="reschedule" data-sid="${s.schedule_id}">Reschedule</button>`;
                    }

                    inner += '</div>';
                    details.innerHTML = inner;
                    item.appendChild(details);

                    // wire event delegation for the buttons
                    item.addEventListener('click', async function(e){
                        const btn = e.target.closest('button[data-action]');
                        if (!btn) return;
                        const act = btn.getAttribute('data-action');
                        const sid = btn.getAttribute('data-sid');
                        if (act === 'arrive') {
                            btn.disabled = true;
                            try {
                                const fd = new FormData(); fd.append('schedule_id', sid);
                                const r = await fetch('../assets/api/appointments/mark_schedule_arrived.php', { method: 'POST', body: fd });
                                const j = await r.json();
                                if (j.success) {
                                    btn.textContent = 'Arrived';
                                    btn.classList.remove('btn-success');
                                    btn.classList.add('btn-secondary');
                                } else notifyDoctor(j.message || 'Failed', 'danger');
                            } catch (e) { notifyDoctor('Error', 'danger'); }
                            btn.disabled = false;
                        } else if (act === 'cancel') {
                            if (!confirm('Cancel this schedule?')) return;
                            btn.disabled = true;
                            try {
                                const fd = new FormData(); fd.append('schedule_id', sid);
                                fd.append('csrf_token', doctorCsrfToken);
                                const r = await fetch('../assets/api/appointments/cancel_schedule.php', { method: 'POST', body: fd });
                                const j = await r.json();
                                if (j.success) {
                                    // update header status
                                    header.querySelector('strong').textContent = 'CANCELLED';
                                } else notifyDoctor(j.message || 'Failed', 'danger');
                            } catch (e) { notifyDoctor('Error', 'danger'); }
                            btn.disabled = false;
                        } else if (act === 'reschedule') {
                            openRescheduleModal(s);
                        }
                    });

                    wrap.appendChild(item);
                });
            } else {
                wrap.innerHTML = '<div class="text-muted small">No schedules found for this appointment.</div>';
            }
        } catch (e) { console.warn('render schedules', e); }

        bs.show();
    } catch (e) {
        console.error(e);
        notifyDoctor('Error loading history', 'danger');
    }
}
</script>
<!-- Reschedule Modal -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
                <div class="mb-2"><button id="rescheduleSuggest" class="btn btn-sm btn-outline-primary">Suggest slots</button></div>
                <div id="rescheduleSuggestions" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button id="rescheduleSaveBtn" type="button" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
const doctorCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
function notifyDoctor(message, type) {
    if (typeof window.showAppToast === 'function') {
        window.showAppToast(String(message || ''), type || 'info');
        return;
    }
    alert(String(message || ''));
}
const resModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
// initialize flatpickr on modal inputs
let fpStart = null, fpEnd = null;
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
    document.getElementById('rescheduleSuggestions').innerHTML = '';
    resModal.show();
    // attach suggest handler
}

document.getElementById('rescheduleSuggest').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleId').value;
    if (!sid) return notifyDoctor('No schedule selected', 'warning');
    // attempt to find schedule owner by querying schedules endpoint (we have no dedicated get-schedule api); reuse smart_schedule with user_id
    // We'll derive user_id from the DOM by searching rendered schedule item if possible
    const btn = this; btn.disabled = true;
    try {
        // fetch schedule info for accurate user_id / duration
        const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
        const gs = await g.json();
        if (!gs.success) return notifyDoctor(gs.message || 'Could not resolve schedule', 'danger');
        const user_id = gs.schedule.user_id || CURRENT_USER;
        const duration = gs.schedule.duration || 30;
        // Post to smart_schedule
        const fd = new FormData(); fd.append('user_id', user_id); fd.append('days_ahead', 14); fd.append('duration', duration);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        const wrap = document.getElementById('rescheduleSuggestions'); wrap.innerHTML = '';
        if (!j.success) return notifyDoctor(j.message || 'No suggestions', 'warning');
            (j.slots || []).slice(0,10).forEach(slot => {
            const a = document.createElement('a'); a.href = '#'; a.className = 'list-group-item list-group-item-action';
            a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : '');
            a.addEventListener('click', function(e){
                e.preventDefault();
                const startLocal = slot.start.replace(' ', 'T').replace(/:00$/,'');
                const endLocal = slot.end ? slot.end.replace(' ', 'T').replace(/:00$/,'') : '';
                if (fpStart && typeof fpStart.setDate === 'function') fpStart.setDate(startLocal);
                else document.getElementById('rescheduleStart').value = startLocal;
                if (endLocal) {
                  if (fpEnd && typeof fpEnd.setDate === 'function') fpEnd.setDate(endLocal);
                  else document.getElementById('rescheduleEnd').value = endLocal;
                }
            });
            wrap.appendChild(a);
        });
    } catch (e) { console.error(e); notifyDoctor('Error fetching suggestions', 'danger'); }
    btn.disabled = false;
});

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
        resModal.hide();
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
                </div>

                <!-- Submit Section -->
                <div class="alert alert-info small" role="alert">
                    <i class="fas fa-info-circle"></i> Once submitted, the appointment will be marked as completed and the secretary will be notified to create records.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitFinishAppointmentBtn">
                    <i class="fas fa-check"></i> Finish Appointment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden video element for camera capture -->
<video id="cameraPreviewVideo" style="display: none; max-width: 100%; max-height: 300px; margin: 10px 0; border: 2px solid #0d6efd; border-radius: 5px;"></video>
<canvas id="cameraCaptureCanvas" style="display: none;"></canvas>

<script>
// Finish Appointment Modal Logic
let finishingAppointmentId = null;
let uploadedPhotos = [];

function openFinishAppointmentModal(appointmentId) {
    finishingAppointmentId = appointmentId;
    uploadedPhotos = [];
    document.getElementById('photoGalleryContainer').innerHTML = '<p class="text-muted small">No photos uploaded yet</p>';
    document.getElementById('photoCountBadge').textContent = '0';
    document.getElementById('finishAppointmentNotes').value = '';
    document.getElementById('finishPhotoUpload').value = '';
    document.getElementById('finishAdditionalCharge').value = '';
    document.getElementById('finishChargeReason').value = '';
    const modal = new bootstrap.Modal(document.getElementById('finishAppointmentModal'));
    modal.show();
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
        if (file.size > 5 * 1024 * 1024) {
            notifyDoctor(`File ${file.name} exceeds 5MB limit`, 'warning');
            continue;
        }
        uploadedPhotos.push(file);
    }
    
    updatePhotoGalleryPreview();
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
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    
    canvas.toBlob((blob) => {
        const file = new File([blob], `capture_${Date.now()}.jpg`, { type: 'image/jpeg' });
        uploadedPhotos.push(file);
        updatePhotoGalleryPreview();
        notifyDoctor('Photo captured', 'success');
    }, 'image/jpeg', 0.9);
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
let finishAppointmentSubmitting = false;
let finishAppointmentIdempotencyKey = null;

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
