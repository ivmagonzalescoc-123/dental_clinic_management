<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

$doctor_id = $_SESSION['user_id'] ?? 0;

// Fetch schedules for this doctor (or appointments assigned to this doctor)
$sql = "
SELECT s.schedule_id, s.appointment_id, s.user_id, s.scheduled_start, s.scheduled_end, s.status,
       a.appointment_date, a.patient_id, p.first_name, p.last_name, p.phone_number,
       (SELECT GROUP_CONCAT(service_name SEPARATOR ', ') FROM selected_services ss JOIN services sv ON ss.service_id = sv.service_id WHERE ss.appointment_id = a.appointment_id) AS services
FROM patient_schedules s
LEFT JOIN appointments a ON s.appointment_id = a.appointment_id
LEFT JOIN patients p ON a.patient_id = p.patient_id
WHERE s.user_id = ? OR a.user_id = ?
ORDER BY s.scheduled_start DESC
";

$stmt = $conn->prepare($sql);
$schedules = [];
if ($stmt) {
    $stmt->bind_param('ii', $doctor_id, $doctor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $schedules[] = $r;
    $stmt->close();
}

// Fetch upcoming appointments for this doctor to select when creating schedule
$apptSql = 'SELECT appointment_id, appointment_date, patient_id FROM appointments WHERE user_id = ? ORDER BY appointment_date ASC LIMIT 200';
$aStmt = $conn->prepare($apptSql);
$appointments = [];
if ($aStmt) {
    $aStmt->bind_param('i', $doctor_id);
    $aStmt->execute();
    $aRes = $aStmt->get_result();
    while ($a = $aRes->fetch_assoc()) $appointments[] = $a;
    $aStmt->close();
}

?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Scheduling</h4>
        <div>
            <button class="btn btn-sm btn-outline-primary" id="btnOpenCreateSchedule">Create Schedule</button>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($schedules)): ?>
            <div class="alert alert-info">No schedules found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Schedule ID</th>
                            <th>Appointment</th>
                            <th>Patient</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $sch): ?>
                            <tr>
                                <td><?php echo (int)$sch['schedule_id']; ?></td>
                                <td><?php echo htmlspecialchars($sch['appointment_id'] ? ($sch['appointment_id'] . ' • ' . ($sch['appointment_date'] ?? '')) : '—'); ?></td>
                                <td><?php echo htmlspecialchars(($sch['first_name'] ?? '') . ' ' . ($sch['last_name'] ?? '')); ?><br/><small class="text-muted"><?php echo htmlspecialchars($sch['phone_number'] ?? ''); ?></small></td>
                                <td><?php echo htmlspecialchars($sch['scheduled_start'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($sch['scheduled_end'] ?? ''); ?></td>
                                <td><span class="badge bg-<?php echo ($sch['status'] === 'scheduled' ? 'primary' : ($sch['status'] === 'completed' ? 'success' : 'secondary')); ?>"><?php echo htmlspecialchars(ucfirst($sch['status'])); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary btn-reschedule" data-sid="<?php echo (int)$sch['schedule_id']; ?>">Reschedule</button>
                                    <button class="btn btn-sm btn-outline-danger btn-cancel" data-sid="<?php echo (int)$sch['schedule_id']; ?>">Cancel</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Schedule Modal -->
<div class="modal fade" id="createScheduleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Create Schedule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
            <label class="form-label">Appointment (optional)</label>
            <select id="createScheduleAppointment" class="form-select">
                <option value="">-- Select appointment --</option>
                <?php foreach ($appointments as $a): ?>
                    <option value="<?php echo (int)$a['appointment_id']; ?>"><?php echo (int)$a['appointment_id']; ?> • <?php echo htmlspecialchars($a['appointment_date']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2"><label class="form-label">Start</label><input id="createScheduleStart" class="form-control" type="datetime-local"/></div>
        <div class="mb-2"><label class="form-label">End (optional)</label><input id="createScheduleEnd" class="form-control" type="datetime-local"/></div>
        <div class="mb-2"><button id="btnSuggestSlots" class="btn btn-sm btn-outline-primary">Suggest slots</button></div>
        <div id="suggestionsWrap" class="list-group mt-2"></div>
        <div class="mt-2 text-muted small">You can also use <strong>Auto-schedule</strong> to create a schedule based on profiling suggestions.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button id="btnAutoSchedule" type="button" class="btn btn-warning">Auto-schedule</button>
        <button id="btnCreateScheduleSave" type="button" class="btn btn-primary">Save</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script>
document.addEventListener('DOMContentLoaded', function(){
    const modalEl = document.getElementById('createScheduleModal');
    const bsModal = new bootstrap.Modal(modalEl);
    document.getElementById('btnOpenCreateSchedule').addEventListener('click', ()=>bsModal.show());

    document.getElementById('btnSuggestSlots').addEventListener('click', async function(){
        const appt = document.getElementById('createScheduleAppointment').value || '';
        const fd = new FormData();
        fd.append('user_id', <?php echo (int)$doctor_id; ?>);
        if (appt) fd.append('appointment_id', appt);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        const wrap = document.getElementById('suggestionsWrap'); wrap.innerHTML = '';
        if (!j.success) return alert(j.message || 'No suggestions');
        (j.slots || []).slice(0,10).forEach(slot => {
            const a = document.createElement('a'); a.href='#'; a.className='list-group-item list-group-item-action';
            a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : '');
            a.addEventListener('click', function(e){ e.preventDefault(); document.getElementById('createScheduleStart').value = slot.start.replace(' ', 'T').replace(/:00$/,''); document.getElementById('createScheduleEnd').value = slot.end ? slot.end.replace(' ', 'T').replace(/:00$/,'') : ''; });
            wrap.appendChild(a);
        });
    });

    document.getElementById('btnCreateScheduleSave').addEventListener('click', async function(){
        const appt = document.getElementById('createScheduleAppointment').value || '';
        const start = document.getElementById('createScheduleStart').value.replace('T',' ');
        const end = (document.getElementById('createScheduleEnd').value || '').replace('T',' ');
        if (!start) return alert('Select start time');
        const fd = new FormData(); fd.append('appointment_id', appt); fd.append('user_id', <?php echo (int)$doctor_id; ?>); fd.append('book_start', start); if (end) fd.append('book_end', end);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.success) return alert(j.message || 'Failed to create schedule');
        alert('Scheduled');
        location.reload();
    });

    document.getElementById('btnAutoSchedule').addEventListener('click', async function(){
        const appt = document.getElementById('createScheduleAppointment').value || '';
        if (!appt) return alert('Select an appointment to auto-schedule');
        const fd = new FormData(); fd.append('appointment_id', appt); fd.append('user_id', <?php echo (int)$doctor_id; ?>); fd.append('days_ahead', 14); fd.append('duration', 30);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.success) return alert(j.message || 'Auto-schedule failed');
        if (!j.slots || j.slots.length === 0) return alert('No suggested slots');
        // auto book first slot that is not today
        const todayDate = new Date().toISOString().slice(0,10);
        let slot = null;
        for (let i = 0; i < (j.slots || []).length; i++) {
            const s = j.slots[i];
            const sDate = (s.start || '').split(' ')[0];
            if (sDate && sDate > todayDate) { slot = s; break; }
        }
        // fallback to first slot if none found (to avoid failing silently)
        if (!slot) slot = j.slots[0] || null;
        const fd2 = new FormData(); fd2.append('appointment_id', appt); fd2.append('user_id', <?php echo (int)$doctor_id; ?>); fd2.append('book_start', slot.start); fd2.append('book_end', slot.end || '');
        const res2 = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd2 });
        const j2 = await res2.json();
        if (!j2.success) return alert(j2.message || 'Failed to book slot');
        alert('Auto-scheduled successfully'); location.reload();
    });

    // Reschedule / cancel handlers
    document.querySelectorAll('.btn-reschedule').forEach(btn=>btn.addEventListener('click', async function(){
        const sid = this.getAttribute('data-sid');
        const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
        const j = await g.json();
        if (!j.success) return alert(j.message || 'Could not load schedule');
        const sch = j.schedule;
        // open modal prefilled
        document.getElementById('createScheduleAppointment').value = sch.appointment_id || '';
        if (sch.scheduled_start) document.getElementById('createScheduleStart').value = sch.scheduled_start.replace(' ', 'T').replace(/:00$/,'');
        if (sch.scheduled_end) document.getElementById('createScheduleEnd').value = sch.scheduled_end.replace(' ', 'T').replace(/:00$/,'');
        bsModal.show();
    }));

    document.querySelectorAll('.btn-cancel').forEach(btn=>btn.addEventListener('click', async function(){
        if (!confirm('Cancel this schedule?')) return;
        const sid = this.getAttribute('data-sid');
        const fd = new FormData(); fd.append('schedule_id', sid);
        const res = await fetch('../assets/api/appointments/cancel_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.success) return alert(j.message || 'Cancel failed');
        alert('Cancelled'); location.reload();
    }));

});
</script>
