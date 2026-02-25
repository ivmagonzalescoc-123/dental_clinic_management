<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

$doctor_id = $_SESSION['user_id'] ?? 0;

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'name_asc'));

$hasAppointmentFilter = ($dateFrom !== '') || ($dateTo !== '') || ($year !== '');

// Build SQL: only patients with appointments assigned to this doctor
$sql = "
    SELECT
        p.patient_id,
        p.first_name,
        p.last_name,
        p.middle_name,
        p.phone_number,
        p.email,
        p.created_at AS patient_created_at,

        a.appointment_id,
        a.appointment_date,
        a.status,
        a.is_online_appointment,
        a.reason,
        a.notes,
        a.created_at AS appointment_created_at,

        -- latest schedule start for the appointment
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS start_time,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS end_time,
        (SELECT s.status FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS schedule_status,

        ss.selected_service_id,
        s.service_name
    FROM patients p
    INNER JOIN appointments a ON a.patient_id = p.patient_id AND a.user_id = ?
    LEFT JOIN selected_services ss ON ss.appointment_id = a.appointment_id
    LEFT JOIN services s ON s.service_id = ss.service_id
";

$where = [];
$types = 'i';
$params = [$doctor_id];

if ($q !== '') {
    $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ? OR p.phone_number LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? )";
    $like = '%' . $q . '%';
    $types .= 'sssss';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($hasAppointmentFilter) {
    if ($year !== '' && ctype_digit($year)) {
        $where[] = "YEAR(a.appointment_date) = ?";
        $types .= 'i';
        $params[] = (int)$year;
    }
    if ($dateFrom !== '') {
        $where[] = "a.appointment_date >= ?";
        $types .= 's';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "a.appointment_date <= ?";
        $types .= 's';
        $params[] = $dateTo;
    }
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY p.last_name ASC, p.first_name ASC, a.appointment_date DESC, a.created_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    // bind dynamically
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = null;
}

$patients = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $patientId = (int)$row['patient_id'];
        if (!isset($patients[$patientId])) {
            $patients[$patientId] = [
                'patient_id' => $patientId,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'middle_name' => $row['middle_name'],
                'phone_number' => $row['phone_number'],
                'email' => $row['email'],
                'appointments' => [],
            ];
        }

        if (!empty($row['appointment_id'])) {
            $appointmentId = (int)$row['appointment_id'];
            if (!isset($patients[$patientId]['appointments'][$appointmentId])) {
                $patients[$patientId]['appointments'][$appointmentId] = [
                    'appointment_id' => $appointmentId,
                    'appointment_date' => $row['appointment_date'],
                    'status' => $row['status'],
                    'is_online_appointment' => (int)$row['is_online_appointment'],
                    'reason' => $row['reason'],
                    'notes' => $row['notes'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'schedule_status' => $row['schedule_status'],
                    'services' => [],
                ];
            }

            if (!empty($row['selected_service_id'])) {
                $patients[$patientId]['appointments'][$appointmentId]['services'][] = [
                    'selected_service_id' => (int)$row['selected_service_id'],
                    'service_name' => $row['service_name'] ?? 'Service',
                ];
            }
        }
    }
    $stmt->close();
}

// Helpers (same as admin)
function format_patient_name($patient)
{
    $middle = trim((string)($patient['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? ' ' . strtoupper(substr($middle, 0, 1)) . '.' : '';
    return trim($patient['first_name'] . $middleInitial . ' ' . $patient['last_name']);
}

function format_time_range($start, $end)
{
    if (empty($start) || empty($end)) return '';
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if (!$startTs || !$endTs) return '';
    return date('h:i A', $startTs) . ' - ' . date('h:i A', $endTs);
}

function status_badge_class($status)
{
    switch ($status) {
        case 'completed':
            return 'success';
        case 'cancelled':
        case 'no_show':
            return 'danger';
        case 'confirmed':
            return 'primary';
        case 'checked_in':
        case 'in_progress':
            return 'info';
        case 'rescheduled':
            return 'secondary';
        case 'scheduled':
        default:
            return 'warning';
    }
}

function patient_latest_appointment_date($patient)
{
    if (empty($patient['appointments'])) return null;
    $latest = null;
    foreach ($patient['appointments'] as $appt) {
        $d = $appt['appointment_date'] ?? null;
        if ($d && ($latest === null || $d > $latest)) {
            $latest = $d;
        }
    }
    return $latest;
}

$patientList = array_values($patients);

usort($patientList, function ($a, $b) use ($sort) {
    $nameA = strtolower(trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? '')));
    $nameB = strtolower(trim(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? '')));
    $countA = isset($a['appointments']) ? count($a['appointments']) : 0;
    $countB = isset($b['appointments']) ? count($b['appointments']) : 0;
    $latestA = patient_latest_appointment_date($a) ?? '';
    $latestB = patient_latest_appointment_date($b) ?? '';

    switch ($sort) {
        case 'name_desc':
            return $nameB <=> $nameA;
        case 'appointments_desc':
            return $countB <=> $countA;
        case 'latest_appt_desc':
            return $latestB <=> $latestA;
        case 'latest_appt_asc':
            return $latestA <=> $latestB;
        case 'name_asc':
        default:
            return $nameA <=> $nameB;
    }
});
?>

<div class="card">
    <div class="card-header d-flex flex-nowrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2" style="min-width: 0;">
            <h2 class="card-title mb-0 text-truncate">My Patients</h2>
        </div>

        <form class="d-flex flex-nowrap align-items-center gap-2" method="GET" action="doctor.php">
            <input type="hidden" name="page" value="patients">

            <div class="input-group input-group-sm" style="width: 240px;">
                <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search...">
                <button class="btn btn-primary" type="submit" title="Search"><i class="bi bi-search"></i></button>
            </div>

            <div class="dropdown" data-bs-auto-close="outside">
                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Filters
                </button>
                <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 320px;">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small mb-1">From</label>
                            <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">To</label>
                            <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>

                        <div class="col-6">
                            <label class="form-label small mb-1">Year</label>
                            <select class="form-select form-select-sm" name="year">
                                <option value="">All</option>
                                <?php
                                    $currentYear = (int)date('Y');
                                    for ($y = $currentYear; $y >= $currentYear - 10; $y--) {
                                        $selected = ((string)$y === (string)$year) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars((string)$y) . '" ' . $selected . '>' . htmlspecialchars((string)$y) . '</option>';
                                    }
                                ?>
                            </select>
                        </div>

                        <div class="col-6">
                            <label class="form-label small mb-1">Sort</label>
                            <select class="form-select form-select-sm" name="sort">
                                <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                                <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                                <option value="appointments_desc" <?php echo $sort === 'appointments_desc' ? 'selected' : ''; ?>>Most Appointments</option>
                                <option value="latest_appt_desc" <?php echo $sort === 'latest_appt_desc' ? 'selected' : ''; ?>>Latest Appointment (newest)</option>
                                <option value="latest_appt_asc" <?php echo $sort === 'latest_appt_asc' ? 'selected' : ''; ?>>Latest Appointment (oldest)</option>
                            </select>
                        </div>

                        <div class="col-12 d-flex justify-content-between align-items-center mt-1">
                            <small class="text-muted">
                                Shows patients assigned to you (doctor).
                            </small>
                            <div class="d-flex gap-2">
                                <a class="btn btn-outline-secondary btn-sm" href="doctor.php?page=patients">Clear</a>
                                <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($patientList)): ?>
            <div class="alert alert-info mb-0">No patients found.</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($patientList as $patient): ?>
                    <?php
                        $patientId = (int)$patient['patient_id'];
                        $appointments = $patient['appointments'];
                        $appointmentCount = count($appointments);
                        $collapseId = 'patientAppointments' . $patientId;
                        $cardId = 'patientCard' . $patientId;
                    ?>
                    <div class="col-12">
                        <div class="card h-100" id="<?php echo htmlspecialchars($cardId); ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-0"><?php echo htmlspecialchars(format_patient_name($patient)); ?></h5>
                                        <div class="text-muted small">
                                            <?php if (!empty($patient['phone_number'])): ?>
                                                <div><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($patient['phone_number']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($patient['email'])): ?>
                                                <div><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column align-items-end">
                                        <span class="badge bg-secondary"><?php echo $appointmentCount; ?> Appt<?php echo $appointmentCount === 1 ? '' : 's'; ?></span>
                                        <button class="btn btn-outline-primary btn-sm mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($collapseId); ?>" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($collapseId); ?>">
                                            View Appointment Records
                                        </button>
                                    </div>
                                </div>

                                <div class="collapse mt-3" id="<?php echo htmlspecialchars($collapseId); ?>">
                                    <?php if (empty($appointments)): ?>
                                        <div class="alert alert-light border mb-0">No appointment records yet.</div>
                                    <?php else: ?>
                                        <div class="accordion" id="accordion-<?php echo htmlspecialchars($patientId); ?>">
                                            <?php foreach ($appointments as $appt): ?>
                                                <?php
                                                    $apptId = (int)$appt['appointment_id'];
                                                    $apptCollapseId = 'apptCollapse' . $patientId . '-' . $apptId;
                                                    $serviceTitle = 'No service selected';
                                                    if (!empty($appt['services'])) {
                                                        $serviceTitle = $appt['services'][0]['service_name'] ?? $serviceTitle;
                                                    }
                                                    $timeRange = format_time_range($appt['start_time'], $appt['end_time']);
                                                ?>
                                                <div class="accordion-item mb-2">
                                                    <h2 class="accordion-header" id="heading-<?php echo htmlspecialchars($patientId . '-' . $apptId); ?>">
                                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($apptCollapseId); ?>" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($apptCollapseId); ?>">
                                                            <div class="w-100">
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <div class="me-2">
                                                                        <div class="fw-semibold"><?php echo htmlspecialchars($serviceTitle); ?></div>
                                                                        <div class="small text-muted">
                                                                            <?php echo htmlspecialchars($appt['appointment_date']); ?>
                                                                            <?php if ($timeRange !== ''): ?>
                                                                                • <?php echo htmlspecialchars($timeRange); ?>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <span class="badge bg-<?php echo htmlspecialchars(status_badge_class($appt['status'])); ?>">
                                                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $appt['status']))); ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </button>
                                                    </h2>
                                                    <div id="<?php echo htmlspecialchars($apptCollapseId); ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?php echo htmlspecialchars($patientId . '-' . $apptId); ?>" data-bs-parent="#accordion-<?php echo htmlspecialchars($patientId); ?>">
                                                        <div class="accordion-body">
                                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                                <?php if (!empty($appt['is_online_appointment'])): ?>
                                                                    <span class="badge bg-info">Online</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">In-Person</span>
                                                                <?php endif; ?>
                                                                <span class="badge bg-light text-dark border">Appointment ID: <?php echo htmlspecialchars($apptId); ?></span>
                                                            </div>

                                                            <?php if (!empty($appt['services'])): ?>
                                                                <div class="mb-2">
                                                                    <div class="fw-semibold small text-muted mb-1">Selected Service(s)</div>
                                                                    <div class="d-flex flex-wrap gap-2">
                                                                        <?php foreach ($appt['services'] as $svc): ?>
                                                                            <span class="badge bg-primary"><?php echo htmlspecialchars($svc['service_name']); ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if (!empty($appt['reason'])): ?>
                                                                <div class="mb-2">
                                                                    <div class="fw-semibold small text-muted">Reason</div>
                                                                    <div><?php echo htmlspecialchars($appt['reason']); ?></div>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if (!empty($appt['start_time']) || !empty($appt['end_time'])): ?>
                                                                <div class="mb-2">
                                                                    <div class="fw-semibold small text-muted">Scheduled</div>
                                                                    <div><?php echo htmlspecialchars($appt['start_time'] ?? '') . ' ' . (empty($appt['end_time']) ? '' : ' — ' . htmlspecialchars($appt['end_time'])); ?></div>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if (!empty($appt['notes'])): ?>
                                                                <div class="mb-2">
                                                                    <div class="fw-semibold small text-muted">Notes</div>
                                                                    <div><?php echo htmlspecialchars($appt['notes']); ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
