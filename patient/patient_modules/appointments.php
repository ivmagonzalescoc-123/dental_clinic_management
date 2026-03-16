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

// Fetch appointment history for this patient
$appointment_history = [];
if ($patient_id) {
    $historySql = "SELECT a.appointment_id, a.appointment_date, a.reason, a.status, a.created_at,
                          u.first_name AS doctor_first_name, u.last_name AS doctor_last_name,
                          t.start_time, t.end_time
                   FROM appointments a
                   LEFT JOIN users u ON u.user_id = a.user_id
                   LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
                   WHERE a.patient_id = ?
                   ORDER BY a.appointment_date DESC, a.appointment_id DESC
                   LIMIT 100";
    $historyStmt = $conn->prepare($historySql);
    if ($historyStmt) {
        $historyStmt->bind_param('i', $patient_id);
        $historyStmt->execute();
        $historyRes = $historyStmt->get_result();
        if ($historyRes) {
            while ($row = $historyRes->fetch_assoc()) {
                $appointment_history[] = $row;
            }
        }
        $historyStmt->close();
    }
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<style>
.patient-appt-card .card-header {
    background: linear-gradient(135deg, #0f766e 0%, #14532d 100%);
    color: #fff;
}
.patient-appt-card .card-header .sub-title {
    font-size: 0.9rem;
    opacity: 0.92;
}
.patient-appt-card .calendar-panel {
    border: 1px solid #dbe7e4;
    border-radius: 14px;
    background: linear-gradient(180deg, #f8fffd 0%, #f2f8f6 100%);
    padding: 14px;
}
.patient-appt-card .selected-date-chip {
    display: inline-flex;
    align-items: center;
    font-weight: 600;
    font-size: 0.85rem;
    background: #d1fae5;
    color: #065f46;
    border-radius: 999px;
    padding: 0.35rem 0.8rem;
}
.patient-appt-card .calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 10px;
    font-size: 0.82rem;
    color: #334155;
}
.patient-appt-card .legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.patient-appt-card .legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
}
.patient-appt-card .legend-open { background: #16a34a; }
.patient-appt-card .legend-busy { background: #f59e0b; }
.patient-appt-card .legend-blocked { background: #dc2626; }
.patient-appt-card #appointmentCalendar .fc-toolbar-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}
.patient-appt-card #appointmentCalendar .fc-button {
    border-radius: 10px;
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-open:hover,
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-busy:hover {
    transform: translateY(-1px);
    box-shadow: inset 0 0 0 2px rgba(15, 118, 110, 0.35);
    cursor: pointer;
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-open .fc-daygrid-day-frame {
    background: linear-gradient(180deg, rgba(22, 163, 74, 0.14), rgba(22, 163, 74, 0.05));
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-busy .fc-daygrid-day-frame {
    background: linear-gradient(180deg, rgba(245, 158, 11, 0.16), rgba(245, 158, 11, 0.07));
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-closed .fc-daygrid-day-frame,
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-disabled .fc-daygrid-day-frame {
    background: linear-gradient(180deg, rgba(220, 38, 38, 0.15), rgba(220, 38, 38, 0.06));
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-selected {
    box-shadow: inset 0 0 0 2px #0f766e;
}
.patient-appt-card #appointmentCalendar .appt-day-badge {
    display: inline-flex;
    margin-top: 4px;
    padding: 2px 6px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    background: #e2e8f0;
    color: #334155;
}
.patient-appt-card #appointmentCalendar .appt-day-badge.state-open {
    background: #dcfce7;
    color: #166534;
}
.patient-appt-card #appointmentCalendar .appt-day-badge.state-busy {
    background: #fef3c7;
    color: #92400e;
}
.patient-appt-card #appointmentCalendar .appt-day-badge.state-closed {
    background: #fee2e2;
    color: #991b1b;
}
.patient-appt-card .appt-tab-nav .nav-link {
    border-radius: 999px;
    font-weight: 600;
    color: #0f172a;
}
.patient-appt-card .appt-tab-nav .nav-link.active {
    background: #5f43b2;
    color: #fff;
}
.patient-appt-card #appointmentCalendar .fc-button-primary {
    background-color: #5f43b2;
    border-color: #5f43b2;
}
.patient-appt-card #appointmentCalendar .fc-button-primary:hover,
.patient-appt-card #appointmentCalendar .fc-button-primary:focus,
.patient-appt-card #appointmentCalendar .fc-button-primary:active,
.patient-appt-card #appointmentCalendar .fc-button-primary.fc-button-active {
    background-color: #4f3796;
    border-color: #4f3796;
}
.patient-appt-card .history-table-wrap {
    border: 1px solid #dbe7e4;
    border-radius: 12px;
    overflow: hidden;
}
.patient-appt-card .history-table-wrap table {
    margin-bottom: 0;
}
.patient-appt-card .history-table-wrap thead th {
    background: #f0fdf4;
    color: #14532d;
    border-bottom: 1px solid #dbe7e4;
    white-space: nowrap;
}
.patient-appt-card .history-empty {
    color: #64748b;
    font-style: italic;
}
@media (max-width: 767px) {
    .patient-appt-card .calendar-panel {
        padding: 10px;
    }
}
</style>

<div class="card border-0 patient-appt-card">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="card-title mb-1">Make an Appointment</h5>
                <div class="sub-title">Select your preferred doctor, then choose an available date and time slot.</div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <ul class="nav nav-pills appt-tab-nav" id="apptTabNav" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" type="button" id="apptTabBook">Create Appointment</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" id="apptTabHistory">Appointment History</button>
                </li>
            </ul>
            <small class="text-muted">Track your appointment request status in history.</small>
        </div>

        <div id="apptBookPane">
            <div id="apptAlert"></div>
            <form id="createApptForm">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="calendar-panel">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Availability Calendar</h6>
                                <span id="selectedDateLabel" class="selected-date-chip">No date selected</span>
                            </div>
                            <input type="hidden" id="appointment_date" name="appointment_date" required>
                            <div id="appointmentCalendar" aria-live="polite"></div>
                            <div class="calendar-legend">
                                <span class="legend-item"><span class="legend-dot legend-open"></span>Available</span>
                                <span class="legend-item"><span class="legend-dot legend-busy"></span>With bookings</span>
                                <span class="legend-item"><span class="legend-dot legend-blocked"></span>Unavailable / Closed</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
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
                            <select class="form-select" id="timeslot_id" name="timeslot_id" required disabled>
                                <option value="">-- Select a 20-minute slot --</option>
                            </select>
                            <div class="form-text">20-minute slots only: 8:00-12:00 and 1:00-5:00.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reason for Appointment</label>
                            <textarea class="form-control" id="reason" name="reason" rows="4" placeholder="Describe the reason" required></textarea>
                        </div>

                        <input type="hidden" name="patient_id" value="<?php echo (int)$patient_id; ?>">
                        <input type="hidden" name="is_online_appointment" value="0">

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-calendar-check"></i> Request Appointment
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="apptHistoryPane" class="d-none">
            <div class="history-table-wrap table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Appointment #</th>
                            <th>Date</th>
                            <th>Doctor</th>
                            <th>Time Slot</th>
                            <th>Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="patientApptHistoryBody">
                        <?php if (empty($appointment_history)): ?>
                            <tr id="patientApptHistoryEmpty">
                                <td colspan="6" class="text-center py-4 history-empty">No appointment history yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($appointment_history as $history): ?>
                                <?php
                                $statusKey = strtolower((string)($history['status'] ?? 'scheduled'));
                                $statusLabel = ucwords(str_replace('_', ' ', $statusKey));
                                $statusBadge = 'secondary';
                                if ($statusKey === 'confirmed') {
                                    $statusBadge = 'primary';
                                } elseif ($statusKey === 'checked_in') {
                                    $statusBadge = 'info';
                                } elseif ($statusKey === 'in_progress') {
                                    $statusBadge = 'warning';
                                } elseif ($statusKey === 'completed') {
                                    $statusBadge = 'success';
                                } elseif ($statusKey === 'cancelled' || $statusKey === 'no_show') {
                                    $statusBadge = 'danger';
                                }

                                $doctorName = trim((string)($history['doctor_first_name'] ?? '') . ' ' . (string)($history['doctor_last_name'] ?? ''));
                                if ($doctorName === '') {
                                    $doctorName = 'To be assigned';
                                }

                                $slotLabel = 'TBD';
                                if (!empty($history['start_time']) && !empty($history['end_time'])) {
                                    $slotLabel = date('g:i A', strtotime((string)$history['start_time'])) . ' - ' . date('g:i A', strtotime((string)$history['end_time']));
                                }
                                ?>
                                <tr>
                                    <td>#<?php echo (int)($history['appointment_id'] ?? 0); ?></td>
                                    <td><?php echo !empty($history['appointment_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$history['appointment_date']))) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($doctorName); ?></td>
                                    <td><?php echo htmlspecialchars($slotLabel); ?></td>
                                    <td><?php echo htmlspecialchars((string)($history['reason'] ?? '')); ?></td>
                                    <td><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
            <div id="bookingResultToast" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex align-items-center">
                    <div class="toast-body" id="bookingResultToastBody"></div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const DAYS_AHEAD = 150;
    const dateSelect = document.getElementById('appointment_date');
    const alertBox = document.getElementById('apptAlert');
    const calendarEl = document.getElementById('appointmentCalendar');
    const doctorSelect = document.getElementById('user_id');
    const timeslotWrap = document.getElementById('patientTimeslotWrap');
    const timeslotSelect = document.getElementById('timeslot_id');
    const selectedDateLabel = document.getElementById('selectedDateLabel');
    const bookTabBtn = document.getElementById('apptTabBook');
    const historyTabBtn = document.getElementById('apptTabHistory');
    const bookPane = document.getElementById('apptBookPane');
    const historyPane = document.getElementById('apptHistoryPane');
    const historyBody = document.getElementById('patientApptHistoryBody');
    const historyEmptyRow = document.getElementById('patientApptHistoryEmpty');
    const bookingToastEl = document.getElementById('bookingResultToast');
    const bookingToastBodyEl = document.getElementById('bookingResultToastBody');

    let appointmentCalendar = null;
    let availabilityByDate = {};
    let selectedDayEl = null;

    function toIsoDateLocal(dateObj) {
        const year = dateObj.getFullYear();
        const month = String(dateObj.getMonth() + 1).padStart(2, '0');
        const day = String(dateObj.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function addDaysIso(isoDate, days) {
        const d = new Date(isoDate + 'T00:00:00');
        d.setDate(d.getDate() + days);
        return toIsoDateLocal(d);
    }

    const todayIso = toIsoDateLocal(new Date());

    function showAlert(message, type = 'info') {
        if (!alertBox) return;
        alertBox.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }

    function showBookingToast(message, type) {
        if (!bookingToastEl || !bookingToastBodyEl || typeof bootstrap === 'undefined') {
            showAlert(message, type === 'success' ? 'success' : 'danger');
            return;
        }

        const toastType = String(type || 'success');
        bookingToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-dark');
        if (toastType === 'success') {
            bookingToastEl.classList.add('bg-success');
        } else if (toastType === 'warning') {
            bookingToastEl.classList.add('bg-warning', 'text-dark');
        } else {
            bookingToastEl.classList.add('bg-danger');
        }

        bookingToastBodyEl.textContent = message;
        const toast = bootstrap.Toast.getOrCreateInstance(bookingToastEl, { autohide: true, delay: 2000 });
        toast.show();
    }

    function switchAppointmentsTab(tabName) {
        const showHistory = tabName === 'history';
        if (bookTabBtn) {
            bookTabBtn.classList.toggle('active', !showHistory);
        }
        if (historyTabBtn) {
            historyTabBtn.classList.toggle('active', showHistory);
        }
        if (bookPane) {
            bookPane.classList.toggle('d-none', showHistory);
        }
        if (historyPane) {
            historyPane.classList.toggle('d-none', !showHistory);
        }
    }

    function statusBadgeClass(status) {
        const key = String(status || '').toLowerCase();
        if (key === 'confirmed') return 'primary';
        if (key === 'checked_in') return 'info';
        if (key === 'in_progress') return 'warning';
        if (key === 'completed') return 'success';
        if (key === 'cancelled' || key === 'no_show') return 'danger';
        return 'secondary';
    }

    function statusLabel(status) {
        return String(status || 'scheduled').replace(/_/g, ' ').replace(/\b\w/g, function(ch) {
            return ch.toUpperCase();
        });
    }

    function displayDateLabel(isoDate) {
        if (!isoDate) return 'N/A';
        const d = new Date(String(isoDate) + 'T00:00:00');
        return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' });
    }

    function appendHistoryRow(payload) {
        if (!historyBody || !payload) return;
        if (historyEmptyRow && historyEmptyRow.parentNode) {
            historyEmptyRow.parentNode.removeChild(historyEmptyRow);
        }

        const tr = document.createElement('tr');

        const idCell = document.createElement('td');
        idCell.textContent = '#' + String(payload.appointmentId || 0);
        tr.appendChild(idCell);

        const dateCell = document.createElement('td');
        dateCell.textContent = displayDateLabel(payload.date);
        tr.appendChild(dateCell);

        const doctorCell = document.createElement('td');
        doctorCell.textContent = String(payload.doctor || 'To be assigned');
        tr.appendChild(doctorCell);

        const slotCell = document.createElement('td');
        slotCell.textContent = String(payload.slot || 'TBD');
        tr.appendChild(slotCell);

        const reasonCell = document.createElement('td');
        reasonCell.textContent = String(payload.reason || '');
        tr.appendChild(reasonCell);

        const statusCell = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'badge bg-' + statusBadgeClass(payload.status);
        badge.textContent = statusLabel(payload.status);
        statusCell.appendChild(badge);
        tr.appendChild(statusCell);

        if (historyBody.firstChild) {
            historyBody.insertBefore(tr, historyBody.firstChild);
        } else {
            historyBody.appendChild(tr);
        }
    }

    function formatDisplayDate(isoDate) {
        if (!isoDate) return 'No date selected';
        const d = new Date(isoDate + 'T00:00:00');
        return d.toLocaleDateString(undefined, { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' });
    }

    function updateSelectedDateLabel(isoDate) {
        if (selectedDateLabel) {
            selectedDateLabel.textContent = formatDisplayDate(isoDate);
        }
    }

    function resetTimeslots() {
        if (!timeslotWrap || !timeslotSelect) return;
        timeslotWrap.style.display = 'none';
        timeslotSelect.disabled = true;
        timeslotSelect.required = false;
        timeslotSelect.value = '';
        timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';
    }

    function clearSelectedDate() {
        if (dateSelect) dateSelect.value = '';
        updateSelectedDateLabel('');
        if (selectedDayEl) {
            selectedDayEl.classList.remove('appt-day-selected');
            selectedDayEl = null;
        }
        resetTimeslots();
    }

    function isDateSelectable(dateStr) {
        if (!dateStr || dateStr < todayIso) {
            return false;
        }
        const dateObj = new Date(dateStr + 'T00:00:00');
        if (dateObj.getDay() === 0) {
            return false;
        }
        const item = availabilityByDate[dateStr];
        return !!(item && item.available);
    }

    function getBlockedReason(dateStr) {
        const item = availabilityByDate[dateStr];
        if (!item) {
            return 'No schedule available for this date.';
        }
        const reason = String(item.reason || '').toLowerCase();
        if (reason.includes('leave')) {
            return 'Doctor is on leave for this date.';
        }
        if (reason.includes('schedule')) {
            return 'Doctor has no available schedule on this date.';
        }
        return 'This date is not available for booking.';
    }

    function decorateDayCell(arg) {
        const dateStr = toIsoDateLocal(arg.date);
        const item = availabilityByDate[dateStr] || null;
        const frame = arg.el.querySelector('.fc-daygrid-day-frame');
        if (!frame) return;

        arg.el.classList.remove('appt-day-open', 'appt-day-busy', 'appt-day-closed', 'appt-day-disabled', 'appt-day-selected');

        const existingBadge = frame.querySelector('.appt-day-badge');
        if (existingBadge) existingBadge.remove();

        const badge = document.createElement('span');
        badge.className = 'appt-day-badge';

        if (dateStr < todayIso) {
            arg.el.classList.add('appt-day-disabled');
            badge.classList.add('state-closed');
            badge.textContent = 'Past';
        } else if (arg.date.getDay() === 0) {
            arg.el.classList.add('appt-day-closed');
            badge.classList.add('state-closed');
            badge.textContent = 'Closed';
        } else if (item && item.available) {
            if ((item.count || 0) > 0) {
                arg.el.classList.add('appt-day-busy');
                badge.classList.add('state-busy');
                badge.textContent = item.count + ' booked';
            } else {
                arg.el.classList.add('appt-day-open');
                badge.classList.add('state-open');
                badge.textContent = 'Open';
            }
        } else {
            arg.el.classList.add('appt-day-closed');
            badge.classList.add('state-closed');
            const reason = String(item && item.reason ? item.reason : '').toLowerCase();
            if (reason.includes('leave')) {
                badge.textContent = 'On leave';
            } else if (reason.includes('schedule')) {
                badge.textContent = 'No schedule';
            } else {
                badge.textContent = 'Unavailable';
            }
        }

        if (dateSelect && dateSelect.value === dateStr) {
            arg.el.classList.add('appt-day-selected');
            selectedDayEl = arg.el;
        }

        frame.appendChild(badge);
    }

    function renderCalendar() {
        if (!calendarEl) return;

        if (appointmentCalendar) {
            appointmentCalendar.destroy();
            appointmentCalendar = null;
            selectedDayEl = null;
        }

        if (typeof FullCalendar === 'undefined') {
            calendarEl.innerHTML = '<div class="text-danger small">Calendar library failed to load.</div>';
            return;
        }

        appointmentCalendar = new FullCalendar.Calendar(calendarEl, {
            themeSystem: 'bootstrap5',
            initialView: 'dayGridMonth',
            fixedWeekCount: false,
            showNonCurrentDates: false,
            height: 'auto',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: ''
            },
            validRange: {
                start: todayIso,
                end: addDaysIso(todayIso, DAYS_AHEAD + 1)
            },
            dateClick: function(info) {
                const dateStr = info.dateStr;
                if (!isDateSelectable(dateStr)) {
                    showAlert(getBlockedReason(dateStr), 'warning');
                    return;
                }

                if (selectedDayEl) {
                    selectedDayEl.classList.remove('appt-day-selected');
                }

                selectedDayEl = info.dayEl;
                selectedDayEl.classList.add('appt-day-selected');

                if (dateSelect) {
                    dateSelect.value = dateStr;
                }
                updateSelectedDateLabel(dateStr);
                loadTimeslots();
            },
            dayCellDidMount: decorateDayCell
        });

        appointmentCalendar.render();
    }

    async function loadDates() {
        clearSelectedDate();
        try {
            const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
            let url = '../assets/api/appointments/get_available_dates.php?days=' + encodeURIComponent(String(DAYS_AHEAD));
            if (doctorId) {
                url += '&user_id=' + encodeURIComponent(doctorId);
            }

            const r = await fetch(url);
            if (!r.ok) {
                throw new Error('Network response was not ok');
            }

            const data = await r.json();
            if (!data.success || !Array.isArray(data.dates)) {
                availabilityByDate = {};
                renderCalendar();
                showAlert('Unable to load available dates.', 'warning');
                return;
            }

            availabilityByDate = {};
            data.dates.forEach(function(item) {
                if (!item || !item.date) return;
                availabilityByDate[String(item.date)] = {
                    available: !!item.available,
                    count: parseInt(item.count || 0, 10) || 0,
                    reason: String(item.reason || '')
                };
            });

            renderCalendar();
        } catch (err) {
            console.error(err);
            availabilityByDate = {};
            renderCalendar();
            showAlert('Error loading available dates.', 'danger');
        }
    }

    async function loadTimeslots() {
        const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
        const apptDate = dateSelect ? String(dateSelect.value || '').trim() : '';
        if (!timeslotWrap || !timeslotSelect) return;

        timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';
        if (!doctorId || !apptDate) {
            resetTimeslots();
            return;
        }

        timeslotWrap.style.display = '';
        timeslotSelect.disabled = false;
        timeslotSelect.required = true;
        timeslotSelect.innerHTML = '<option value="">Loading time slots...</option>';

        try {
            const r = await fetch('../assets/api/appointments/get_timeslots.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(apptDate));
            const data = await r.json();
            timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';

            if (!data.success || !Array.isArray(data.slots) || data.slots.length === 0) {
                timeslotSelect.innerHTML = '<option value="">No available timeslots for this date</option>';
                return;
            }

            data.slots.forEach(function(slot) {
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

    const initialTab = new URLSearchParams(window.location.search).get('appt_tab') === 'history' ? 'history' : 'book';
    switchAppointmentsTab(initialTab);

    if (bookTabBtn) {
        bookTabBtn.addEventListener('click', function() {
            switchAppointmentsTab('book');
        });
    }
    if (historyTabBtn) {
        historyTabBtn.addEventListener('click', function() {
            switchAppointmentsTab('history');
        });
    }

    if (doctorSelect) {
        doctorSelect.addEventListener('change', function() {
            loadDates();
        });
    }

    const form = document.getElementById('createApptForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const tsId = timeslotSelect ? String(timeslotSelect.value || '').trim() : '';
            if (!tsId) {
                showAlert('Please select a 20-minute timeslot before submitting.', 'warning');
                showBookingToast('Please select a time slot before booking.', 'warning');
                return;
            }

            const selectedDoctor = doctorSelect && doctorSelect.selectedOptions.length > 0
                ? String(doctorSelect.selectedOptions[0].textContent || '').trim()
                : 'To be assigned';
            const selectedSlot = timeslotSelect && timeslotSelect.selectedOptions.length > 0
                ? String(timeslotSelect.selectedOptions[0].textContent || '').trim()
                : 'TBD';
            const selectedReason = document.getElementById('reason') ? String(document.getElementById('reason').value || '').trim() : '';
            const selectedDate = dateSelect ? String(dateSelect.value || '') : '';

            const formData = new FormData(form);
            fetch('../assets/api/appointments/create_appointment.php', {
                method: 'POST',
                body: formData
            }).then(function(r) {
                return r.json();
            }).then(function(resp) {
                if (resp.success) {
                    appendHistoryRow({
                        appointmentId: resp.appointment_id,
                        date: selectedDate,
                        doctor: selectedDoctor,
                        slot: selectedSlot,
                        reason: selectedReason,
                        status: resp.status || 'scheduled'
                    });
                    showAlert(resp.message || 'Appointment created successfully.', 'success');
                    showBookingToast(resp.message || 'Appointment created successfully.', 'success');

                    form.reset();
                    clearSelectedDate();
                    loadDates();
                    switchAppointmentsTab('history');
                } else {
                    const failType = resp.warning ? 'warning' : 'danger';
                    showAlert(resp.message || 'Failed to create appointment.', 'warning');
                    showBookingToast(resp.message || 'Failed to create appointment.', failType);
                }
            }).catch(function(err) {
                console.error(err);
                showAlert('Server error creating appointment.', 'danger');
                showBookingToast('Server error creating appointment.', 'danger');
            });
        });
    }
});
</script>
