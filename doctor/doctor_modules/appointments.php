<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';

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
    
    // Handle COMPLETE VISIT (submit EHR and send to billing)
    if ($action === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!security_validate_csrf_token($csrf)) {
            $_SESSION['message'] = 'Invalid security token. Please refresh and try again.';
            $_SESSION['message_type'] = 'danger';
            header('Location: doctor.php?page=appointments');
            exit;
        }

        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $toothData = $_POST['tooth_data'] ?? []; // Array of tooth updates
        $treatmentIds = $_POST['treatment_ids'] ?? []; // Array of treatment IDs
        $notes = $_POST['notes'] ?? '';
        $followUpDate = $_POST['follow_up_date'] ?? null;
        $materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
        $doctor_fee = isset($_POST['doctor_fee']) ? (float)$_POST['doctor_fee'] : 0.0;
        $recommended_services = isset($_POST['recommended_services']) ? json_decode($_POST['recommended_services'], true) : null;
        
        if ($appointmentId <= 0 || $serviceId <= 0) {
            $_SESSION['message'] = 'Invalid appointment or service data.';
            $_SESSION['message_type'] = 'warning';
            header('Location: doctor.php?page=appointments');
            exit;
        }
        
        $conn->begin_transaction();
        try {
            // Verify appointment exists and belongs to this doctor
            $stmt = $conn->prepare('SELECT appointment_id, patient_id, user_id, status FROM appointments WHERE appointment_id = ? AND user_id = ?');
            if (!$stmt) throw new Exception('Error preparing statement.');
            $stmt->bind_param('ii', $appointmentId, $doctor_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $appt = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            
            if (!$appt) {
                throw new Exception('Appointment not found or access denied.');
            }
            
            $patientId = $appt['patient_id'];
            $currentStatus = strtolower((string)($appt['status'] ?? ''));
            if (!appointment_can_transition_doctor($currentStatus, 'completed')) {
                throw new Exception('Cannot complete visit from status: ' . $currentStatus);
            }
            
            // Update appointment status to completed and add notes
            $status = 'completed';
            $stmt = $conn->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?');
            if ($stmt) {
                $stmt->bind_param('ssi', $status, $notes, $appointmentId);
                $stmt->execute();
                $stmt->close();
            }
            
            // Record tooth status for each tooth updated
            foreach ($toothData as $toothId => $toothStatus) {
                if (!empty($toothStatus)) {
                    $stmt = $conn->prepare('
                        INSERT INTO tooth_status (tooth_selected, status) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status)
                    ');
                    if ($stmt) {
                        $stmt->bind_param('is', $toothId, $toothStatus);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            
            // Record treatments if provided
            if (!empty($treatmentIds)) {
                foreach ($treatmentIds as $treatmentId) {
                    $treatmentId = (int)$treatmentId;
                    // You can add logic to link treatments to appointments/patients here
                }
            }
            
            // Get service price
            $stmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
            $servicePrice = 0;
            if ($stmt) {
                $stmt->bind_param('i', $serviceId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $servicePrice = (float)($row['initial_price'] ?? 0);
                }
                $stmt->close();
            }

            // Calculate billing total: service + materials + doctor fee + any recommended services
            $billingAmount = 0.0;
            $billingAmount += $servicePrice;
            if ($materials_used && is_array($materials_used)) {
                foreach ($materials_used as $m) {
                    $qty = isset($m['qty']) ? (float)$m['qty'] : 0;
                    $price = isset($m['price']) ? (float)$m['price'] : 0;
                    $billingAmount += $qty * $price;
                }
            }
            if ($recommended_services && is_array($recommended_services)) {
                foreach ($recommended_services as $sid) {
                    $sstmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
                    $sstmt->bind_param('i', $sid);
                    $sstmt->execute();
                    $sres = $sstmt->get_result();
                    if ($sres && ($srow = $sres->fetch_assoc())) $billingAmount += (float)$srow['initial_price'];
                    $sstmt->close();
                }
            }
            if ($doctor_fee > 0) $billingAmount += $doctor_fee;

            $billingStatus = 'unpaid';
            $existingBillingId = 0;
            $chk = $conn->prepare('SELECT billing_id FROM billing WHERE appointment_id = ? LIMIT 1');
            if ($chk) {
                $chk->bind_param('i', $appointmentId);
                $chk->execute();
                $cres = $chk->get_result();
                $crow = $cres ? $cres->fetch_assoc() : null;
                $existingBillingId = (int)($crow['billing_id'] ?? 0);
                $chk->close();
            }

            if ($existingBillingId > 0) {
                $stmt = $conn->prepare('UPDATE billing SET user_id = ?, total_amount = ?, status = ?, created_at = NOW() WHERE billing_id = ?');
                if ($stmt) {
                    $stmt->bind_param('idsi', $patientId, $billingAmount, $billingStatus, $existingBillingId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, status, created_at) VALUES (?, ?, ?, ?, NOW())');
                if ($stmt) {
                    $stmt->bind_param('iids', $appointmentId, $patientId, $billingAmount, $billingStatus);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            // Handle follow-up appointment if requested
            if (!empty($followUpDate)) {
                $followUpDate = date('Y-m-d', strtotime($followUpDate));
                // You can add logic to create follow-up appointment here
                // For now, we'll just store it in notes
            }
            
            $conn->commit();

            if (function_exists('log_system_event')) {
                log_system_event(
                    $conn,
                    'appointment.complete_visit',
                    'appointments',
                    $appointmentId,
                    'Completed visit and generated/updated billing.',
                    (int)$doctor_id
                );
            }
            
            $_SESSION['message'] = 'Visit completed successfully. Patient sent to secretary for billing.';
            $_SESSION['message_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['message'] = 'Error completing visit: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
        
        header('Location: doctor.php?page=appointments');
        exit;
    }
    
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

<ul class="nav nav-tabs doctor-appointments-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $appointmentsTab === 'recent' ? 'active' : ''; ?>" href="doctor.php?page=appointments&tab=recent">
            <i class="bi bi-clock-history me-1"></i> Recent Appointments
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $appointmentsTab === 'general' ? 'active' : ''; ?>" href="doctor.php?page=appointments&tab=general">
            <i class="bi bi-calendar3 me-1"></i> Appointments Calendar & List
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $appointmentsTab === 'followup' ? 'active' : ''; ?>" href="doctor.php?page=appointments&tab=followup">
            <i class="bi bi-calendar-range me-1"></i> Follow-up Appointments
        </a>
    </li>
</ul>

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
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <input type="text" id="doctorRecentSearchInput" class="form-control form-control-sm" placeholder="Search recent appointments" style="max-width: 280px;">
                        <button type="button" id="doctorRecentSearchBtn" class="btn btn-sm btn-outline-primary">Search</button>
                        <button type="button" id="doctorRecentSearchClearBtn" class="btn btn-sm btn-outline-secondary">Clear</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle doctor-recent-appointments-table" id="doctorRecentAppointmentsTable">
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
            <div class="d-flex align-items-center gap-2">
                <span id="appointmentsFilterBadge" class="small fw-semibold text-muted" style="display:none;"></span>
                <button id="clearAppointmentsDateFilter" type="button" class="btn btn-sm btn-outline-secondary" style="display:none;">Show All</button>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <input type="text" id="doctorGeneralSearchInput" class="form-control form-control-sm" placeholder="Search appointments list" style="max-width: 280px;">
                <button type="button" id="doctorGeneralSearchBtn" class="btn btn-sm btn-outline-primary">Search</button>
                <button type="button" id="doctorGeneralSearchClearBtn" class="btn btn-sm btn-outline-secondary">Clear</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle doctor-general-appointments-table" id="doctorGeneralAppointmentsTable">
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
                                    $timeLabel = 'All day';
                                    if ($startTime !== '') {
                                        $timeLabel = substr($startTime, 0, 5);
                                        if ($endTime !== '') {
                                            $timeLabel .= ' - ' . substr($endTime, 0, 5);
                                        }
                                    }
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
                                        <span class="badge bg-<?php echo ($appt['status'] === 'completed' ? 'success' : ($appt['status'] === 'cancelled' ? 'danger' : 'warning text-dark')); ?>">
                                            <?php echo htmlspecialchars(ucfirst((string)$appt['status'])); ?>
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
            <div class="small text-muted">All scheduled follow-up entries assigned to you.</div>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <input type="text" id="doctorFollowupSearchInput" class="form-control form-control-sm" placeholder="Search follow-up appointments" style="max-width: 280px;">
                <button type="button" id="doctorFollowupSearchBtn" class="btn btn-sm btn-outline-primary">Search</button>
                <button type="button" id="doctorFollowupSearchClearBtn" class="btn btn-sm btn-outline-secondary">Clear</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle doctor-general-appointments-table" id="doctorFollowupAppointmentsTable">
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

                                <div class="mb-3" id="histProfilingWrap">
                                    <h6>Profiling / EHR Overview</h6>
                                    <div class="row">
                                        <div class="col-md-6"><strong>Medical History:</strong> <div id="histMedicalHistory" class="small text-muted"></div></div>
                                        <div class="col-md-6"><strong>Medications:</strong> <div id="histMedications" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Allergies:</strong> <div id="histAllergies" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Smoking:</strong> <div id="histSmoking" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Alcohol:</strong> <div id="histAlcohol" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>ASA:</strong> <div id="histASA" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Last Visit:</strong> <div id="histLastVisit" class="small text-muted"></div></div>
                                        <div class="col-12"><strong>Periodontal / Oral Exam:</strong> <div id="histPeriodontal" class="small text-muted"></div></div>
                                        <div class="col-12"><strong>Dental Chart / Findings:</strong> <div id="histDentalChart" class="small text-muted"></div></div>
                                        <div class="col-12"><strong>Radiographs / Imaging Notes:</strong> <div id="histRadiographs" class="small text-muted"></div></div>
                                    </div>
                                </div>

                                <div class="mb-3"><strong>Clinical Notes:</strong>
                                    <pre id="histNotes" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Recommended Services:</strong>
                                    <ul id="histServices"></ul>
                                </div>
                                <div class="mb-3"><strong>Treatment Plan:</strong>
                                    <pre id="histTreatment" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Materials Used:</strong>
                                    <pre id="histMaterials" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Images:</strong>
                                    <div id="histImages" class="d-flex flex-wrap gap-2"></div>
                                </div>

                                <div class="mb-3"><strong>Related Schedules</strong>
                                    <div id="histSchedules" class="list-group small"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>Odontogram (read-only)</strong></label>
                                    <div id="histOdontogramWrap" style="border:1px solid #ddd; height:420px;">
                                        <iframe id="histOdontoFrame" src="../odontogram/index.html" style="width:100%;height:100%;border:0;" ></iframe>
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

<!-- EHR Modal -->
<div class="modal fade" id="ehrModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-medical"></i> Patient EHR & Clinical Notes
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="doctor.php?page=appointments&action=complete">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? ''); ?>">
                    <input type="hidden" name="appointment_id" id="modalAppointmentId">
                    <input type="hidden" name="patient_id" id="modalPatientId">
                    
                    <!-- Patient Info -->
                    <div class="mb-3 p-3 bg-light rounded">
                        <h6 class="mb-2"><i class="bi bi-person"></i> Patient Information</h6>
                        <p class="mb-0"><strong id="modalPatientName"></strong></p>
                        <p class="mb-0 small text-muted"><span id="modalPatientPhone"></span> | <span id="modalPatientEmail"></span></p>
                    </div>
                    
                    <!-- Odontogram -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-tooth"></i> <strong>Odontogram (Tooth Chart)</strong></label>
                        <div class="card p-3">
                            <div class="row mb-2">
                                <div class="col-12">
                                    <strong>Upper Teeth</strong>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php foreach ($teeth as $tooth): 
                                            if ($tooth['upper_lower'] == 1): // Upper teeth
                                        ?>
                                            <div class="tooth-selector">
                                                <select class="form-select form-select-sm tooth-status-select" 
                                                        data-tooth-id="<?php echo $tooth['tooth_id']; ?>"
                                                        name="tooth_data[<?php echo $tooth['tooth_id']; ?>]">
                                                    <option value="">Tooth <?php echo htmlspecialchars($tooth['tooth_number']); ?></option>
                                                    <option value="healthy">Healthy</option>
                                                    <option value="decayed">Decayed</option>
                                                    <option value="filled">Filled</option>
                                                    <option value="crowned">Crowned</option>
                                                    <option value="root_canal">Root Canal</option>
                                                    <option value="missing">Missing</option>
                                                </select>
                                            </div>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <strong>Lower Teeth</strong>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($teeth as $tooth): 
                                            if ($tooth['upper_lower'] == 0): // Lower teeth
                                        ?>
                                            <div class="tooth-selector">
                                                <select class="form-select form-select-sm tooth-status-select" 
                                                        data-tooth-id="<?php echo $tooth['tooth_id']; ?>"
                                                        name="tooth_data[<?php echo $tooth['tooth_id']; ?>]">
                                                    <option value="">Tooth <?php echo htmlspecialchars($tooth['tooth_number']); ?></option>
                                                    <option value="healthy">Healthy</option>
                                                    <option value="decayed">Decayed</option>
                                                    <option value="filled">Filled</option>
                                                    <option value="crowned">Crowned</option>
                                                    <option value="root_canal">Root Canal</option>
                                                    <option value="missing">Missing</option>
                                                </select>
                                            </div>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Treatments -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-bandaid"></i> <strong>Treatments Recommended</strong></label>
                        <div class="card p-3">
                            <?php foreach ($treatments as $treatment): ?>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input treatment-checkbox" 
                                           id="treatment_<?php echo $treatment['treatment_id']; ?>"
                                           name="treatment_ids[]" value="<?php echo $treatment['treatment_id']; ?>">
                                    <label class="form-check-label" for="treatment_<?php echo $treatment['treatment_id']; ?>">
                                        <?php echo htmlspecialchars($treatment['treatment_name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Service for Consultation -->
                    <div class="mb-3">
                        <label for="serviceSelect" class="form-label"><i class="bi bi-cash-coin"></i> <strong>Service/Consultation Type</strong></label>
                        <select class="form-select" id="serviceSelect" name="service_id" required>
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?php echo $service['service_id']; ?>">
                                    <?php echo htmlspecialchars($service['service_name']); ?> (₱<?php echo number_format($service['initial_price'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This will be the consultation fee. Additional treatment charges can be added later in billing.</small>
                    </div>
                    
                    <!-- Clinical Notes -->
                    <div class="mb-3">
                        <label for="clinicalNotes" class="form-label"><i class="bi bi-file-text"></i> <strong>Clinical Notes</strong></label>
                        <textarea class="form-control" id="clinicalNotes" name="notes" rows="4" placeholder="Enter clinical observations, findings, recommendations, etc."></textarea>
                    </div>
                    
                    <!-- Follow-up Appointment -->
                    <div class="mb-3">
                        <label for="followUpDate" class="form-label"><i class="bi bi-calendar-plus"></i> <strong>Schedule Follow-up Appointment (Optional)</strong></label>
                        <input type="date" class="form-control" id="followUpDate" name="follow_up_date">
                        <small class="text-muted">Patient can confirm this during billing phase.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Complete Visit & Send to Billing
                    </button>
                </div>
            </form>
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

</style>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
function loadPatientEHR(patient) {
    document.getElementById('modalAppointmentId').value = patient.appointment_id;
    document.getElementById('modalPatientId').value = patient.patient_id;
    document.getElementById('modalPatientName').textContent = patient.first_name + ' ' + patient.last_name;
    document.getElementById('modalPatientPhone').textContent = patient.phone_number;
    document.getElementById('modalPatientEmail').textContent = patient.email;
    
    // Clear previous selections
    document.querySelectorAll('.tooth-status-select').forEach(select => select.value = '');
    document.querySelectorAll('.treatment-checkbox').forEach(check => check.checked = false);
    document.getElementById('serviceSelect').value = '';
    document.getElementById('clinicalNotes').value = '';
    document.getElementById('followUpDate').value = '';
}

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

function initSimpleTableSearch(config) {
    const table = document.getElementById(config.tableId);
    const input = document.getElementById(config.inputId);
    const searchBtn = document.getElementById(config.searchBtnId);
    const clearBtn = document.getElementById(config.clearBtnId);
    if (!table || !input || !searchBtn || !clearBtn) return;

    const noMatchRowId = config.noMatchRowId || '';

    const apply = function () {
        const query = input.value.trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        let visibleCount = 0;

        rows.forEach(function (row) {
            if (noMatchRowId && row.id === noMatchRowId) return;
            const text = (row.textContent || '').toLowerCase();
            const match = query === '' || text.indexOf(query) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });

        if (noMatchRowId) {
            const noMatchRow = document.getElementById(noMatchRowId);
            if (noMatchRow) {
                noMatchRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
            }
        }
    };

    searchBtn.addEventListener('click', apply);
    clearBtn.addEventListener('click', function () {
        input.value = '';
        apply();
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            apply();
        }
    });
}

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

document.addEventListener('DOMContentLoaded', function () {
    initDoctorAppointmentsCalendar();

    initSimpleTableSearch({
        tableId: 'doctorRecentAppointmentsTable',
        inputId: 'doctorRecentSearchInput',
        searchBtnId: 'doctorRecentSearchBtn',
        clearBtnId: 'doctorRecentSearchClearBtn'
    });

    initSimpleTableSearch({
        tableId: 'doctorFollowupAppointmentsTable',
        inputId: 'doctorFollowupSearchInput',
        searchBtnId: 'doctorFollowupSearchBtn',
        clearBtnId: 'doctorFollowupSearchClearBtn'
    });

    const generalInput = document.getElementById('doctorGeneralSearchInput');
    const generalSearchBtn = document.getElementById('doctorGeneralSearchBtn');
    const generalClearBtn = document.getElementById('doctorGeneralSearchClearBtn');

    if (generalInput && generalSearchBtn && generalClearBtn) {
        generalSearchBtn.addEventListener('click', function () {
            generalSearchTerm = generalInput.value.trim().toLowerCase();
            applyGeneralTableFilters(false);
        });

        generalClearBtn.addEventListener('click', function () {
            generalInput.value = '';
            generalSearchTerm = '';
            applyGeneralTableFilters(false);
        });

        generalInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                generalSearchTerm = generalInput.value.trim().toLowerCase();
                applyGeneralTableFilters(false);
            }
        });
    }
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

// If page requested to show history on load, trigger modal
<?php if (!empty($_GET['show_history']) && !empty($_GET['appointment_id'])): ?>
document.addEventListener('DOMContentLoaded', function(){
    try { openHistoryModal(<?php echo (int)$_GET['appointment_id']; ?>); } catch(e){}
});
<?php endif; ?>

// Open history modal and fetch clinical record (read-only)
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

        try {
            const res = await fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?appointment_id=' + encodeURIComponent(appointmentId));
            const j = await res.json();
            if (!j.success) {
                alert('Could not load history: ' + (j.message || ''));
                return;
            }
            const rec = j.record;
            const schedules = j.schedules || [];
        if (!rec) {
            alert('No clinical record found for this appointment.');
            return;
        }
        // show patient+date
        // fetch patient name from DOM or server: we'll try to pull patient via appointment list row
        document.getElementById('histPatientName').textContent = rec._patient_name || ('Patient ID: ' + (rec.patient_id || ''));
        document.getElementById('histApptDate').textContent = rec._appointment_date || rec.follow_up_date || (rec.submitted_at ?? '');
        document.getElementById('histNotes').textContent = rec.doctor_notes || '';

        // profiling: prefer clinical-record-level `profiling` then patient-level `patient_profiling`
        const pf = rec.profiling || rec.patient_profiling || null;
        if (pf) {
            try {
                const p = (typeof pf === 'string') ? JSON.parse(pf) : pf;
                document.getElementById('histMedicalHistory').textContent = p.medical_history || '';
                document.getElementById('histMedications').textContent = p.medications || '';
                document.getElementById('histAllergies').textContent = p.allergies || '';
                document.getElementById('histSmoking').textContent = p.smoking || '';
                document.getElementById('histAlcohol').textContent = p.alcohol || '';
                document.getElementById('histASA').textContent = p.asa || '';
                document.getElementById('histLastVisit').textContent = p.last_visit || '';
                document.getElementById('histPeriodontal').textContent = p.periodontal || '';
                document.getElementById('histDentalChart').textContent = p.dental_chart || '';
            } catch (e) { console.warn('parse profiling', e); }
        } else {
            // clear
            ['histMedicalHistory','histMedications','histAllergies','histSmoking','histAlcohol','histASA','histLastVisit','histPeriodontal','histDentalChart'].forEach(id => document.getElementById(id).textContent = '');
        }

        // radiographs notes
        document.getElementById('histRadiographs').textContent = rec.radiographs_notes || '';

        // services
        const ul = document.getElementById('histServices');
        if (Array.isArray(rec.recommended_services)) {
            rec.recommended_services.forEach(sid => {
                const li = document.createElement('li');
                const name = (sid in serviceMap) ? serviceMap[sid] : ('Service ID: ' + sid);
                li.textContent = name;
                ul.appendChild(li);
            });
        }

        // treatment plan and materials
        document.getElementById('histTreatment').textContent = JSON.stringify(rec.treatment_plan || {}, null, 2);
        document.getElementById('histMaterials').textContent = formatMaterialsDisplay(rec.materials_used);

        // images
        const imgWrap = document.getElementById('histImages');
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

        // odontogram: send to iframe via postMessage if present
        try {
            const odf = document.getElementById('histOdontoFrame');
            if (rec.odontogram && odf && odf.contentWindow) {
                odf.contentWindow.postMessage({ type: 'load_odontogram', toothState: rec.odontogram, readonly: true }, '*');
            }
        } catch (e) {}

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
                                } else alert(j.message || 'Failed');
                            } catch (e) { alert('Error'); }
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
                                } else alert(j.message || 'Failed');
                            } catch (e) { alert('Error'); }
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
        alert('Error loading history');
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
    if (!sid) return alert('No schedule selected');
    // attempt to find schedule owner by querying schedules endpoint (we have no dedicated get-schedule api); reuse smart_schedule with user_id
    // We'll derive user_id from the DOM by searching rendered schedule item if possible
    const btn = this; btn.disabled = true;
    try {
        // fetch schedule info for accurate user_id / duration
        const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
        const gs = await g.json();
        if (!gs.success) return alert(gs.message || 'Could not resolve schedule');
        const user_id = gs.schedule.user_id || CURRENT_USER;
        const duration = gs.schedule.duration || 30;
        // Post to smart_schedule
        const fd = new FormData(); fd.append('user_id', user_id); fd.append('days_ahead', 14); fd.append('duration', duration);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        const wrap = document.getElementById('rescheduleSuggestions'); wrap.innerHTML = '';
        if (!j.success) return alert(j.message || 'No suggestions');
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
    } catch (e) { console.error(e); alert('Error fetching suggestions'); }
    btn.disabled = false;
});

document.getElementById('rescheduleSaveBtn').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleId').value;
    const start = toServerFormat(document.getElementById('rescheduleStart').value);
    const end = toServerFormat(document.getElementById('rescheduleEnd').value);
    if (!sid || !start) return alert('Start datetime required');
    this.disabled = true;
    try {
        const fd = new FormData();
        fd.append('schedule_id', sid);
        fd.append('scheduled_start', start);
        fd.append('scheduled_end', end);
        fd.append('csrf_token', doctorCsrfToken);
        const res = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.success) return alert(j.message || 'Failed to reschedule');
        alert('Rescheduled');
        resModal.hide();
        // reload page or update UI: simple approach reload
        location.reload();
    } catch (e) { console.error(e); alert('Error'); }
    this.disabled = false;
});
</script>
