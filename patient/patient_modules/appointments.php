<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Fetch doctors for dropdown
$doctors_list = [];
$doctors_sql = "SELECT user_id, first_name, last_name FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY first_name ASC";
$doctors_result = $conn->query($doctors_sql);
if ($doctors_result) {
    while ($row = $doctors_result->fetch_assoc()) {
        $doctors_list[] = $row;
    }
}

$patient_id = $_SESSION['patient_id'] ?? 0;
?>

<div class="card">
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
                        <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
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

    function showAlert(message, type='info'){
        alertBox.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
    }

    // Fetch available dates for next 30 days and render a simple calendar grid
    fetch('../assets/api/appointments/get_available_dates.php?days=30')
    .then(r => r.json())
    .then(data => {
        const cal = document.getElementById('appointmentCalendar');
        dateSelect.value = '';
        cal.innerHTML = '';
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
                        const dateStr = new Date(year, month, curDay).toISOString().slice(0,10);
                        const item = items.find(it => it.date === dateStr);
                        const dayDiv = document.createElement('div');
                        dayDiv.style.cursor = item && item.available ? 'pointer' : 'default';
                        const dayHeader = document.createElement('div');
                        dayHeader.innerHTML = '<small class="text-muted">' + curDay + '</small>';
                        dayDiv.appendChild(dayHeader);
                        if (item && item.count) {
                            const countDiv = document.createElement('div');
                            countDiv.innerHTML = '<small>' + item.count + ' booked</small>';
                            dayDiv.appendChild(countDiv);
                        }
                        if (item) {
                            if (item.available) {
                                dayDiv.className = item.count ? 'has-bookings' : 'selectable-date';
                                dayDiv.addEventListener('click', function() {
                                    dateSelect.value = dateStr;
                                    // clear active
                                    document.querySelectorAll('#appointmentCalendar .selected').forEach(el => el.classList.remove('selected'));
                                    dayDiv.classList.add('selected');
                                });
                                dayDiv.title = item.count ? (item.count + ' booked') : '';
                            } else {
                                dayDiv.className = 'full-day text-muted';
                                dayDiv.title = (item.count ? (item.count + ' booked') : '') + ' — FULL';
                            }
                        } else {
                            dayDiv.className = 'text-muted';
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
            s.textContent = '\n#appointmentCalendar .selected{background:#0d6efd;color:#fff;padding:2px;border-radius:4px;}\n#appointmentCalendar .card{width:100%;}\n#appointmentCalendar .text-muted{opacity:0.6;}\n#appointmentCalendar .selectable-date{background:#e9f7ef;}\n#appointmentCalendar .has-bookings{background:#fff3cd;}\n#appointmentCalendar .full-day{background:#f8d7da;color:#721c24;}\n.calendar-viewport { position: relative; }\n.calendar-track { width:100%; }\n.calendar-card { will-change: transform, opacity; flex:0 0 100%; }\n.calendar-card table td { vertical-align: top; }\n';
            document.head.appendChild(s);
        }
    })
    .catch(err => {
        console.error(err);
        const cal = document.getElementById('appointmentCalendar');
        if (cal) cal.innerHTML = '<div class="text-danger">Error loading dates</div>';
        showAlert('Error loading available dates.', 'danger');
    });

    // Form submit
    const form = document.getElementById('createApptForm');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
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
