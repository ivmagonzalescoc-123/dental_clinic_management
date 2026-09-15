<?php
// Secretary — Arrivals Module
include dirname(dirname(__DIR__)) . '/config/database.php';
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Fetch active doctors for the modal
$doctors_list = [];
$docRes = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY first_name ASC");
if ($docRes) { while ($r = $docRes->fetch_assoc()) $doctors_list[] = $r; }
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
    <div class="table-responsive">
        <table class="table table-hover align-middle table-sm">
            <thead class="table-light"><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Service</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="arrInitialSkeletonBody"></tbody>
        </table>
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
    c.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle table-sm"><thead class="table-light"><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Service</th><th>Status</th><th>Actions</th></tr></thead><tbody id="arrSkeletonBody"></tbody></table></div>';
    if (typeof DentalUI !== 'undefined' && DentalUI.showSkeleton) {
        DentalUI.showSkeleton(document.getElementById('arrSkeletonBody'), 5, 6);
    }
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
                h += '<button class="btn btn-sm btn-success me-1 mb-1" onclick="doArrived(' + row.appointment_id + ', ' + JSON.stringify(row).replace(/"/g, '&quot;') + ')"><i class="bi bi-check-circle"></i> Arrived</button>';
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

async function doArrived(id, rowData) {
    openArrivedModal(rowData);
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
            const dot = document.getElementById('secretaryArrivalsDot');
            if (dot) {
                const count = parseInt(dot.dataset.count || '0', 10);
                const next = Math.max(0, count - 1);
                dot.dataset.count = next;
                dot.classList.toggle('is-active', next > 0);
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

// Arrived modal logic
let arrModalData = {};

function openArrivedModal(row) {
    arrModalData = row || {};
    const modal = document.getElementById('arrivedModal');
    if (!modal) return;

    // Doctor select
    const docSel = document.getElementById('arrDoctorSelect');
    if (docSel) docSel.value = row.doctor_id || '';

    // Services checkboxes
    const svcBox = document.getElementById('arrServiceCheckboxes');
    loadArrivalServices(svcBox);

    // Time slot
    const tsSel = document.getElementById('arrTimeslotSelect');
    const tsWrap = document.getElementById('arrTimeslotWrap');
    if (tsWrap) tsWrap.style.display = '';
    if (tsSel) {
        tsSel.innerHTML = '<option value="">Keep current time (' + fmtT(row.start_time) + ' – ' + fmtT(row.end_time) + ')</option>';
        tsSel.value = '';
        loadArrivalTimeslots(tsSel, row);
    }

    // Doctor label
    const docLabel = document.getElementById('arrCurrentDoctor');
    if (docLabel) {
        docLabel.textContent = row.doctor_name || 'Not assigned';
    }

    // Patient label
    const patLabel = document.getElementById('arrPatientName');
    if (patLabel) patLabel.textContent = row.patient_name || 'Unknown';

    // Date label
    const dateLabel = document.getElementById('arrDateLabel');
    if (dateLabel) dateLabel.textContent = row.slot_date || '';

    // Store appointment id
    document.getElementById('arrApptId').value = row.appointment_id;

    const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
    bsModal.show();
}

async function loadArrivalServices(container) {
    if (!container) return;
    container.innerHTML = '<small class="text-muted">Loading services...</small>';
    try {
        const resp = await fetch('../assets/api/appointments/manage_services.php?action=services&appointment_id=0');
        const data = await resp.json();
        if (data.success && Array.isArray(data.services) && data.services.length > 0) {
            container.innerHTML = data.services.map(s =>
                '<div class="form-check">' +
                '<input class="form-check-input" type="checkbox" value="' + s.service_id + '" id="arrSvc_' + s.service_id + '">' +
                '<label class="form-check-label" for="arrSvc_' + s.service_id + '">' + (s.service_name || '') + ' — ₱' + Number(s.initial_price || 0).toLocaleString() + '</label>' +
                '</div>'
            ).join('');
        } else {
            container.innerHTML = '<small class="text-muted">No services available.</small>';
        }
    } catch (e) {
        container.innerHTML = '<small class="text-muted">Failed to load services.</small>';
    }
}

async function loadArrivalTimeslots(select, row) {
    if (!select || !row) return;
    const date = row.slot_date;
    const doctorId = document.getElementById('arrDoctorSelect')?.value || row.doctor_id || '';
    if (!date) return;

    select.innerHTML += '<option value="">Loading other slots...</option>';
    try {
        let url = '../assets/api/appointments/get_timeslots.php?date=' + encodeURIComponent(date);
        if (doctorId) url += '&doctor_id=' + encodeURIComponent(doctorId);
        const resp = await fetch(url);
        const data = await resp.json();
        select.querySelector('option:last-child').remove();
        if (data.success && Array.isArray(data.slots)) {
            data.slots.forEach(function(slot) {
                if (String(slot.timeslot_id) === String(row.timeslot_id)) return; // skip current
                const opt = document.createElement('option');
                opt.value = slot.timeslot_id;
                opt.textContent = slot.label;
                select.appendChild(opt);
            });
        }
    } catch (e) {
        select.querySelector('option:last-child')?.remove();
    }
}

document.getElementById('arrivedDoctorSelect')?.addEventListener('change', function() {
    // Reload timeslots when doctor changes
    const tsSel = document.getElementById('arrTimeslotSelect');
    if (tsSel && arrModalData.slot_date) {
        tsSel.innerHTML = '<option value="">Keep current time (' + fmtT(arrModalData.start_time) + ' – ' + fmtT(arrModalData.end_time) + ')</option>';
        loadArrivalTimeslots(tsSel, arrModalData);
    }
});

document.getElementById('arrivedConfirmBtn')?.addEventListener('click', async function() {
    const apptId = document.getElementById('arrApptId')?.value;
    const doctorId = document.getElementById('arrDoctorSelect')?.value || '';
    const timeslotId = document.getElementById('arrTimeslotSelect')?.value || '';

    if (!apptId) return;
    if (!doctorId) {
        showArrToast('Please assign a doctor.', 'warning');
        return;
    }

    this.disabled = true;
    try {
        const fd = new FormData();
        fd.append('appointment_id', apptId);
        fd.append('csrf_token', secretaryCsrfToken);
        fd.append('doctor_id', doctorId);
        if (timeslotId) fd.append('timeslot_id', timeslotId);

        const svcChecks = document.querySelectorAll('#arrServiceCheckboxes input[type="checkbox"]:checked');
        svcChecks.forEach(cb => fd.append('selected_service_ids[]', cb.value));

        const resp = await fetch('../assets/api/arrivals/mark_arrived.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            const dot = document.getElementById('secretaryArrivalsDot');
            if (dot) {
                const count = parseInt(dot.dataset.count || '0', 10);
                const next = Math.max(0, count - 1);
                dot.dataset.count = next;
                dot.classList.toggle('is-active', next > 0);
            }
            bootstrap.Modal.getInstance(document.getElementById('arrivedModal'))?.hide();
            showArrToast(data.message, 'success');
            loadArrivalSchedule(currentArrivalDate);
        } else {
            showArrToast(data.message || 'Failed', 'danger');
        }
    } catch (e) {
        showArrToast('Error: ' + e.message, 'danger');
    } finally {
        this.disabled = false;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Hide arrivals dot when page loads
    const arrDot = document.getElementById('secretaryArrivalsDot');
    if (arrDot) arrDot.classList.remove('is-active');

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

<!-- Arrived Modal -->
<div class="modal fade" id="arrivedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-person-check"></i> Confirm Arrival</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="arrApptId">
                <div class="mb-3">
                    <span class="text-muted small">Patient</span>
                    <div class="fw-semibold" id="arrPatientName">—</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Assign Doctor *</label>
                    <select id="arrDoctorSelect" class="form-select" required>
                        <option value="">-- Select Doctor --</option>
                        <?php foreach ($doctors_list as $d): ?>
                            <option value="<?php echo (int)$d['user_id']; ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Current: <span id="arrCurrentDoctor" class="fw-medium">—</span></div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Services <small class="text-muted fw-normal">(optional)</small></label>
                    <div id="arrServiceCheckboxes" style="max-height:150px;overflow-y:auto;">
                        <small class="text-muted">Loading services...</small>
                    </div>
                </div>

                <div class="mb-3" id="arrTimeslotWrap">
                    <label class="form-label fw-semibold">Move Time Slot <small class="text-muted fw-normal">(optional)</small></label>
                    <select id="arrTimeslotSelect" class="form-select">
                        <option value="">Keep current time</option>
                    </select>
                    <div class="form-text">Leave blank to keep the current scheduled time.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" id="arrivedConfirmBtn">
                    <i class="bi bi-check-circle"></i> Confirm Arrival
                </button>
            </div>
        </div>
    </div>
</div>
