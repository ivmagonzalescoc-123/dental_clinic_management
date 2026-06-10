<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
security_ensure_work_type_column($conn);
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['secretary']);

$doctorId = (int)($_GET['doctor_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? 'all'));
$allowedStatus = ['all', 'scheduled', 'rescheduled', 'completed', 'cancelled'];
if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}

$rangeStart = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$rangeEnd = trim((string)($_GET['end_date'] ?? date('Y-m-t')));

$startObj = DateTime::createFromFormat('Y-m-d', $rangeStart);
$endObj = DateTime::createFromFormat('Y-m-d', $rangeEnd);
if (!$startObj || $startObj->format('Y-m-d') !== $rangeStart) {
    $rangeStart = date('Y-m-01');
}
if (!$endObj || $endObj->format('Y-m-d') !== $rangeEnd) {
    $rangeEnd = date('Y-m-t');
}
if ($rangeStart > $rangeEnd) {
    $tmp = $rangeStart;
    $rangeStart = $rangeEnd;
    $rangeEnd = $tmp;
}

$doctors = [];
$dq = $conn->query("SELECT user_id, first_name, last_name, COALESCE(work_type, 'regular') AS work_type FROM users WHERE role_id = 2 ORDER BY first_name ASC, last_name ASC");
if ($dq) {
    while ($d = $dq->fetch_assoc()) {
        $doctors[] = $d;
    }
}

$params = [];
$types = '';
$where = [
    "DATE(s.scheduled_start) BETWEEN ? AND ?",
    "a.appointment_id IS NOT NULL"
];
$params[] = $rangeStart;
$params[] = $rangeEnd;
$types .= 'ss';

if ($doctorId > 0) {
    $where[] = 's.user_id = ?';
    $params[] = $doctorId;
    $types .= 'i';
}
if ($status !== 'all') {
    $where[] = 's.status = ?';
    $params[] = $status;
    $types .= 's';
}

$sql = "
SELECT
    s.schedule_id,
    s.appointment_id,
    s.user_id,
    s.scheduled_start,
    s.scheduled_end,
    s.status,
    a.appointment_date,
    p.patient_id,
    p.first_name AS patient_first_name,
    p.last_name AS patient_last_name,
    p.phone_number AS patient_phone,
    u.first_name AS doctor_first_name,
    u.last_name AS doctor_last_name,
    COALESCE(u.work_type, 'regular') AS work_type
FROM patient_schedules s
INNER JOIN appointments a ON a.appointment_id = s.appointment_id
LEFT JOIN patients p ON p.patient_id = a.patient_id
LEFT JOIN users u ON u.user_id = s.user_id
WHERE " . implode(' AND ', $where) . "
ORDER BY s.scheduled_start DESC, s.schedule_id DESC
";

$schedules = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $schedules[] = $row;
    }
    $stmt->close();
}

$statusCounts = [
    'scheduled' => 0,
    'rescheduled' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
foreach ($schedules as $row) {
    $st = strtolower(trim((string)($row['status'] ?? '')));
    if ($st === '') {
        $st = 'scheduled';
    }
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Follow-up Scheduling</h5>
    <span class="text-muted small">View and update appointment-linked schedules from doctors.</span>
</div>

<form class="card border-0 mb-3" method="GET" action="secretary.php">
    <div class="card-body row g-2 align-items-end">
        <input type="hidden" name="page" value="scheduling">
        <div class="col-md-3">
            <label class="form-label">Doctor</label>
            <select name="doctor_id" class="form-select">
                <option value="0">All Doctors</option>
                <?php foreach ($doctors as $doc): ?>
                    <?php $did = (int)$doc['user_id']; ?>
                    <?php $doctorType = ((string)($doc['work_type'] ?? 'regular') === 'part_time') ? 'Part-time' : 'Regular'; ?>
                    <option value="<?php echo $did; ?>" <?php echo $doctorId === $did ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? '')) . ' (' . $doctorType . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <?php foreach ($allowedStatus as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $status === $opt ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucfirst($opt)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Start Date</label>
            <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($rangeStart); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($rangeEnd); ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button>
            <a href="secretary.php?page=scheduling" class="btn btn-outline-secondary w-100">Reset</a>
        </div>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-md-3"><div class="card border-0"><div class="card-body py-2"><small class="text-muted d-block">Scheduled</small><strong><?php echo (int)$statusCounts['scheduled']; ?></strong></div></div></div>
    <div class="col-md-3"><div class="card border-0"><div class="card-body py-2"><small class="text-muted d-block">Rescheduled</small><strong><?php echo (int)$statusCounts['rescheduled']; ?></strong></div></div></div>
    <div class="col-md-3"><div class="card border-0"><div class="card-body py-2"><small class="text-muted d-block">Completed</small><strong><?php echo (int)$statusCounts['completed']; ?></strong></div></div></div>
    <div class="col-md-3"><div class="card border-0"><div class="card-body py-2"><small class="text-muted d-block">Cancelled</small><strong><?php echo (int)$statusCounts['cancelled']; ?></strong></div></div></div>
</div>

<div class="card border-0">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Schedule</th>
                    <th>Appointment</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($schedules)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No follow-up schedules found for the selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($schedules as $row): ?>
                    <?php
                        $st = strtolower(trim((string)($row['status'] ?? '')));
                        if ($st === '') {
                            $st = 'scheduled';
                        }
                        $badge = ($st === 'scheduled') ? 'primary' : (($st === 'rescheduled') ? 'warning text-dark' : (($st === 'completed') ? 'success' : 'secondary'));
                        $canEdit = !in_array($st, ['completed', 'cancelled'], true);
                    ?>
                    <tr>
                        <td>#<?php echo (int)$row['schedule_id']; ?></td>
                        <td>#<?php echo (int)$row['appointment_id']; ?><br><small class="text-muted"><?php echo htmlspecialchars((string)($row['appointment_date'] ?? '')); ?></small></td>
                        <td>
                            <?php echo htmlspecialchars(trim(($row['patient_first_name'] ?? '') . ' ' . ($row['patient_last_name'] ?? ''))); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars((string)($row['patient_phone'] ?? '')); ?></small>
                        </td>
                        <td>
                            <?php
                                $doctorType = ((string)($row['work_type'] ?? 'regular') === 'part_time') ? 'Part-time' : 'Regular';
                                echo htmlspecialchars(trim(($row['doctor_first_name'] ?? '') . ' ' . ($row['doctor_last_name'] ?? '')));
                            ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($doctorType); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars((string)($row['scheduled_start'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['scheduled_end'] ?? '')); ?></td>
                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                        <td>
                            <?php if ($canEdit): ?>
                                <div class="d-flex gap-1">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-warning"
                                        onclick='openScheduleRescheduleModal(<?php echo json_encode([
                                            'schedule_id' => (int)$row['schedule_id'],
                                            'appointment_id' => (int)$row['appointment_id'],
                                            'doctor_id' => (int)$row['user_id'],
                                            'scheduled_start' => (string)($row['scheduled_start'] ?? ''),
                                            'scheduled_end' => (string)($row['scheduled_end'] ?? ''),
                                        ]); ?>)'>
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="cancelSchedule(<?php echo (int)$row['schedule_id']; ?>)">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">Locked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="scheduleRescheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Follow-up Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="scScheduleId">
                <input type="hidden" id="scAppointmentId">
                <div class="mb-2">
                    <label class="form-label">Doctor</label>
                    <select class="form-select" id="scDoctorId">
                        <option value="">Select doctor</option>
                        <?php foreach ($doctors as $doc): ?>
                            <?php $did = (int)$doc['user_id']; ?>
                            <option value="<?php echo $did; ?>">
                                <?php
                                    $doctorType = ((string)($doc['work_type'] ?? 'regular') === 'part_time') ? 'Part-time' : 'Regular';
                                    echo htmlspecialchars(trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? '')) . ' (' . $doctorType . ')');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Start</label>
                    <input type="datetime-local" class="form-control" id="scStart">
                </div>
                <div class="mb-2">
                    <label class="form-label">End</label>
                    <input type="datetime-local" class="form-control" id="scEnd">
                </div>
                <div class="mb-2">
                    <button class="btn btn-sm btn-outline-primary" type="button" id="scSuggestBtn">Suggest Slots</button>
                </div>
                <div class="list-group" id="scSuggestions"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="scSaveBtn">Update Schedule</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const secretaryCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    let modal = null;

    document.addEventListener('DOMContentLoaded', function() {
        const el = document.getElementById('scheduleRescheduleModal');
        if (el && window.bootstrap) {
            modal = new bootstrap.Modal(el);
        }

        const saveBtn = document.getElementById('scSaveBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveScheduleReschedule);
        }

        const suggestBtn = document.getElementById('scSuggestBtn');
        if (suggestBtn) {
            suggestBtn.addEventListener('click', suggestSlots);
        }
    });

    function toLocal(dt) {
        if (!dt) return '';
        const normalized = String(dt).replace(' ', 'T');
        return normalized.length >= 16 ? normalized.slice(0, 16) : normalized;
    }

    function toServer(val) {
        if (!val) return '';
        return val.replace('T', ' ');
    }

    window.openScheduleRescheduleModal = function(schedule) {
        document.getElementById('scScheduleId').value = schedule.schedule_id || '';
        document.getElementById('scAppointmentId').value = schedule.appointment_id || '';
        document.getElementById('scDoctorId').value = schedule.doctor_id || '';
        document.getElementById('scStart').value = toLocal(schedule.scheduled_start || '');
        document.getElementById('scEnd').value = toLocal(schedule.scheduled_end || '');
        document.getElementById('scSuggestions').innerHTML = '';
        if (modal) {
            modal.show();
        }
    };

    async function suggestSlots() {
        const doctorId = document.getElementById('scDoctorId').value;
        const appointmentId = document.getElementById('scAppointmentId').value;
        const wrap = document.getElementById('scSuggestions');
        wrap.innerHTML = '';
        if (!doctorId) {
            window.showToast('Missing doctor reference.', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('user_id', doctorId);
        if (appointmentId) {
            fd.append('appointment_id', appointmentId);
        }

        try {
            const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
            const j = await res.json();
            if (!j.success || !Array.isArray(j.slots) || j.slots.length === 0) {
                wrap.innerHTML = '<div class="text-muted small">No suggested slots found.</div>';
                window.showToast('No suggested slots found for the selected doctor.', 'warning');
                return;
            }
            j.slots.slice(0, 10).forEach(function(slot) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                item.textContent = slot.start + (slot.end ? (' - ' + slot.end) : '');
                item.addEventListener('click', function() {
                    document.getElementById('scStart').value = toLocal(slot.start || '');
                    document.getElementById('scEnd').value = toLocal(slot.end || '');
                });
                wrap.appendChild(item);
            });
        } catch (err) {
            wrap.innerHTML = '<div class="text-danger small">Error loading suggestions.</div>';
            window.showToast('Error loading suggested slots.', 'danger');
        }
    }

    async function saveScheduleReschedule() {
        const scheduleId = document.getElementById('scScheduleId').value;
        const start = toServer(document.getElementById('scStart').value);
        const end = toServer(document.getElementById('scEnd').value);

        if (!scheduleId || !start || !end) {
            window.showToast('Start and end date/time are required.', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('schedule_id', scheduleId);
        fd.append('scheduled_start', start);
        fd.append('scheduled_end', end);
        fd.append('user_id', document.getElementById('scDoctorId').value || '');
        fd.append('csrf_token', secretaryCsrfToken);

        try {
            const res = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd });
            const j = await res.json();
            if (!j.success) {
                window.showToast(j.message || 'Schedule update failed.', 'danger');
                return;
            }
            window.showToast('Schedule updated successfully.', 'success');
            window.location.reload();
        } catch (err) {
            window.showToast('Server error while updating schedule.', 'danger');
        }
    }

    window.cancelSchedule = async function(scheduleId) {
        if (!confirm('Cancel this follow-up schedule?')) {
            return;
        }
        const fd = new FormData();
        fd.append('schedule_id', scheduleId);
        fd.append('csrf_token', secretaryCsrfToken);

        try {
            const res = await fetch('../assets/api/appointments/cancel_schedule.php', { method: 'POST', body: fd });
            const j = await res.json();
            if (!j.success) {
                window.showToast(j.message || 'Cancel failed.', 'danger');
                return;
            }
            window.showToast('Schedule cancelled.', 'success');
            window.location.reload();
        } catch (err) {
            window.showToast('Server error while cancelling schedule.', 'danger');
        }
    };
})();
</script>
