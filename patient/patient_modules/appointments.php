<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
security_ensure_work_type_column($conn);

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

// Ensure patient reschedule requests table exists.
$conn->query("CREATE TABLE IF NOT EXISTS appointment_reschedule_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    requested_doctor_id INT NULL,
    requested_date DATE NOT NULL,
    requested_timeslot_id INT NOT NULL,
    request_note VARCHAR(500) NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    review_note VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_arr_status_created (status, created_at),
    KEY idx_arr_appointment_status (appointment_id, status),
    KEY idx_arr_patient_status (patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Fetch appointment history for this patient
$appointment_history = [];
if ($patient_id) {
        $historySql = "SELECT a.appointment_id, a.appointment_date, a.reason, a.status, a.created_at,
                                                    a.user_id,
                                                    a.timeslot_id,
                          u.first_name AS doctor_first_name, u.last_name AS doctor_last_name,
                                                    t.start_time, t.end_time,
                                                    (
                                                            SELECT COUNT(*)
                                                            FROM appointment_reschedule_requests rr
                                                            WHERE rr.appointment_id = a.appointment_id
                                                                AND rr.status = 'pending'
                                                    ) AS pending_reschedule_count
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

<style>
.patient-appt-card .card-header {
    background: #1173c1;
    color: #fff;
}
.patient-appt-card .card-header .sub-title {
    font-size: 0.9rem;
    opacity: 0.92;
}
.patient-appt-card .calendar-panel {
    border: 1px solid #dbe7e4;
    border-radius: 14px;
    background: #f4f8ff;
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
    background: rgba(22, 163, 74, 0.1);
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-busy .fc-daygrid-day-frame {
    background: rgba(245, 158, 11, 0.12);
}
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-closed .fc-daygrid-day-frame,
.patient-appt-card #appointmentCalendar .fc-daygrid-day.appt-day-disabled .fc-daygrid-day-frame {
    background: rgba(220, 38, 38, 0.12);
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
    color: #334155;
}
.patient-appt-card #appointmentCalendar .appt-day-badge.state-open {
    color: #166534;
}
.patient-appt-card #appointmentCalendar .appt-day-badge.state-busy {
    color: #92400e;
}
.patient-appt-card #appointmentCalendar .appt-day-badge.state-closed {
    color: #991b1b;
}
.patient-appt-card .appt-tab-nav .nav-link {
    border-radius: 999px;
    font-weight: 600;
    color: #0f172a;
}
.patient-appt-card .appt-tab-nav .nav-link.active {
    background: #1173c1;
    color: #fff;
}
.patient-appt-card #appointmentCalendar .fc-button-primary {
    background-color: transparent;
    background-image: none;
    border-color: transparent;
    color: #000;
    box-shadow: none;
}
.patient-appt-card #appointmentCalendar .fc-button-primary:hover,
.patient-appt-card #appointmentCalendar .fc-button-primary:focus,
.patient-appt-card #appointmentCalendar .fc-button-primary:active,
.patient-appt-card #appointmentCalendar .fc-button-primary.fc-button-active {
    background-color: transparent;
    background-image: none;
    border-color: transparent;
    color: #000;
    box-shadow: none;
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
.patient-appt-history-search-toolbar {
    flex-wrap: wrap;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 0.85rem;
    gap: 0.5rem 0.75rem;
}
.patient-appt-history-search-wrap {
    width: min(340px, 100%);
    flex: 1 1 220px;
}
.patient-appt-history-search-meta {
    font-size: 0.85rem;
    color: #64748b;
    flex: 1 1 100%;
}
@media (min-width: 576px) {
    .patient-appt-history-search-meta {
        flex: 0 0 auto;
        margin-left: auto;
    }
}
.patient-appt-booking-row {
    align-items: flex-start;
}
.patient-appt-step {
    border-left: 3px solid #1173c1;
    padding-left: 14px;
    margin-bottom: 1.35rem;
}
.patient-appt-step-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 0.7rem;
}
.patient-appt-step-num {
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #1173c1;
    color: #fff;
    font-weight: 700;
    font-size: 0.95rem;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.patient-appt-step-title {
    font-weight: 700;
    font-size: 1.05rem;
    color: #0f172a;
    letter-spacing: -0.01em;
}
.patient-appt-step-body .form-label {
    font-weight: 600;
}
.patient-calendar-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    padding: 1.25rem;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    background: #f8fafc;
    color: #64748b;
    font-size: 0.92rem;
    text-align: center;
}
@media (max-width: 991.98px) {
    .patient-appt-form-col {
        order: 1;
    }
    .patient-appt-calendar-col {
        order: 2;
    }
}
@media (max-width: 767px) {
    .patient-appt-card .calendar-panel {
        padding: 10px;
    }
    .patient-appt-card #appointmentCalendar .appt-day-badge {
        display: none;
    }
    .patient-appt-card #appointmentCalendar .fc-daygrid-day-frame {
        min-height: 2.35rem;
    }
    .patient-appt-card #appointmentCalendar .fc-daygrid-day-number {
        font-size: 0.82rem;
        padding: 2px 4px;
    }
    .patient-appt-card #appointmentCalendar .fc-col-header-cell-cushion {
        font-size: 0.68rem;
        padding: 4px 2px;
    }
    .patient-appt-card #appointmentCalendar .fc-scrollgrid,
    .patient-appt-card #appointmentCalendar .fc-scrollgrid-section > * {
        border-color: #e2e8f0;
    }
    .patient-appt-card #appointmentCalendar .fc-daygrid-body-unbalanced .fc-daygrid-day-events {
        min-height: 0;
        margin: 0;
    }
}
</style>

<div class="card border-0 patient-appt-card">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="card-title mb-1">Make an Appointment</h5>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div id="apptBookPane">
            <div id="apptAlert"></div>
            <form id="createApptForm">
                <div class="row g-4 patient-appt-booking-row">
                    <div class="col-lg-5 order-lg-2 patient-appt-form-col">
                        <div class="patient-appt-step">
                            <div class="patient-appt-step-heading">
                                <span class="patient-appt-step-num" aria-hidden="true">1</span>
                                <span class="patient-appt-step-title">Choose Doctor</span>
                            </div>
                            <div class="patient-appt-step-body">
                                <select class="form-select" id="user_id" name="user_id" required aria-label="Choose doctor">
                                    <option value="">-- Select Doctor --</option>
                                    <?php foreach ($doctors_list as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="patient-appt-step">
                            <div class="patient-appt-step-heading">
                                <span class="patient-appt-step-num" aria-hidden="true">2</span>
                                <span class="patient-appt-step-title">Select Services</span>
                            </div>
                            <div class="patient-appt-step-body">
                                <div id="serviceSelector" class="service-selector">
                                    <small class="text-muted">Please select a doctor first</small>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3" id="patientTimeslotWrap" style="display:none;">
                            <label class="form-label">Choose Start Time (range shown)</label>
                            <select class="form-select" id="timeslot_id" name="timeslot_id" required disabled>
                                <option value="">-- Select a start time slot --</option>
                            </select>
                            <div class="form-text" id="patientTimeslotHint">Select services to see the required appointment duration.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reason for Appointment</label>
                            <textarea class="form-control" id="reason" name="reason" rows="4" placeholder="Describe the reason" required></textarea>
                        </div>

                        <input type="hidden" name="patient_id" value="<?php echo (int)$patient_id; ?>">
                        <input type="hidden" name="is_online_appointment" value="0">
                    </div>
                    <div class="col-lg-7 order-lg-1 patient-appt-calendar-col">
                        <div class="calendar-panel" id="patientCalendarPanel">
                            <div class="patient-appt-step mb-0">
                                <div class="patient-appt-step-heading">
                                    <span class="patient-appt-step-num" aria-hidden="true">3</span>
                                    <span class="patient-appt-step-title">Availability Calendar</span>
                                </div>
                            </div>
                            <div id="calendarLockedMessage" class="patient-calendar-placeholder">
                                Select a doctor first to view the availability calendar.
                            </div>
                            <div id="calendarActiveWrap" class="d-none">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span id="selectedDateLabel" class="selected-date-chip">No date selected</span>
                                </div>
                                <input type="hidden" id="appointment_date" name="appointment_date">
                                <div id="appointmentCalendar" aria-live="polite"></div>
                                <div class="calendar-legend">
                                    <span class="legend-item"><span class="legend-dot legend-open"></span>Available</span>
                                    <span class="legend-item"><span class="legend-dot legend-busy"></span>With bookings</span>
                                    <span class="legend-item"><span class="legend-dot legend-blocked"></span>Unavailable / Closed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3" id="submitApptBtn" style="width: 180px; display: block; margin: 12px auto 0;">
                    Request Appointment
                </button>
            </form>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-2">
            <div>
                <h6 class="mb-1">Appointment History</h6>
            </div>
        </div>

        <div class="unified-table-search-toolbar patient-appt-history-search-toolbar">
            <div class="unified-table-search-wrap module-search-inline patient-appt-history-search-wrap">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input
                    type="search"
                    id="patientApptHistorySearch"
                    class="form-control unified-table-search-input"
                    placeholder="Search appointment #, date, doctor, time, reason, or status..."
                    autocomplete="off"
                    aria-label="Search appointment history"
                >
                <button type="button" class="unified-table-search-clear" id="patientApptHistorySearchClear" aria-label="Clear search" style="display:none;">&times;</button>
            </div>
            <div id="patientApptHistorySearchMeta" class="patient-appt-history-search-meta"></div>
        </div>

        <div id="apptHistoryPane">
            <div class="history-table-wrap table-responsive">
                <table class="table table-hover align-middle patient-appt-history-table" data-skip-table-search="1">
                    <thead>
                        <tr>
                            <th>Appointment #</th>
                            <th>Date</th>
                            <th>Doctor</th>
                            <th>Time Slot</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="patientApptHistoryBody">
                        <?php if (empty($appointment_history)): ?>
                            <tr id="patientApptHistoryEmpty">
                                <td colspan="7" class="text-center py-4 history-empty">No appointment history yet.</td>
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
                                } elseif ($statusKey === 'missed') {
                                    $statusBadge = 'warning';
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

                                $apptDateRaw = (string)($history['appointment_date'] ?? '');
                                $apptDateTs = $apptDateRaw !== '' ? strtotime($apptDateRaw) : false;
                                $isFutureOrToday = ($apptDateTs !== false) ? (date('Y-m-d', $apptDateTs) >= date('Y-m-d')) : false;
                                $slotEndRaw = (string)($history['end_time'] ?? '');
                                $apptDateOnly = ($apptDateTs !== false) ? date('Y-m-d', $apptDateTs) : '';
                                $slotEndTs = false;
                                if ($apptDateOnly !== '' && $slotEndRaw !== '') {
                                    $slotEndTs = strtotime($apptDateOnly . ' ' . $slotEndRaw);
                                }
                                $baseTimeTs = $slotEndTs !== false ? $slotEndTs : $apptDateTs;
                                $isComputedMissed = in_array($statusKey, ['scheduled', 'confirmed'], true) && ($baseTimeTs !== false) && ($baseTimeTs < time());
                                if ($isComputedMissed) {
                                    $statusKey = 'missed';
                                    $statusLabel = 'Missed';
                                    $statusBadge = 'warning';
                                }
                                $hasPendingResched = ((int)($history['pending_reschedule_count'] ?? 0) > 0);
                                $canRequestReschedule = in_array($statusKey, ['scheduled', 'confirmed', 'missed'], true) && !$hasPendingResched;
                                ?>
                                <tr class="patient-appt-history-row">
                                    <td data-label="Appointment #">#<?php echo (int)($history['appointment_id'] ?? 0); ?></td>
                                    <td data-label="Date"><?php echo !empty($history['appointment_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$history['appointment_date']))) : 'N/A'; ?></td>
                                    <td data-label="Doctor"><?php echo htmlspecialchars($doctorName); ?></td>
                                    <td data-label="Time Slot"><?php echo htmlspecialchars($slotLabel); ?></td>
                                    <td data-label="Reason"><?php echo htmlspecialchars((string)($history['reason'] ?? '')); ?></td>
                                    <td data-label="Status"><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                    <td data-label="Actions">
                                        <?php if ($canRequestReschedule): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-warning patient-resched-btn"
                                                data-appointment-id="<?php echo (int)$history['appointment_id']; ?>"
                                                data-doctor-id="<?php echo (int)($history['user_id'] ?? 0); ?>"
                                                data-current-date="<?php echo htmlspecialchars($apptDateRaw); ?>"
                                            >
                                                <i class="bi bi-calendar2-week"></i> Request Reschedule
                                            </button>
                                        <?php elseif ($hasPendingResched): ?>
                                            <span class="badge bg-warning text-dark">Reschedule Pending Approval</span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="patientRescheduleModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="bi bi-calendar2-week"></i> Request Appointment Reschedule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="patientRescheduleAppointmentId">
                        <input type="hidden" id="patientRescheduleDoctorId">

                        <div class="mb-3">
                            <label class="form-label">Preferred New Date</label>
                            <input type="date" id="patientRescheduleDate" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Preferred Time Slot</label>
                            <select id="patientRescheduleTimeslot" class="form-select" disabled>
                                <option value="">-- Select available slot --</option>
                            </select>
                            <small class="text-muted">Only available slots can be requested. Secretary approval is required.</small>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Note to Secretary (optional)</label>
                            <textarea id="patientRescheduleNote" class="form-control" rows="3" placeholder="Preferred time reason or constraints"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning" id="submitPatientRescheduleBtn">Submit Request</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="../assets/vendor/fullcalendar/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const appointmentCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const DAYS_AHEAD = 150;
    const dateSelect = document.getElementById('appointment_date');
    const alertBox = document.getElementById('apptAlert');
    const calendarLockedMessage = document.getElementById('calendarLockedMessage');
    const calendarActiveWrap = document.getElementById('calendarActiveWrap');
    const patientCalendarPanel = document.getElementById('patientCalendarPanel');
    const doctorSelect = document.getElementById('user_id');
    const timeslotWrap = document.getElementById('patientTimeslotWrap');
    const timeslotSelect = document.getElementById('timeslot_id');
    const timeslotHint = document.getElementById('patientTimeslotHint');
    const selectedDateLabel = document.getElementById('selectedDateLabel');
    const bookPane = document.getElementById('apptBookPane');
    const historyPane = document.getElementById('apptHistoryPane');
    const historyBody = document.getElementById('patientApptHistoryBody');
    const historyEmptyRow = document.getElementById('patientApptHistoryEmpty');
    const apptHistorySearchInput = document.getElementById('patientApptHistorySearch');
    const apptHistorySearchClear = document.getElementById('patientApptHistorySearchClear');
    const apptHistorySearchMeta = document.getElementById('patientApptHistorySearchMeta');
    let apptHistoryNoMatchRow = null;
    const patientRescheduleModalEl = document.getElementById('patientRescheduleModal');
    const patientRescheduleDateEl = document.getElementById('patientRescheduleDate');
    const patientRescheduleTimeslotEl = document.getElementById('patientRescheduleTimeslot');
    const patientRescheduleAppointmentIdEl = document.getElementById('patientRescheduleAppointmentId');
    const patientRescheduleDoctorIdEl = document.getElementById('patientRescheduleDoctorId');
    const patientRescheduleNoteEl = document.getElementById('patientRescheduleNote');
    const submitPatientRescheduleBtn = document.getElementById('submitPatientRescheduleBtn');
    let patientRescheduleModal = null;

    let appointmentCalendar = null;
    let availabilityByDate = {};
    let selectedDayEl = null;

    function getCalendarEl() {
        return document.getElementById('appointmentCalendar');
    }

    function isMobileCalendar() {
        return window.innerWidth < 768;
    }

    function setCalendarPanelVisible(visible) {
        if (calendarLockedMessage) {
            calendarLockedMessage.classList.toggle('d-none', visible);
        }
        if (calendarActiveWrap) {
            calendarActiveWrap.classList.toggle('d-none', !visible);
        }
        if (patientCalendarPanel) {
            patientCalendarPanel.classList.toggle('calendar-panel-active', visible);
        }
        if (dateSelect) {
            dateSelect.required = visible;
        }
    }

    function destroyCalendar() {
        if (appointmentCalendar) {
            appointmentCalendar.destroy();
            appointmentCalendar = null;
        }
        selectedDayEl = null;
    }

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
        window.showToast(String(message || ''), type || 'info');
    }

    function showBookingToast(message, type) {
        showAlert(message, type);
    }

    function statusBadgeClass(status) {
        const key = String(status || '').toLowerCase();
        if (key === 'confirmed') return 'primary';
        if (key === 'checked_in') return 'info';
        if (key === 'in_progress') return 'warning';
        if (key === 'missed') return 'warning';
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

    function historyCell(label, content) {
        const td = document.createElement('td');
        td.setAttribute('data-label', label);
        if (content instanceof Node) {
            td.appendChild(content);
        } else {
            td.textContent = String(content ?? '');
        }
        return td;
    }

    function getHistoryDataRows() {
        if (!historyBody) return [];
        return Array.from(historyBody.querySelectorAll('tr.patient-appt-history-row'));
    }

    function updateApptHistorySearchMeta(visibleCount, totalCount, query) {
        if (!apptHistorySearchMeta) return;
        if (totalCount === 0) {
            apptHistorySearchMeta.textContent = '';
            return;
        }
        if (!query) {
            apptHistorySearchMeta.textContent = totalCount + ' appointment' + (totalCount === 1 ? '' : 's');
            return;
        }
        apptHistorySearchMeta.textContent = 'Showing ' + visibleCount + ' of ' + totalCount;
    }

    function clearApptHistoryNoMatchRow() {
        if (apptHistoryNoMatchRow && apptHistoryNoMatchRow.parentNode) {
            apptHistoryNoMatchRow.parentNode.removeChild(apptHistoryNoMatchRow);
        }
        apptHistoryNoMatchRow = null;
    }

    function showApptHistoryNoMatchRow() {
        if (!historyBody) return;
        clearApptHistoryNoMatchRow();
        apptHistoryNoMatchRow = document.createElement('tr');
        apptHistoryNoMatchRow.className = 'patient-appt-history-no-match history-empty-row';
        const td = document.createElement('td');
        td.colSpan = 7;
        td.className = 'text-center py-4 history-empty';
        td.textContent = 'No appointments match your search.';
        apptHistoryNoMatchRow.appendChild(td);
        historyBody.appendChild(apptHistoryNoMatchRow);
    }

    function filterAppointmentHistory(query) {
        const normalized = String(query || '').trim().toLowerCase();
        const rows = getHistoryDataRows();
        const total = rows.length;

        if (apptHistorySearchClear && apptHistorySearchInput) {
            apptHistorySearchClear.style.display = apptHistorySearchInput.value ? '' : 'none';
        }

        clearApptHistoryNoMatchRow();

        if (total === 0) {
            updateApptHistorySearchMeta(0, 0, normalized);
            return;
        }

        let visible = 0;
        rows.forEach(function(row) {
            const match = !normalized || row.textContent.toLowerCase().includes(normalized);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        if (normalized && visible === 0) {
            showApptHistoryNoMatchRow();
        }

        updateApptHistorySearchMeta(visible, total, normalized);
    }

    function appendHistoryRow(payload) {
        if (!historyBody || !payload) return;
        if (historyEmptyRow && historyEmptyRow.parentNode) {
            historyEmptyRow.parentNode.removeChild(historyEmptyRow);
        }

        const tr = document.createElement('tr');
        tr.className = 'patient-appt-history-row';

        tr.appendChild(historyCell('Appointment #', '#' + String(payload.appointmentId || 0)));
        tr.appendChild(historyCell('Date', displayDateLabel(payload.date)));
        tr.appendChild(historyCell('Doctor', String(payload.doctor || 'To be assigned')));
        tr.appendChild(historyCell('Time Slot', String(payload.slot || 'TBD')));
        tr.appendChild(historyCell('Reason', String(payload.reason || '')));

        const badge = document.createElement('span');
        badge.className = 'badge bg-' + statusBadgeClass(payload.status);
        badge.textContent = statusLabel(payload.status);
        tr.appendChild(historyCell('Status', badge));

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-warning patient-resched-btn';
        btn.setAttribute('data-appointment-id', String(payload.appointmentId || ''));
        btn.setAttribute('data-doctor-id', String(payload.doctorId || ''));
        btn.setAttribute('data-current-date', String(payload.date || ''));
        btn.innerHTML = '<i class="bi bi-calendar2-week"></i> Request Reschedule';
        tr.appendChild(historyCell('Actions', btn));

        if (historyBody.firstChild) {
            historyBody.insertBefore(tr, historyBody.firstChild);
        } else {
            historyBody.appendChild(tr);
        }

        if (apptHistorySearchInput) {
            filterAppointmentHistory(apptHistorySearchInput.value);
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
        const calendarEl = getCalendarEl();
        if (!calendarEl) return;

        destroyCalendar();

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
            contentHeight: isMobileCalendar() ? 'auto' : undefined,
            aspectRatio: isMobileCalendar() ? 1.05 : 1.35,
            headerToolbar: isMobileCalendar()
                ? { left: 'prev,next', center: 'title', right: 'today' }
                : { left: 'prev,next today', center: 'title', right: '' },
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
        const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
        if (!doctorId) {
            availabilityByDate = {};
            destroyCalendar();
            setCalendarPanelVisible(false);
            return;
        }

        setCalendarPanelVisible(true);

        try {
            let url = '../assets/api/appointments/get_available_dates.php?days=' + encodeURIComponent(String(DAYS_AHEAD));
            url += '&user_id=' + encodeURIComponent(doctorId);

            const r = await fetch(url);
            if (!r.ok) {
                throw new Error('Network response was not ok');
            }

            const data = await r.json();
            if (!data.success || !Array.isArray(data.dates)) {
                availabilityByDate = {};
                renderCalendar();
                showAlert('Unable to load available dates for this doctor.', 'warning');
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
        const selectedDuration = Number(window.patientSelectedServiceDuration || 0);
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
            let url = '../assets/api/appointments/get_timeslots.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(apptDate);
            if (selectedDuration > 0) {
                url += '&duration=' + encodeURIComponent(String(selectedDuration));
            }
            const r = await fetch(url);
            const data = await r.json();
            timeslotSelect.innerHTML = '<option value="">-- Select a 20-minute slot --</option>';

            if (!data.success || !Array.isArray(data.slots) || data.slots.length === 0) {
                timeslotSelect.innerHTML = '<option value="">No available timeslots for this date</option>';
                if (timeslotHint) {
                    if (selectedDuration > 0) {
                        timeslotHint.textContent = `No available time range can fit ${selectedDuration} minutes on this date.`;
                    } else {
                        timeslotHint.textContent = 'No available timeslots for this date.';
                    }
                }
                return;
            }

            data.slots.forEach(function(slot) {
                const opt = document.createElement('option');
                opt.value = String(slot.timeslot_id);
                opt.textContent = String(slot.label || 'Slot');
                if (slot.end_time) {
                    opt.dataset.endTime = String(slot.end_time);
                }
                timeslotSelect.appendChild(opt);
            });
            if (timeslotHint) {
                timeslotHint.textContent = 'Select a start time. The option shows the full time range for your services.';
            }
        } catch (err) {
            console.error(err);
            timeslotSelect.innerHTML = '<option value="">Unable to load timeslots</option>';
        }
    }

    async function loadPatientRescheduleTimeslots() {
        if (!patientRescheduleTimeslotEl) return;
        const doctorId = patientRescheduleDoctorIdEl ? String(patientRescheduleDoctorIdEl.value || '').trim() : '';
        const dateVal = patientRescheduleDateEl ? String(patientRescheduleDateEl.value || '').trim() : '';

        patientRescheduleTimeslotEl.disabled = true;
        patientRescheduleTimeslotEl.innerHTML = '<option value="">-- Select available slot --</option>';

        if (!doctorId || !dateVal) {
            return;
        }

        patientRescheduleTimeslotEl.innerHTML = '<option value="">Loading time slots...</option>';
        try {
            const r = await fetch('../assets/api/appointments/get_timeslots.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(dateVal));
            const data = await r.json();
            patientRescheduleTimeslotEl.innerHTML = '<option value="">-- Select available slot --</option>';
            if (!data.success || !Array.isArray(data.slots) || data.slots.length === 0) {
                patientRescheduleTimeslotEl.innerHTML = '<option value="">No available slots for this date</option>';
                patientRescheduleTimeslotEl.disabled = true;
                return;
            }

            data.slots.forEach(function(slot) {
                const opt = document.createElement('option');
                opt.value = String(slot.timeslot_id || '');
                opt.textContent = String(slot.label || 'Slot');
                patientRescheduleTimeslotEl.appendChild(opt);
            });
            patientRescheduleTimeslotEl.disabled = false;
        } catch (e) {
            console.error(e);
            patientRescheduleTimeslotEl.innerHTML = '<option value="">Unable to load timeslots</option>';
            patientRescheduleTimeslotEl.disabled = true;
        }
    }

    setCalendarPanelVisible(false);

    if (doctorSelect) {
        doctorSelect.addEventListener('change', function() {
            loadDates();
            loadTimeslots();
        });
    }

    let calendarResizeTimer = null;
    window.addEventListener('resize', function() {
        if (!appointmentCalendar) return;
        clearTimeout(calendarResizeTimer);
        calendarResizeTimer = setTimeout(function() {
            renderCalendar();
        }, 200);
    });

    if (timeslotSelect) {
        timeslotSelect.addEventListener('change', function() {
            const selectedOption = timeslotSelect.options[timeslotSelect.selectedIndex];
            const endTime = selectedOption?.dataset?.endTime || '';
            const label = String(selectedOption?.textContent || '').trim();
            if (timeslotHint) {
                if (endTime && label) {
                    timeslotHint.textContent = `Selected time: ${label}`;
                } else {
                    timeslotHint.textContent = 'Select services to see the required appointment duration.';
                }
            }
        });
    }

    window.addEventListener('patient-service-duration-changed', function() {
        loadTimeslots();
    });

    if (typeof bootstrap !== 'undefined' && patientRescheduleModalEl) {
        patientRescheduleModal = bootstrap.Modal.getOrCreateInstance(patientRescheduleModalEl);
    }

    if (patientRescheduleDateEl) {
        patientRescheduleDateEl.min = todayIso;
        patientRescheduleDateEl.addEventListener('change', function() {
            loadPatientRescheduleTimeslots();
        });
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.patient-resched-btn');
        if (!btn) return;

        const appointmentId = String(btn.getAttribute('data-appointment-id') || '').trim();
        const doctorId = String(btn.getAttribute('data-doctor-id') || '').trim();
        const currentDate = String(btn.getAttribute('data-current-date') || '').trim();

        if (!appointmentId || !doctorId) {
            showBookingToast('Unable to open reschedule request. Missing appointment details.', 'danger');
            return;
        }

        if (patientRescheduleAppointmentIdEl) patientRescheduleAppointmentIdEl.value = appointmentId;
        if (patientRescheduleDoctorIdEl) patientRescheduleDoctorIdEl.value = doctorId;
        if (patientRescheduleDateEl) {
            patientRescheduleDateEl.value = currentDate ? String(currentDate).slice(0, 10) : '';
        }
        if (patientRescheduleNoteEl) {
            patientRescheduleNoteEl.value = '';
        }

        loadPatientRescheduleTimeslots();
        if (patientRescheduleModal) patientRescheduleModal.show();
    });

    if (submitPatientRescheduleBtn) {
        submitPatientRescheduleBtn.addEventListener('click', async function() {
            const appointmentId = patientRescheduleAppointmentIdEl ? String(patientRescheduleAppointmentIdEl.value || '').trim() : '';
            const doctorId = patientRescheduleDoctorIdEl ? String(patientRescheduleDoctorIdEl.value || '').trim() : '';
            const requestedDate = patientRescheduleDateEl ? String(patientRescheduleDateEl.value || '').trim() : '';
            const requestedTimeslot = patientRescheduleTimeslotEl ? String(patientRescheduleTimeslotEl.value || '').trim() : '';
            const requestNote = patientRescheduleNoteEl ? String(patientRescheduleNoteEl.value || '').trim() : '';

            if (!appointmentId || !doctorId || !requestedDate || !requestedTimeslot) {
                showBookingToast('Please select date and available slot for your request.', 'warning');
                return;
            }

            submitPatientRescheduleBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('appointment_id', appointmentId);
                fd.append('requested_doctor_id', doctorId);
                fd.append('requested_date', requestedDate);
                fd.append('requested_timeslot_id', requestedTimeslot);
                fd.append('request_note', requestNote);
                fd.append('csrf_token', appointmentCsrfToken);

                const r = await fetch('../assets/api/appointments/request_reschedule.php', {
                    method: 'POST',
                    body: fd
                });
                const j = await r.json();

                if (!j.success) {
                    showBookingToast(j.message || 'Failed to submit reschedule request.', 'danger');
                    return;
                }

                if (patientRescheduleModal) patientRescheduleModal.hide();
                showBookingToast(j.message || 'Reschedule request submitted.', 'success');

                const triggerBtn = document.querySelector('.patient-resched-btn[data-appointment-id="' + appointmentId + '"]');
                if (triggerBtn) {
                    const td = triggerBtn.closest('td');
                    if (td) {
                        td.innerHTML = '<span class="badge bg-warning text-dark">Reschedule Pending Approval</span>';
                    }
                }
            } catch (err) {
                console.error(err);
                showBookingToast('Server error while submitting request.', 'danger');
            } finally {
                submitPatientRescheduleBtn.disabled = false;
            }
        });
    }

    const form = document.getElementById('createApptForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
            const selectedDate = dateSelect ? String(dateSelect.value || '').trim() : '';
            if (!doctorId) {
                showAlert('Please select a doctor first.', 'warning');
                return;
            }
            if (!selectedDate) {
                showAlert('Please select an available date on the calendar.', 'warning');
                return;
            }

            const tsId = timeslotSelect ? String(timeslotSelect.value || '').trim() : '';
            if (!tsId) {
                showAlert('Please select a time slot before booking.', 'warning');
                return;
            }

            const selectedDoctor = doctorSelect && doctorSelect.selectedOptions.length > 0
                ? String(doctorSelect.selectedOptions[0].textContent || '').trim()
                : 'To be assigned';
            const selectedSlot = timeslotSelect && timeslotSelect.selectedOptions.length > 0
                ? String(timeslotSelect.selectedOptions[0].textContent || '').trim()
                : 'TBD';
            const selectedReason = document.getElementById('reason') ? String(document.getElementById('reason').value || '').trim() : '';

            const submitBtn = document.getElementById('submitApptBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Submitting...';
            }

            const formData = new FormData(form);
            formData.append('csrf_token', appointmentCsrfToken);
            fetch('../assets/api/appointments/create_appointment.php', {
                method: 'POST',
                body: formData
            }).then(function(r) {
                return r.json();
            }).then(function(resp) {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Request Appointment';
                }
                if (resp.success) {
                    appendHistoryRow({
                        appointmentId: resp.appointment_id,
                        date: selectedDate,
                        doctor: selectedDoctor,
                        doctorId: doctorSelect ? String(doctorSelect.value || '') : '',
                        slot: selectedSlot,
                        reason: selectedReason,
                        status: resp.status || 'scheduled'
                    });
                    showBookingToast(resp.message || 'Appointment created successfully.', 'success');

                    form.reset();
                    clearSelectedDate();
                    setCalendarPanelVisible(false);
                    destroyCalendar();
                    const serviceSelectorEl = document.getElementById('serviceSelector');
                    if (serviceSelectorEl) {
                        serviceSelectorEl.innerHTML = '<small class="text-muted">Please select a doctor first</small>';
                    }
                } else {
                    const failType = resp.warning ? 'warning' : 'danger';
                    showAlert(resp.message || 'Failed to create appointment.', failType);
                }
            }).catch(function(err) {
                console.error(err);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Request Appointment';
                }
                showAlert('Server error creating appointment.', 'danger');
            });
        });
    }

    if (apptHistorySearchInput) {
        apptHistorySearchInput.addEventListener('input', function() {
            filterAppointmentHistory(this.value);
        });
        apptHistorySearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                apptHistorySearchInput.value = '';
                if (apptHistorySearchClear) apptHistorySearchClear.style.display = 'none';
                filterAppointmentHistory('');
            }
        });
    }

    if (apptHistorySearchClear) {
        apptHistorySearchClear.addEventListener('click', function() {
            if (!apptHistorySearchInput) return;
            apptHistorySearchInput.value = '';
            apptHistorySearchClear.style.display = 'none';
            filterAppointmentHistory('');
            apptHistorySearchInput.focus();
        });
    }

    filterAppointmentHistory('');
});
</script>
