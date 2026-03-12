<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
security_ensure_work_type_column($conn);

// Fetch doctors for dropdown
$doctors_list = [];
$doctors_sql = "SELECT user_id, first_name, last_name, COALESCE(work_type, 'regular') AS work_type FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY first_name ASC";
$doctors_result = $conn->query($doctors_sql);
if ($doctors_result) {
    while ($row = $doctors_result->fetch_assoc()) {
        $doctors_list[] = $row;
    }
}

$patient_id = $_SESSION['patient_id'] ?? 0;

// Fetch recent billing history for this patient
$billings = [];
if ($patient_id) {
    $billSql = "SELECT b.billing_id, b.appointment_id, b.total_amount, b.created_at, a.appointment_date
                FROM billing b
                INNER JOIN appointments a ON b.appointment_id = a.appointment_id
                WHERE a.patient_id = " . (int)$patient_id . "
                ORDER BY b.created_at DESC LIMIT 50";
    $billRes = $conn->query($billSql);
    if ($billRes) {
        while ($row = $billRes->fetch_assoc()) $billings[] = $row;
    }
}
?>

<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Make an Appointment</h5>
    </div>
    <div class="card-body">
        <div id="apptAlert"></div>

        <form id="createApptForm">
            <div class="mb-3">
                <label class="form-label">Select Date</label>
                <input type="hidden" id="appointment_date" name="appointment_date" required>
                <div id="appointmentCalendar" class="d-flex flex-wrap gap-2" aria-live="polite">Loading available dates...</div>
                <div class="form-text">Click a date to select. Fully booked dates are disabled.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Choose Doctor</label>
                <select class="form-select" id="user_id" name="user_id" required>
                    <option value="">-- Select Doctor --</option>
                    <?php foreach ($doctors_list as $d): ?>
                        <?php $doctorType = ((string)($d['work_type'] ?? 'regular') === 'part_time') ? 'Part-time' : 'Regular'; ?>
                        <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name'] . ' (' . $doctorType . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3" id="patientTimeslotWrap" style="display:none;">
                <label class="form-label">Choose Time Slot</label>
                <select class="form-select" id="timeslot_id" name="timeslot_id" required>
                    <option value="">-- Select a 20-minute slot --</option>
                </select>
                <div class="form-text">20-minute slots only: 8:00-12:00 and 1:00-5:00.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Reason for Appointment</label>
                <textarea class="form-control" id="reason" name="reason" rows="3" placeholder="Describe the reason" required></textarea>
            </div>

            <input type="hidden" name="patient_id" value="<?php echo (int)$patient_id; ?>">
            <input type="hidden" name="is_online_appointment" value="0">

            <button type="submit" class="btn btn-primary">Request Appointment</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateSelect = document.getElementById('appointment_date');
    const alertBox = document.getElementById('apptAlert');
    const doctorSelect = document.getElementById('user_id');
    const timeslotWrap = document.getElementById('patientTimeslotWrap');
    const timeslotSelect = document.getElementById('timeslot_id');

    function showAlert(message, type='info'){
        alertBox.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
    }

    // Fetch available dates for next 30 days and render a simple calendar grid
    async function loadDates(){
        const cal = document.getElementById('appointmentCalendar');
        dateSelect.value = '';
        if (timeslotWrap && timeslotSelect) {
            timeslotWrap.style.display = 'none';
            timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';
        }
        cal.innerHTML = '';
        try {
            const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
            const qs = doctorId ? ('?days=30&user_id=' + encodeURIComponent(doctorId)) : '?days=30';
            const r = await fetch('../assets/api/appointments/get_available_dates.php' + qs);
            if (!r.ok) throw new Error('Network response was not ok');
            const data = await r.json();
            if (!data.success || !Array.isArray(data.dates)) {
                cal.innerHTML = '<div class="text-muted">Unable to load dates</div>';
                showAlert('Unable to load available dates.', 'warning');
                return;
            }

            const dates = data.dates || [];
        if (dates.length === 0) {
            cal.innerHTML = '<div class="text-muted">No available dates in range</div>';
            showAlert('No available dates found. Please try again later or contact the clinic.', 'warning');
            return;
        }

        // Render month-style calendars (single-month view with prev/next) with slide animation
        // Build mapping of monthKey -> items; always include next 5 months
        const byMonth = {};
        const monthsToShow = 5;
        const startDate = new Date();
        for (let m = 0; m < monthsToShow; m++) {
            const d = new Date(startDate.getFullYear(), startDate.getMonth() + m, 1);
            const key = d.getFullYear() + '-' + (d.getMonth() + 1);
            byMonth[key] = [];
        }
        // populate items into months (if present)
        dates.forEach(d => {
            const dt = new Date(d.date + 'T00:00:00');
            const key = dt.getFullYear() + '-' + (dt.getMonth() + 1);
            if (!byMonth[key]) byMonth[key] = [];
            byMonth[key].push(d);
        });

        // prepare ordered months and render only one at a time
        const monthKeys = Object.keys(byMonth).sort((a,b) => {
            const pa = a.split('-').map(Number); const pb = b.split('-').map(Number);
            return (pa[0] - pb[0]) || (pa[1] - pb[1]);
        });
        let currentIndex = 0;

        // create a viewport and a sliding track (flex) for reliable sliding
        const viewport = document.createElement('div');
        viewport.className = 'calendar-viewport';
        viewport.style.position = 'relative';
        viewport.style.overflow = 'hidden';
        viewport.style.minHeight = '360px';
        cal.innerHTML = '';
        cal.appendChild(viewport);

        const track = document.createElement('div');
        track.className = 'calendar-track';
        track.style.display = 'flex';
        track.style.transition = 'transform 320ms ease';
        // make track wide enough for all months
        track.style.width = (monthKeys.length * 100) + '%';
        viewport.appendChild(track);

        function createMonthCard(monthKey, items) {
            const parts = monthKey.split('-');
            const year = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10) - 1;
            const monthStart = new Date(year, month, 1);
            const monthName = monthStart.toLocaleString(undefined, { month: 'long' });

            const card = document.createElement('div');
            card.className = 'calendar-card card p-2 mb-2';
            const pct = 100 / monthKeys.length;
            card.style.flex = '0 0 ' + pct + '%';
            card.style.boxSizing = 'border-box';

            const hdr = document.createElement('div');
            hdr.className = 'mb-2';
            hdr.innerHTML = '<strong>' + monthName + ' ' + year + '</strong>';
            card.appendChild(hdr);

            const table = document.createElement('table');
            table.className = 'table table-bordered mb-0';
            const thead = document.createElement('thead');
            const tr = document.createElement('tr');
            ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(w => { const th = document.createElement('th'); th.style.width = '14%'; th.style.textAlign = 'center'; th.textContent = w; tr.appendChild(th); });
            thead.appendChild(tr);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');

            const firstWeekday = monthStart.getDay();
            let curDay = 1 - firstWeekday;
            while (curDay <= 31) {
                const row = document.createElement('tr');
                let emptyRow = true;
                for (let wd = 0; wd < 7; wd++, curDay++) {
                    const cell = document.createElement('td');
                    cell.style.height = '60px';
                    cell.style.verticalAlign = 'top';
                    if (curDay < 1 || curDay > new Date(year, month + 1, 0).getDate()) {
                        cell.innerHTML = '';
                    } else {
                        emptyRow = false;
                                    const dtLocal = new Date(year, month, curDay);
                                    // format date as local YYYY-MM-DD to avoid UTC shift issues
                                    const pad = (n) => String(n).padStart(2,'0');
                                    const dateStr = dtLocal.getFullYear() + '-' + pad(dtLocal.getMonth() + 1) + '-' + pad(dtLocal.getDate());
                                    const weekday = dtLocal.getDay();
                                    const item = items.find(it => it.date === dateStr);
                        const dayDiv = document.createElement('div');
                        // default: make selectable unless explicitly unavailable
                        const dayHeader = document.createElement('div');
                        dayHeader.innerHTML = '<small class="text-muted">' + curDay + '</small>';
                        dayDiv.appendChild(dayHeader);
                        if (item && item.count) {
                            const countDiv = document.createElement('div');
                            countDiv.innerHTML = '<small>' + item.count + ' booked</small>';
                            dayDiv.appendChild(countDiv);
                        }
                        // Sundays: clinic closed — override to non-clickable
                        if (weekday === 0) {
                            dayDiv.className = 'full-day text-muted';
                            dayDiv.title = 'Clinic closed (Sunday)';
                        } else if (item) {
                            if (item.available) {
                                dayDiv.className = item.count ? 'has-bookings' : 'selectable-date';
                                dayDiv.style.cursor = 'pointer';
                                dayDiv.addEventListener('click', function() {
                                    dateSelect.value = dateStr;
                                    // clear active
                                    document.querySelectorAll('#appointmentCalendar .selected').forEach(el => el.classList.remove('selected'));
                                    dayDiv.classList.add('selected');
                                    loadTimeslots();
                                });
                                dayDiv.title = item.count ? (item.count + ' booked') : '';
                            } else {
                                // explicitly unavailable (doctor not present)
                                const badge = document.createElement('div');
                                const reason = String(item.reason || '').toLowerCase();
                                const badgeText = reason.includes('leave') ? 'ON LEAVE' : (reason.includes('schedule') ? 'NO SCHEDULE' : 'FULL');
                                badge.innerHTML = '<small class="text-danger">' + badgeText + '</small>';
                                dayDiv.appendChild(badge);
                                dayDiv.className = 'full-day text-muted';
                                const bookedPart = item.count ? (item.count + ' booked') : '';
                                const reasonPart = item.reason ? String(item.reason) : 'No availability for this date.';
                                dayDiv.title = (bookedPart ? (bookedPart + ' — ') : '') + reasonPart;
                            }
                        } else {
                            // no availability info -> allow selection by default
                            dayDiv.className = 'selectable-date';
                            dayDiv.style.cursor = 'pointer';
                            dayDiv.addEventListener('click', function() {
                                dateSelect.value = dateStr;
                                document.querySelectorAll('#appointmentCalendar .selected').forEach(el => el.classList.remove('selected'));
                                dayDiv.classList.add('selected');
                                loadTimeslots();
                            });
                            dayDiv.title = '';
                        }
                        cell.appendChild(dayDiv);
                    }
                    row.appendChild(cell);
                }
                if (emptyRow) break;
                tbody.appendChild(row);
            }
            table.appendChild(tbody);
            card.appendChild(table);
            track.appendChild(card);
            return card;
        }
        // navigation header
        const header = document.createElement('div');
        header.className = 'd-flex justify-content-between align-items-center mb-2';
        const prevBtn = document.createElement('button'); prevBtn.type='button'; prevBtn.className='btn btn-light btn-sm'; prevBtn.textContent='◀';
        const nextBtn = document.createElement('button'); nextBtn.type='button'; nextBtn.className='btn btn-light btn-sm'; nextBtn.textContent='▶';
        const title = document.createElement('div'); title.className='fw-bold';
        header.appendChild(prevBtn); header.appendChild(title); header.appendChild(nextBtn);

        // attach header above calendar
        cal.parentNode.insertBefore(header, cal);

        function showMonthIndex(i) {
            if (i < 0) i = 0; if (i >= monthKeys.length) i = monthKeys.length - 1;
            currentIndex = i;
            const key = monthKeys[currentIndex];
            const parts = key.split('-');
            const dt = new Date(parseInt(parts[0],10), parseInt(parts[1],10)-1, 1);
            title.textContent = dt.toLocaleString(undefined, { month: 'long', year: 'numeric' });
            // slide track: translate by (index * cardWidth)
            const shift = -currentIndex * (100 / monthKeys.length);
            track.style.transform = 'translateX(' + shift + '%)';
        }

        prevBtn.addEventListener('click', () => showMonthIndex(currentIndex - 1));
        nextBtn.addEventListener('click', () => showMonthIndex(currentIndex + 1));

        // build all month cards on track
        monthKeys.forEach(k => createMonthCard(k, byMonth[k] || []));
        showMonthIndex(0);

        // small CSS for selection highlight and date states and slide animations
        const styleId = 'appt-calendar-style';
        if (!document.getElementById(styleId)) {
            const s = document.createElement('style');
            s.id = styleId;
            s.textContent = '\n#appointmentCalendar .selected{background:#0b5ed7 !important;color:#fff;padding:4px;border-radius:6px;border:2px solid #084298;box-shadow:none;}\n#appointmentCalendar .card{width:100%;}\n#appointmentCalendar .text-muted{opacity:0.65;}\n#appointmentCalendar .selectable-date{background:linear-gradient(180deg,#e9f7ef 0%,#dff6e8 100%);border-radius:6px;}\n#appointmentCalendar .has-bookings{background:linear-gradient(180deg,#fff8dc 0%,#fff3cd 100%);border-radius:6px;}\n#appointmentCalendar .full-day{background:#f8d7da;color:#721c24;font-weight:700;border-radius:6px;}\n#appointmentCalendar .calendar-card table td { border:1px solid #e9ecef; padding:6px; }\n#appointmentCalendar .calendar-card table th { background:#f1f3f5;color:#495057;text-align:center; }\n#appointmentCalendar .calendar-card td div { min-height:48px; display:flex; flex-direction:column; gap:4px; }\n#appointmentCalendar .calendar-card td small { font-weight:700; color:#0d6efd; font-size:1rem; }\n/* hover: no padding/size change; only background and border to avoid enlargement */\n#appointmentCalendar .selectable-date:hover, #appointmentCalendar .has-bookings:hover { background:#0d6efd; color:#fff; border:1px solid rgba(11,94,215,0.9); border-radius:6px; box-shadow:none; }\n#appointmentCalendar .selected small { color:#fff; }\n.calendar-viewport { position: relative; }\n.calendar-track { width:100%; }\n.calendar-card { will-change: transform, opacity; flex:0 0 100%; }\n.calendar-card table td { vertical-align: top; }\n\n/* Services grid */\n.services-grid { display: grid; grid-gap: 12px; }\n.service-card { cursor: pointer; border-radius: 8px; transition: transform .12s ease, box-shadow .12s ease; background: #fff; }\n.service-card:hover { transform: translateY(-6px); box-shadow: 0 8px 18px rgba(16,24,40,0.08); }\n.services-grid .service-card .service-name { color: #0b5ed7; }\n.services-grid .service-card .service-price { color: #6c757d; font-size: .95rem; }\n';
            document.head.appendChild(s);
        }
        } catch (err) {
            console.error(err);
            if (cal) cal.innerHTML = '<div class="text-danger">Error loading dates</div>';
            showAlert('Error loading available dates.', 'danger');
        }
    }

    async function loadTimeslots() {
        const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
        const apptDate = dateSelect ? String(dateSelect.value || '').trim() : '';
        if (!timeslotWrap || !timeslotSelect) return;

        timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';
        if (!doctorId || !apptDate) {
            timeslotWrap.style.display = 'none';
            return;
        }

        timeslotWrap.style.display = '';
        timeslotSelect.innerHTML = '<option value="">Loading time slots...</option>';

        try {
            const r = await fetch('../assets/api/appointments/get_timeslots.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(apptDate));
            const data = await r.json();
            timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';

            if (!data.success || !Array.isArray(data.slots) || data.slots.length === 0) {
                timeslotSelect.innerHTML = '<option value="">No available timeslots for this date</option>';
                return;
            }

            data.slots.forEach(slot => {
                const opt = document.createElement('option');
                opt.value = String(slot.timeslot_id);
                opt.textContent = String(slot.label || 'Slot');
                timeslotSelect.appendChild(opt);
            });
        } catch (err) {
            console.error(err);
            timeslotSelect.innerHTML = '<option value="">Unable to load timeslots</option>';
        }
    }

    loadDates();

    if (doctorSelect) {
        doctorSelect.addEventListener('change', function() {
            loadDates();
            loadTimeslots();
        });
    }

    // Form submit
    const form = document.getElementById('createApptForm');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const tsId = timeslotSelect ? String(timeslotSelect.value || '').trim() : '';
        if (!tsId) {
            showAlert('Please select a 20-minute timeslot before submitting.', 'warning');
            return;
        }
        const formData = new FormData(form);

        fetch('../assets/api/appointments/create_appointment.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json())
        .then(resp => {
            if (resp.success) {
                // Redirect to confirmation page with appointment id (and queue number if present)
                const apptId = resp.appointment_id;
                const queue = resp.queue_number ? ('&queue_number=' + resp.queue_number) : '';
                window.location.href = 'patient.php?page=appointment_confirmation&appointment_id=' + apptId + queue;
            } else {
                showAlert(resp.message || 'Failed to create appointment.', 'warning');
            }
        }).catch(err => {
            console.error(err);
            showAlert('Server error creating appointment.', 'danger');
        });
    });
});
</script>
