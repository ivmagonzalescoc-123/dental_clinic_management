<?php
// Secretary — Arrivals Module
include dirname(dirname(__DIR__)) . '/config/database.php';
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-person-check me-2"></i>Arrivals</h5>
    <button class="btn btn-sm btn-outline-secondary" onclick="loadArrivalSchedule(currentArrivalDate)">
        <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>
</div>

<nav class="module-section-nav" aria-label="Arrival days">
    <div class="module-section-nav__track">
        <ul class="nav nav-tabs mb-0" id="arrivalTabs">
            <li class="nav-item">
                <a class="nav-link active" href="#" onclick="switchArrivalTab('today');return false;">
                    Today <span class="badge bg-primary ms-1" id="todayArrBadge">--</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" onclick="switchArrivalTab('tomorrow');return false;">
                    Tomorrow <span class="badge bg-secondary ms-1" id="tomorrowArrBadge">--</span>
                </a>
            </li>
        </ul>
    </div>
</nav>

<div id="arrivalContainer">
    <div class="text-center py-5 text-muted">
        <i class="bi bi-hourglass-split" style="font-size:2rem;"></i>
        <p class="mt-2">Loading schedule...</p>
    </div>
</div>

<script>
window.secretaryCsrfToken  = <?php echo json_encode($csrf_token ?? ''); ?>;
window.ARR_TODAY           = <?= json_encode($today) ?>;
window.ARR_TOMORROW        = <?= json_encode($tomorrow) ?>;
window.currentArrivalDate  = window.ARR_TODAY;

function switchArrivalTab(t) {
    currentArrivalDate = (t === 'today') ? ARR_TODAY : ARR_TOMORROW;
    document.querySelectorAll('#arrivalTabs .nav-link').forEach((el, i) => {
        el.classList.toggle('active', (t === 'today' && i === 0) || (t === 'tomorrow' && i === 1));
    });
    loadArrivalSchedule(currentArrivalDate);
}

async function loadArrivalSchedule(date) {
    const c = document.getElementById('arrivalContainer');
    c.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    try {
        const resp = await fetch('../assets/api/arrivals/get_today_schedule.php?date=' + encodeURIComponent(date), { cache: 'no-store' });
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'Failed to load');
        renderArrTable(data.schedule, date);
        const badge = document.getElementById(date === ARR_TODAY ? 'todayArrBadge' : 'tomorrowArrBadge');
        if (badge) badge.textContent = data.schedule.length;
    } catch(e) {
        c.innerHTML = '<div class="alert alert-danger">Error: ' + e.message + '</div>';
    }
}

function arrBadge(s) {
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

function fmtT(t) {
    if (!t) return '--';
    const p = t.split(':');
    const h = parseInt(p[0], 10);
    return (h > 12 ? h - 12 : (h || 12)) + ':' + p[1] + ' ' + (h >= 12 ? 'PM' : 'AM');
}

function renderArrTable(rows, date) {
    const c       = document.getElementById('arrivalContainer');
    const isToday = (date === ARR_TODAY);
    if (!rows.length) {
        c.innerHTML = '<div class="alert alert-info">No appointments scheduled for ' + date + '.</div>';
        return;
    }
    let h = '<div class="table-responsive"><table class="table table-hover align-middle table-sm">';
    h += '<thead class="table-light"><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Service</th><th>Status</th>';
    if (isToday) h += '<th>Actions</th>';
    h += '</tr></thead><tbody>';

    rows.forEach(function(row) {
        const done    = ['arrived', 'late', 'no_show', 'in_progress', 'served', 'completed'].includes(row.arrival_status)
                     || ['in_progress', 'awaiting_secretary_records', 'records_complete', 'completed'].includes(row.appointment_status);
        const canMark = isToday && !done;
        const ready   = isToday && ['arrived', 'late'].includes(row.arrival_status);

        h += '<tr>';
        h += '<td><strong>' + fmtT(row.start_time) + '</strong><small class="d-block text-muted">' + fmtT(row.end_time) + '</small></td>';
        h += '<td>' + (row.is_emergency ? '<span class="badge bg-danger me-1">EMERGENCY</span>' : '') + (row.patient_name || '--');
        h += '<small class="d-block text-muted">' + (row.patient_phone || '') + '</small></td>';
        h += '<td>' + (row.doctor_name || '--') + '</td>';
        h += '<td>' + (row.service_name || '--') + (row.duration_minutes ? '<small class="d-block text-muted">' + row.duration_minutes + ' min</small>' : '') + '</td>';
        h += '<td id="as-' + row.appointment_id + '">' + arrBadge(row.arrival_status) + '</td>';

        if (isToday) {
            h += '<td>';
            if (canMark) {
                h += '<button class="btn btn-sm btn-success me-1 mb-1" onclick="doArrived(' + row.appointment_id + ')"><i class="bi bi-check-circle"></i> Arrived</button>';
                h += '<button class="btn btn-sm btn-danger mb-1" onclick="doNoShow(' + row.appointment_id + ')"><i class="bi bi-x-circle"></i> No-Show</button>';
            } else if (ready) {
                h += '<span class="badge bg-info">Ready for Doctor</span>';
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

async function doArrived(id) {
    if (!(await showConfirmModal({ title: 'Mark Arrived', message: 'Mark this patient as arrived?', confirmText: 'Mark Arrived' }))) return;
    const fd = new FormData();
    fd.append('appointment_id', id);
    fd.append('csrf_token', secretaryCsrfToken);
    try {
        const resp = await fetch('../assets/api/arrivals/mark_arrived.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            const b = document.getElementById('secretaryArrivalsBadge');
            if (b) {
                const nextCount = Math.max(0, (parseInt(b.textContent, 10) || 1) - 1);
                b.textContent = nextCount;
                b.style.display = nextCount > 0 ? '' : 'none';
            }
            showArrToast(data.message, 'success');
            loadArrivalSchedule(currentArrivalDate);
        } else {
            showArrToast(data.message || 'Failed', 'danger');
        }
    } catch(e) { showArrToast('Error: ' + e.message, 'danger'); }
}

async function doNoShow(id) {
    if (!(await showConfirmModal({ title: 'Mark No-Show', message: 'Mark this patient as no-show?', confirmText: 'Mark No-Show', variant: 'danger' }))) return;
    const fd = new FormData();
    fd.append('appointment_id', id);
    fd.append('csrf_token', secretaryCsrfToken);
    try {
        const resp = await fetch('../assets/api/arrivals/mark_no_show.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            const b = document.getElementById('secretaryArrivalsBadge');
            if (b) {
                const nextCount = Math.max(0, (parseInt(b.textContent, 10) || 1) - 1);
                b.textContent = nextCount;
                b.style.display = nextCount > 0 ? '' : 'none';
            }
            showArrToast('Marked as no-show.', 'warning');
            loadArrivalSchedule(currentArrivalDate);
        } else {
            showArrToast(data.message || 'Failed', 'danger');
        }
    } catch(e) { showArrToast('Error: ' + e.message, 'danger'); }
}

function showArrToast(msg, type) {
    if (typeof window.showToast === 'function') {
        window.showToast(String(msg || ''), type || 'info');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadArrivalSchedule(ARR_TODAY);
    fetch('../assets/api/arrivals/get_today_schedule.php?date=' + ARR_TOMORROW)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                const b = document.getElementById('tomorrowArrBadge');
                if (b) b.textContent = d.schedule.length;
            }
        }).catch(function() {});
});
</script>
