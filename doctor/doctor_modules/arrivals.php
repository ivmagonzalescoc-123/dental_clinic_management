<?php
// Doctor — My Patients Today (Arrivals) Module
include dirname(dirname(__DIR__)) . '/config/database.php';
$today     = date('Y-m-d');
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$doctor_id = (int)($_SESSION['user_id'] ?? 0);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-person-check me-2"></i>My Patients Today</h5>
    <button class="btn btn-sm btn-outline-secondary" onclick="loadDocArrival(currentDocDate)">
        <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>
</div>

<ul class="nav nav-tabs mb-3" id="docArrTabs">
    <li class="nav-item">
        <a class="nav-link active" href="#" onclick="switchDocTab('today');return false;">
            Today <span class="badge bg-success ms-1" id="docTodayBadge">--</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#" onclick="switchDocTab('tomorrow');return false;">
            Tomorrow <span class="badge bg-secondary ms-1" id="docTomorrowBadge">--</span>
        </a>
    </li>
</ul>

<div id="docArrContainer">
    <div class="text-center py-5 text-muted">
        <i class="bi bi-hourglass-split" style="font-size:2rem;"></i>
        <p class="mt-2">Loading your schedule...</p>
    </div>
</div>

<script>
function notifyDoctorArrival(message, type) {
    window.showToast(String(message || ''), type || 'info');
}
const doctorCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
const DOC_TODAY    = <?= json_encode($today) ?>;
const DOC_TOMORROW = <?= json_encode($tomorrow) ?>;
const DOC_UID      = <?= json_encode($doctor_id) ?>;
let currentDocDate = DOC_TODAY;

function switchDocTab(t) {
    currentDocDate = (t === 'today') ? DOC_TODAY : DOC_TOMORROW;
    document.querySelectorAll('#docArrTabs .nav-link').forEach((el, i) => {
        el.classList.toggle('active', (t === 'today' && i === 0) || (t === 'tomorrow' && i === 1));
    });
    loadDocArrival(currentDocDate);
}

async function loadDocArrival(date) {
    const c = document.getElementById('docArrContainer');
    c.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    try {
        const url  = '../assets/api/arrivals/get_today_schedule.php?date=' + encodeURIComponent(date) + '&doctor_id=' + DOC_UID;
        const resp = await fetch(url, { cache: 'no-store' });
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'Failed');
        renderDocTable(data.schedule, date);
        const badge = document.getElementById(date === DOC_TODAY ? 'docTodayBadge' : 'docTomorrowBadge');
        if (badge) badge.textContent = data.schedule.length;
        if (date === DOC_TODAY) {
            const sideB = document.getElementById('doctorArrivalsBadge');
            if (sideB) sideB.textContent = data.schedule.filter(r => ['pending','arrived','late','overdue'].includes(r.arrival_status)).length;
        }
    } catch(e) {
        c.innerHTML = '<div class="alert alert-danger">Error: ' + e.message + '</div>';
    }
}

function daBadge(s) {
    const m = {
        pending:     ['secondary', 'Pending'],
        arrived:     ['success',   'Arrived'],
        late:        ['warning',   'Late'],
        overdue:     ['warning',   'Overdue'],
        no_show:     ['danger',    'No-Show'],
        in_progress: ['primary',   'In Progress'],
        served:      ['info',      'Served'],
        completed:   ['secondary', 'Done']
    };
    const [cls, label] = m[s] || ['secondary', s];
    return '<span class="badge bg-' + cls + '">' + label + '</span>';
}

function dFmtT(t) {
    if (!t) return '--';
    const p = t.split(':');
    const h = parseInt(p[0], 10);
    return (h > 12 ? h - 12 : (h || 12)) + ':' + p[1] + ' ' + (h >= 12 ? 'PM' : 'AM');
}

function renderDocTable(rows, date) {
    const c       = document.getElementById('docArrContainer');
    const isToday = (date === DOC_TODAY);
    if (!rows.length) {
        c.innerHTML = '<div class="alert alert-info">No appointments scheduled for ' + date + '.</div>';
        return;
    }
    let h = '<div class="table-responsive"><table class="table table-hover align-middle table-sm">';
    h += '<thead class="table-light"><tr><th>Time</th><th>Patient</th><th>Service</th><th>Status</th>';
    if (isToday) h += '<th>Action</th>';
    h += '</tr></thead><tbody>';

    rows.forEach(function(row) {
        const canStart  = isToday && ['arrived', 'late'].includes(row.arrival_status);
        const ongoing   = row.arrival_status === 'in_progress';
        const isPending = isToday && ['pending', 'overdue'].includes(row.arrival_status);

        h += '<tr>';
        h += '<td><strong>' + dFmtT(row.start_time) + '</strong><small class="d-block text-muted">' + dFmtT(row.end_time) + '</small></td>';
        h += '<td>';
        if (row.is_emergency) h += '<span class="badge bg-danger me-1">EMERGENCY</span>';
        h += (row.patient_name || '--');
        if (row.patient_phone) h += '<small class="d-block text-muted">' + row.patient_phone + '</small>';
        h += '</td>';
        h += '<td>' + (row.service_name || '--');
        if (row.duration_minutes) h += '<small class="d-block text-muted">' + row.duration_minutes + ' min</small>';
        h += '</td>';
        h += '<td>' + daBadge(row.arrival_status) + '</td>';

        if (isToday) {
            h += '<td>';
            if (canStart) {
                h += '<button class="btn btn-sm btn-primary" onclick="doStartSession(' + row.appointment_id + ')"><i class="bi bi-play-circle"></i> Start Session</button>';
            } else if (ongoing) {
                h += '<a class="btn btn-sm btn-outline-primary" href="doctor.php?page=ehr&appointment_id=' + row.appointment_id + '"><i class="bi bi-journal-medical"></i> Continue EHR</a>';
            } else if (isPending) {
                h += '<span class="text-muted small">Awaiting arrival</span>';
            } else {
                h += '<span class="text-muted small">--</span>';
            }
            h += '</td>';
        }
        h += '</tr>';
    });
    h += '</tbody></table></div>';
    c.innerHTML = h;
}

async function doStartSession(appointmentId) {
    if (!(await showConfirmModal({ title: 'Start Session', message: 'Start session for this patient?', confirmText: 'Start Session' }))) return;
    const fd = new FormData();
    fd.append('appointment_id', appointmentId);
    fd.append('csrf_token', doctorCsrfToken);
    try {
        const resp = await fetch('../assets/api/arrivals/start_session.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            let url = 'doctor.php?page=ehr&appointment_id=' + appointmentId;
            if (data.schedule_id) url += '&schedule_id=' + data.schedule_id;
            window.location.href = url;
        } else {
            notifyDoctorArrival(data.message || 'Failed to start session', 'danger');
        }
    } catch(e) { notifyDoctorArrival('Error: ' + e.message, 'danger'); }
}

document.addEventListener('DOMContentLoaded', function() {
    loadDocArrival(DOC_TODAY);
    fetch('../assets/api/arrivals/get_today_schedule.php?date=' + DOC_TOMORROW + '&doctor_id=' + DOC_UID)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                const b = document.getElementById('docTomorrowBadge');
                if (b) b.textContent = d.schedule.length;
            }
        }).catch(function() {});
});
</script>
