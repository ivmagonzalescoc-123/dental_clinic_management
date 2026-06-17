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
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS start_time,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS end_time,
        (SELECT s.status FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS schedule_status,

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

function patient_latest_appointment($patient)
{
    if (empty($patient['appointments'])) return null;
    $latest = null;
    foreach ($patient['appointments'] as $appt) {
        $d = $appt['appointment_date'] ?? null;
        if ($d && ($latest === null || $d > ($latest['appointment_date'] ?? ''))) {
            $latest = $appt;
        }
    }
    return $latest;
}

function format_date_display($value)
{
    if (empty($value)) return '—';
    $ts = strtotime((string)$value);
    if (!$ts) return htmlspecialchars((string)$value);
    return date('M d, Y', $ts);
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

$selectedPatientId = (int)($_GET['patient_id'] ?? 0);
if ($selectedPatientId <= 0 && !empty($patientList)) {
    $selectedPatientId = (int)($patientList[0]['patient_id'] ?? 0);
}

$selectedPatient = null;
foreach ($patientList as $pItem) {
    if ((int)$pItem['patient_id'] === $selectedPatientId) {
        $selectedPatient = $pItem;
        break;
    }
}

if ($selectedPatient === null && !empty($patientList)) {
    $selectedPatient = $patientList[0];
    $selectedPatientId = (int)$selectedPatient['patient_id'];
}

$basePatientParams = [
    'page' => 'patients',
    'q' => $q,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'year' => $year,
    'sort' => $sort,
];
?>

<div class="card border-0">
    <div class="card-header">
        <h2 class="card-title mb-0">My Patients</h2>
    </div>

    <div class="card-body border-bottom py-2">
        <form class="module-search-toolbar module-search-toolbar--bare" method="GET" action="doctor.php" style="margin:0;">
            <input type="hidden" name="page" value="patients">

            <div class="unified-table-search-wrap module-search-inline">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input type="search" class="form-control unified-table-search-input" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search patients..." aria-label="Search patients">
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
            <div class="row g-3 align-items-start">
                <div class="col-12 col-xl-7">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" data-skip-table-search="1">
                            <thead class="table-light">
                                <tr>
                                    <th>Patient</th>
                                    <th>Latest Appointment</th>
                                    <th>Status</th>
                                    <th class="text-end">Records</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patientList as $patient): ?>
                                    <?php
                                        $pId = (int)$patient['patient_id'];
                                        $latest = patient_latest_appointment($patient);
                                        $rowParams = $basePatientParams;
                                        $rowParams['patient_id'] = $pId;
                                        $href = 'doctor.php?' . http_build_query($rowParams);
                                    ?>
                                    <tr class="<?php echo $pId === $selectedPatientId ? 'table-primary' : ''; ?>" style="cursor:pointer;" onclick="ajaxNavigate('<?php echo htmlspecialchars($href, ENT_QUOTES); ?>')">
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars(format_patient_name($patient)); ?></div>
                                            <div class="small text-muted">
                                                <?php echo htmlspecialchars((string)($patient['email'] ?? '')); ?>
                                                <?php if (!empty($patient['phone_number'])): ?>
                                                    • <?php echo htmlspecialchars((string)$patient['phone_number']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($latest): ?>
                                                <div><?php echo htmlspecialchars(format_date_display($latest['appointment_date'] ?? '')); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars(format_time_range($latest['start_time'] ?? '', $latest['end_time'] ?? '')); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($latest): ?>
                                                <span class="badge bg-<?php echo htmlspecialchars(status_badge_class($latest['status'] ?? 'scheduled')); ?>">
                                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($latest['status'] ?? 'scheduled')))); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Appointments</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-secondary"><?php echo count($patient['appointments']); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-12 col-xl-5">
                    <div class="card border h-100">
                        <div class="card-body">
                            <?php if (!$selectedPatient): ?>
                                <div class="text-muted">Select a patient to view details.</div>
                            <?php else: ?>
                                <?php $selectedLatest = patient_latest_appointment($selectedPatient); ?>
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars(format_patient_name($selectedPatient)); ?></h5>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string)($selectedPatient['email'] ?? '')); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string)($selectedPatient['phone_number'] ?? '')); ?></div>
                                    </div>
                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="openPatientProfileModal(<?php echo (int)$selectedPatient['patient_id']; ?>, '<?php echo htmlspecialchars(format_patient_name($selectedPatient), ENT_QUOTES); ?>')">Profile</button>
                                </div>

                                <div class="d-flex gap-2 flex-wrap mb-3">
                                    <span class="badge bg-primary-subtle text-primary border">Total Records: <?php echo count($selectedPatient['appointments']); ?></span>
                                    <?php if ($selectedLatest): ?>
                                        <span class="badge bg-light text-dark border">Latest: <?php echo htmlspecialchars(format_date_display($selectedLatest['appointment_date'] ?? '')); ?></span>
                                    <?php endif; ?>
                                </div>

                                <h6 class="mb-2">Appointment Timeline</h6>
                                <?php if (empty($selectedPatient['appointments'])): ?>
                                    <div class="alert alert-light border mb-0">No appointment records yet.</div>
                                <?php else: ?>
                                    <div style="max-height: 520px; overflow:auto;">
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($selectedPatient['appointments'] as $appt): ?>
                                                <?php
                                                    $apptId = (int)$appt['appointment_id'];
                                                    $serviceTitle = 'No service availed';
                                                    if (!empty($appt['services'])) {
                                                        $serviceTitle = $appt['services'][0]['service_name'] ?? $serviceTitle;
                                                    }
                                                    $timeRange = format_time_range($appt['start_time'] ?? '', $appt['end_time'] ?? '');
                                                ?>
                                                <div class="list-group-item px-0">
                                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                                        <div>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars($serviceTitle); ?></div>
                                                            <div class="small text-muted">
                                                                <?php echo htmlspecialchars(format_date_display($appt['appointment_date'] ?? '')); ?>
                                                                <?php if ($timeRange !== ''): ?>
                                                                    • <?php echo htmlspecialchars($timeRange); ?>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($appt['reason'])): ?>
                                                                <div class="small"><?php echo htmlspecialchars($appt['reason']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($appt['notes'])): ?>
                                                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($appt['notes']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="badge bg-<?php echo htmlspecialchars(status_badge_class($appt['status'] ?? 'scheduled')); ?> mb-1"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($appt['status'] ?? 'scheduled')))); ?></span>
                                                            <div class="small text-muted">#<?php echo (int)$apptId; ?></div>
                                                            <div class="small text-muted"><?php echo !empty($appt['is_online_appointment']) ? 'Online' : 'In-Person'; ?></div>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($appt['services']) && count($appt['services']) > 1): ?>
                                                        <div class="d-flex flex-wrap gap-1 mt-2">
                                                            <?php foreach ($appt['services'] as $svc): ?>
                                                                <span class="badge bg-primary-subtle text-primary border"><?php echo htmlspecialchars((string)$svc['service_name']); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="patientProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Patient Profiling</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2" id="profilePatientName">-</div>
                <div id="profileBackgroundContent" class="small"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
async function openPatientProfileModal(patientId, patientName) {
    const modalEl = document.getElementById('patientProfileModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);

    document.getElementById('profilePatientName').innerHTML = '<strong>Patient:</strong> ' + (patientName || '-');
    const contentEl = document.getElementById('profileBackgroundContent');
    if (contentEl) {
        contentEl.innerHTML = '<div class="text-muted">Loading...</div>';
    }
    modal.show();

    try {
        const res = await fetch('../assets/api/patients/get_profiling.php?patient_id=' + encodeURIComponent(patientId));
        const data = await res.json();
        if (!data || !data.success) {
            if (contentEl) {
                contentEl.innerHTML = '<div class="text-muted">Unable to load patient background.</div>';
            }
            return;
        }

        if (data.patient) {
            let ageText = 'N/A';
            if (data.patient.date_of_birth) {
                const dob = new Date(data.patient.date_of_birth);
                if (!isNaN(dob.getTime())) {
                    const ageDate = new Date(Date.now() - dob.getTime());
                    ageText = Math.abs(ageDate.getUTCFullYear() - 1970);
                }
            }
            document.getElementById('profilePatientName').innerHTML =
                `<strong>Patient:</strong> ${data.patient.first_name} ${data.patient.middle_name ? data.patient.middle_name + ' ' : ''}${data.patient.last_name}<br>
                 <small class="text-muted">
                    <strong>DOB:</strong> ${data.patient.date_of_birth || 'N/A'} (Age: ${ageText}) &nbsp;|&nbsp;
                    <strong>Gender:</strong> ${data.patient.gender || 'N/A'}<br>
                    <strong>Phone:</strong> ${data.patient.phone_number || 'N/A'} &nbsp;|&nbsp;
                    <strong>Address:</strong> ${data.patient.address || 'N/A'}
                 </small>`;
        }

        if (contentEl && typeof renderBackgroundProfilingFields === 'function') {
            contentEl.innerHTML = renderBackgroundProfilingFields(data.profiling || null);
        }
    } catch (err) {
        console.warn('Failed to load patient profiling', err);
        if (contentEl) {
            contentEl.innerHTML = '<div class="text-muted">Error loading patient background.</div>';
        }
    }
}
</script>
