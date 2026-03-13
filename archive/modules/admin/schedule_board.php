<?php
// Admin — Schedule Board Module
include dirname(dirname(__DIR__)) . '/config/database.php';
$today = date('Y-m-d');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Schedule Board</h5>
    <div class="d-flex gap-2 align-items-center">
        <input type="date" id="boardDatePicker" class="form-control form-control-sm"
               value="<?= htmlspecialchars($today) ?>"
               onchange="loadBoardSchedule(this.value)">
        <button class="btn btn-sm btn-outline-secondary"
                onclick="loadBoardSchedule(document.getElementById('boardDatePicker').value)">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </div>
</div>

<div id="boardContainer">
    <div class="text-center py-5 text-muted">
        <i class="bi bi-hourglass-split" style="font-size:2rem;"></i>
        <p class="mt-2">Loading schedule...</p>
    </div>
</div>

<script>
async function loadBoardSchedule(date) {
    const c = document.getElementById('boardContainer');
    c.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    try {
        const resp = await fetch('../assets/api/arrivals/get_today_schedule.php?date=' + encodeURIComponent(date), { cache: 'no-store' });
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'Failed');
        renderBoardTable(data.schedule, date);
    } catch(e) {
        c.innerHTML = '<div class="alert alert-danger">Error loading schedule: ' + e.message + '</div>';
    }
}

function boardBadge(s) {
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

function bFmtT(t) {
    if (!t) return '--';
    const p = t.split(':');
    const h = parseInt(p[0], 10);
    return (h > 12 ? h - 12 : (h || 12)) + ':' + p[1] + ' ' + (h >= 12 ? 'PM' : 'AM');
}

function renderBoardTable(rows, date) {
    const c = document.getElementById('boardContainer');
    if (!rows.length) {
        c.innerHTML = '<div class="alert alert-info">No appointments scheduled for ' + date + '.</div>';
        return;
    }

    // Group by doctor
    const byDoctor = {};
    rows.forEach(function(row) {
        const key = row.doctor_id || 'unassigned';
        if (!byDoctor[key]) byDoctor[key] = { name: row.doctor_name || 'Unassigned', rows: [] };
        byDoctor[key].rows.push(row);
    });

    let html = '';
    Object.values(byDoctor).forEach(function(group) {
        const arrivedCount    = group.rows.filter(r => r.arrival_status === 'arrived').length;
        const pendingCount    = group.rows.filter(r => ['pending','overdue'].includes(r.arrival_status)).length;
        const noShowCount     = group.rows.filter(r => r.arrival_status === 'no_show').length;
        const inProgressCount = group.rows.filter(r => r.arrival_status === 'in_progress').length;

        html += '<div class="card mb-3">';
        html += '<div class="card-header d-flex justify-content-between align-items-center">';
        html += '<span class="fw-bold"><i class="bi bi-person-badge me-2"></i>' + group.name + '</span>';
        html += '<span>';
        html += '<span class="badge bg-secondary me-1">' + pendingCount + ' pending</span>';
        html += '<span class="badge bg-success me-1">' + arrivedCount + ' arrived</span>';
        if (inProgressCount) html += '<span class="badge bg-primary me-1">' + inProgressCount + ' in progress</span>';
        if (noShowCount)     html += '<span class="badge bg-danger">' + noShowCount + ' no-show</span>';
        html += '</span></div>';
        html += '<div class="card-body p-0"><div class="table-responsive">';
        html += '<table class="table table-sm table-hover mb-0 align-middle">';
        html += '<thead class="table-light"><tr><th>Time</th><th>Patient</th><th>Service</th><th>Duration</th><th>Appt Status</th><th>Arrival</th></tr></thead><tbody>';

        group.rows.forEach(function(row) {
            html += '<tr>';
            html += '<td><strong>' + bFmtT(row.start_time) + '</strong><small class="d-block text-muted">' + bFmtT(row.end_time) + '</small></td>';
            html += '<td>' + (row.is_emergency ? '<span class="badge bg-danger me-1">EMG</span>' : '') + (row.patient_name || '--') + '</td>';
            html += '<td>' + (row.service_name || '--') + '</td>';
            html += '<td>' + (row.duration_minutes ? row.duration_minutes + ' min' : '--') + '</td>';
            html += '<td><span class="badge bg-secondary">' + (row.appointment_status || '--') + '</span></td>';
            html += '<td>' + boardBadge(row.arrival_status) + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div></div></div>';
    });
    c.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    loadBoardSchedule(document.getElementById('boardDatePicker').value);
});
</script>
