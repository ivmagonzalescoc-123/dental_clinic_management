<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/assets/api/appointments/availability_guard.php';
include_once dirname(dirname(__DIR__)) . '/config/booking_helpers.php';
appointments_ensure_reference_column($conn);
security_ensure_work_type_column($conn);
appt_ensure_cancellation_reason_column($conn);

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
        $historySql = "SELECT a.appointment_id, a.reference_code, a.appointment_date, a.reason, a.status, a.created_at,
                                                    a.cancellation_reason,
                                                    a.user_id,
                                                    a.timeslot_id,
                          u.first_name AS doctor_first_name, u.last_name AS doctor_last_name,
                                                    t.start_time, t.end_time,
                                                    (
                                                            SELECT COUNT(*)
                                                            FROM appointment_reschedule_requests rr
                                                            WHERE rr.appointment_id = a.appointment_id
                                                                AND rr.status = 'pending'
                                                    ) AS pending_reschedule_count,
                      (SELECT GROUP_CONCAT(DISTINCT sv.service_name ORDER BY sv.service_name SEPARATOR ', ')
                         FROM selected_services ss JOIN services sv ON sv.service_id = ss.service_id
                        WHERE ss.appointment_id = a.appointment_id) AS services
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
    background: #1b7aa3;
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
    overflow: hidden;
}
.patient-appt-card .selected-date-chip {
    display: inline-flex;
    align-items: center;
    font-weight: 600;
    font-size: 0.85rem;
    background: #dcf0f7;
    color: #17698c;
    border-radius: 999px;
    padding: 0.35rem 0.8rem;
}
.patient-appt-card .appt-tab-nav .nav-link {
    border-radius: 999px;
    font-weight: 600;
    color: #0f172a;
}
.patient-appt-card .appt-tab-nav .nav-link.active {
    background: #1b7aa3;
    color: #fff;
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
    background: #eff8fb;
    color: #17698c;
    border-bottom: 1px solid #dbe7e4;
    white-space: nowrap;
}
.patient-appt-card .history-empty {
    color: #64748b;
    font-style: italic;
}
/* Appointment history: compact visit cards, same look as Clinic Records. */
.ah { margin-top: 1.75rem; }
.ah-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.ah-title { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
.ah-title i { color: #1b7aa3; }
.ah-sub { font-size: 0.82rem; color: #64748b; margin-top: 2px; }
.ah-count { font-size: 0.8rem; font-weight: 700; color: #17698c; background: #eff8fb; border-radius: 999px; padding: 4px 12px; white-space: nowrap; }
.ah-count:empty { display: none; }
.ah-search { width: 100%; max-width: 420px; margin-bottom: 12px; }
.ah-list { display: flex; flex-direction: column; gap: 8px; }
.ah-row {
    display: grid; grid-template-columns: 56px minmax(0, 1fr) auto auto; align-items: center; gap: 14px;
    padding: 10px 14px; background: #fff; border: 1px solid #e5eaf0; border-radius: 12px;
}
.ah-date {
    width: 56px; height: 56px; border-radius: 12px; background: #f1f5f9; color: #475569;
    display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1;
}
.ah-date strong { font-size: 1.15rem; }
.ah-date small { font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.ah-row.is-upcoming { border-color: #cfe3ec; }
.ah-row.is-upcoming .ah-date { background: #1b7aa3; }
#main .ah-row.is-upcoming .ah-date strong, #main .ah-row.is-upcoming .ah-date small { color: #fff !important; }
.ah-main { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
.ah-name { font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ah-desc { font-size: 0.84rem; color: #334155; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.ah-meta { font-size: 0.78rem; color: #64748b; }
.ah-meta .bi { color: #94a3b8; }
.ah-date { height: 62px !important; }
.ah-date .ah-dow { font-size: 0.6rem; opacity: .8; }
.ah-status .badge { font-size: 0.74rem; }
.ah-action:empty { display: none; }
.ah-btn { border-radius: 999px; padding: 4px 12px; font-weight: 600; white-space: nowrap; }
.ah-pending { display: inline-flex; align-items: center; gap: 5px; font-size: 0.78rem; font-weight: 600; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
@media (max-width: 575.98px) {
    .ah-head { align-items: flex-start; }
    .ah-search { max-width: none; }
    .ah-row { grid-template-columns: 52px minmax(0, 1fr); gap: 6px 12px; padding: 10px 12px; align-items: start; }
    .ah-date { grid-row: 1 / span 2; }
    .ah-status, .ah-action { grid-column: 2; }
    .ah-status + .ah-action { margin-top: -2px; }
    .ah-date { width: 52px; height: 52px; }
    .ah-date small { font-size: 0.56rem; white-space: nowrap; }
    .ah-name { white-space: normal; }
}
.patient-appt-booking-row {
    align-items: flex-start;
}
.patient-appt-step {
    border-left: 3px solid #1b7aa3;
    padding-left: 14px;
    margin-bottom: 1.35rem;
}
.patient-appt-step.is-compact {
    margin-bottom: 1rem;
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
    background: #1b7aa3;
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
.patient-symptom-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 0.6rem;
}
.patient-symptom-option {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    padding: 0.65rem 0.75rem;
    border: 1px solid #dbe7e4;
    border-radius: 12px;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.patient-symptom-option:hover {
    border-color: #1b7aa3;
    box-shadow: 0 8px 20px rgba(27, 122, 163, 0.08);
    transform: translateY(-1px);
}
.patient-symptom-option input {
    margin-top: 0.18rem;
}
.patient-symptom-label {
    font-size: 0.92rem;
    color: #0f172a;
    line-height: 1.35;
}
.patient-appt-note {
    color: #64748b;
    font-size: 0.85rem;
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
}</style>

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
                    <div class="col-lg-7 order-lg-1 patient-appt-calendar-col">
                        <div class="calendar-panel" id="patientCalendarPanel">
                            <div class="patient-appt-step mb-0">
                                <div class="patient-appt-step-heading">
                                    <span class="patient-appt-step-num" aria-hidden="true">1</span>
                                    <span class="patient-appt-step-title">Select Date</span>
                                </div>
                            </div>
                            <div id="calendarActiveWrap">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span id="selectedDateLabel" class="selected-date-chip">No date selected</span>
                                </div>
                                <input type="hidden" id="appointment_date" name="appointment_date">
                                <div id="appointmentCalendar"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 order-lg-2 patient-appt-form-col">
                        <div class="patient-appt-step is-compact">
                            <div class="patient-appt-step-heading">
                                <span class="patient-appt-step-num" aria-hidden="true">2</span>
                                <span class="patient-appt-step-title">Choose Doctor</span>
                            </div>
                            <div class="patient-appt-step-body">
                            <label class="form-label fw-semibold" style="font-size:0.85rem;">Select a doctor <span class="text-muted fw-normal">(required)</span></label>
                            <select class="form-select" id="user_id" name="user_id" aria-label="Choose doctor" required>
                                <option value="" disabled selected>Select a doctor...</option>
                                <?php foreach ($doctors_list as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="patient-appt-note mt-2">Selecting a doctor lets the clinic show the correct time slots for that provider.</div>
                            </div>
                        </div>

                        <div class="patient-appt-step is-compact">
                            <div class="patient-appt-step-heading">
                                <span class="patient-appt-step-num" aria-hidden="true">3</span>
                                <span class="patient-appt-step-title">Select Services</span>
                            </div>
                            <div class="patient-appt-step-body">
                            <label class="form-label fw-semibold" style="font-size:0.85rem;">Select services <span class="text-muted fw-normal">(optional)</span></label>
                            <div id="serviceSelector" class="service-selector">
                                <div class="svc-collapsed" id="svcCollapsed">
                                    <span class="svc-collapsed-label">
                                        <i class="bi bi-plus-circle"></i> Tap to select services
                                    </span>
                                </div>
                                <div class="svc-expanded d-none" id="svcExpanded">
                                    <div class="text-muted small mb-2">Loading services...</div>
                                </div>
                                <div id="selectedServiceInputs"><input type="hidden" name="total_duration_minutes" value="0"></div>
                            </div>
                            </div>
                        </div>

                        <div class="patient-appt-step" id="patientTimeslotWrap">
                            <div class="patient-appt-step-heading">
                                <span class="patient-appt-step-num" aria-hidden="true">4</span>
                                <span class="patient-appt-step-title">Choose Time Slot</span>
                            </div>
                            <div class="patient-appt-step-body">
                                <select class="form-select" id="timeslot_id" name="timeslot_id" required disabled>
                                    <option value="">-- Select a time slot --</option>
                                </select>
                                <div class="form-text" id="patientTimeslotHint">Select a date to see available time slots.</div>
                            </div>
                        </div>

                        <div class="patient-appt-step">
                            <div class="patient-appt-step-heading">
                                <span class="patient-appt-step-num" aria-hidden="true">5</span>
                                <span class="patient-appt-step-title">Symptoms & Additional Details</span>
                            </div>
                            <div class="patient-appt-step-body">
                                <label class="form-label fw-semibold" style="font-size:0.85rem;">Select symptoms <span class="text-muted fw-normal">(optional)</span></label>
                                <div class="patient-symptom-grid mb-3" id="patientSymptomGrid">
                                    <label class="patient-symptom-option">
                                        <input type="checkbox" class="form-check-input patient-symptom-check" value="Toothache">
                                        <span class="patient-symptom-label">Toothache</span>
                                    </label>
                                    <label class="patient-symptom-option">
                                        <input type="checkbox" class="form-check-input patient-symptom-check" value="Sensitive teeth">
                                        <span class="patient-symptom-label">Sensitive teeth</span>
                                    </label>
                                    <label class="patient-symptom-option">
                                        <input type="checkbox" class="form-check-input patient-symptom-check" value="Bleeding gums">
                                        <span class="patient-symptom-label">Bleeding gums</span>
                                    </label>
                                    <label class="patient-symptom-option">
                                        <input type="checkbox" class="form-check-input patient-symptom-check" value="Swollen gums">
                                        <span class="patient-symptom-label">Swollen gums</span>
                                    </label>
                                    <label class="patient-symptom-option">
                                        <input type="checkbox" class="form-check-input patient-symptom-check" value="Broken or chipped tooth">
                                        <span class="patient-symptom-label">Broken / chipped tooth</span>
                                    </label>
                                    <label class="patient-symptom-option">
                                        <input type="checkbox" class="form-check-input patient-symptom-check" value="Routine checkup">
                                        <span class="patient-symptom-label">Routine checkup</span>
                                    </label>
                                </div>
                                <textarea class="form-control mb-2" id="notes" name="notes" rows="3" placeholder="Add any extra details the clinic should know..."></textarea>
                                <input type="hidden" id="reason" name="reason" value="">
                                <div class="patient-appt-note">Your selected symptoms will be combined into the appointment reason for the clinic.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mt-2" id="submitApptBtn">
                            Request Appointment
                        </button>

                        <input type="hidden" name="patient_id" value="<?php echo (int)$patient_id; ?>">
                        <input type="hidden" name="is_online_appointment" value="0">
                    </div>
                </div>
            </form>
        </div>

        <section class="ah" aria-labelledby="apptHistoryTitle">
            <div class="ah-head">
                <div>
                    <h6 class="ah-title" id="apptHistoryTitle"><i class="bi bi-clock-history" aria-hidden="true"></i> Appointment History</h6>
                    <p class="ah-sub mb-0">Your past and upcoming appointments.</p>
                </div>
                <div id="patientApptHistorySearchMeta" class="ah-count"></div>
            </div>
            <div class="unified-table-search-wrap module-search-inline ah-search">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input
                    type="search"
                    id="patientApptHistorySearch"
                    class="form-control unified-table-search-input"
                    placeholder="Search by date, reference no., doctor, reason, or status..."
                    autocomplete="off"
                    aria-label="Search appointment history"
                >
                <button type="button" class="unified-table-search-clear" id="patientApptHistorySearchClear" aria-label="Clear search" style="display:none;">&times;</button>
            </div>
            <div id="apptHistoryPane">
                <div class="ah-list" id="patientApptHistoryBody" aria-live="polite"></div>
            </div>
            <?php
        // Rendered by the same JS function as live updates, so the list never changes look
        // (or drops fields) when it refreshes in the background.
        $historyForJs = array_map(function ($h) {
            return [
                'appointment_id' => (int)$h['appointment_id'],
                'reference_code' => (string)($h['reference_code'] ?? ''),
                'appointment_date' => (string)($h['appointment_date'] ?? ''),
                'reason' => (string)($h['reason'] ?? ''),
                'status' => (string)($h['status'] ?? ''),
                'user_id' => (int)($h['user_id'] ?? 0),
                'doctor_first_name' => (string)($h['doctor_first_name'] ?? ''),
                'doctor_last_name' => (string)($h['doctor_last_name'] ?? ''),
                'start_time' => $h['start_time'] ?? null,
                'end_time' => $h['end_time'] ?? null,
                'pending_reschedule_count' => (int)($h['pending_reschedule_count'] ?? 0),
                'services' => (string)($h['services'] ?? ''),
            ];
        }, $appointment_history);
        ?>
            <script>window.PATIENT_APPT_HISTORY = <?php echo json_encode($historyForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
        </section>

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
                        <button type="button" class="btn btn-warning" id="submitPatientRescheduleBtn">Submit Request</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const appointmentCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const DAYS_AHEAD = 150;
    const dateSelect = document.getElementById('appointment_date');
    const alertBox = document.getElementById('apptAlert');
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
    let calendarDataLoaded = false;

    function getCalendarEl() {
        return document.getElementById('appointmentCalendar');
    }

    function setCalendarPanelVisible(visible) {
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

    function getHistoryDataRows() {
        if (!historyBody) return [];
        return Array.from(historyBody.querySelectorAll('.patient-appt-history-row'));
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
        apptHistoryNoMatchRow = document.createElement('div');
        apptHistoryNoMatchRow.className = 'dp-note patient-appt-history-no-match';
        apptHistoryNoMatchRow.innerHTML = '<i class="bi bi-search"></i><span>No appointments match your search.</span>';
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
        const empty = historyBody.querySelector('.patient-appt-history-empty');
        if (empty) empty.remove();
        historyBody.insertAdjacentHTML('afterbegin', buildHistoryRow({
            appointment_id: payload.appointmentId,
            reference_code: payload.reference,
            appointment_date: payload.date,
            reason: payload.reason,
            status: payload.status,
            user_id: payload.doctorId,
            doctor_name: payload.doctor,
            slot_label: payload.slot,
            services: payload.services || '',
            pending_reschedule_count: 0
        }));
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
        if (!timeslotSelect) return;
        timeslotSelect.disabled = true;
        timeslotSelect.required = false;
        timeslotSelect.value = '';
        timeslotSelect.innerHTML = '<option value="">-- Select a time slot --</option>';
    }

    function clearSelectedDate() {
        if (dateSelect) dateSelect.value = '';
        updateSelectedDateLabel('');
        if (appointmentCalendar) appointmentCalendar.setSelected(null);
        resetTimeslots();
    }

    function isDateSelectable(dateStr) {
        const hasDoctor = doctorSelect && String(doctorSelect.value || '').trim() !== '';
        if (!hasDoctor || !dateStr || dateStr < todayIso) {
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
        if (!doctorSelect || String(doctorSelect.value || '').trim() === '') {
            return 'Please choose a doctor first.';
        }
        const item = availabilityByDate[dateStr];
        if (!item) {
            return 'No schedule available for this date.';
        }
        const reason = String(item.reason || '').toLowerCase();
        if (reason.includes('leave')) {
            return doctorSelect && doctorSelect.value ? 'Doctor is on leave for this date.' : 'Doctors are unavailable on this date.';
        }
        if (reason.includes('schedule')) {
            return doctorSelect && doctorSelect.value ? 'Doctor has no available schedule on this date.' : 'No doctors available on this date.';
        }
        if (reason.includes('sunday') || reason.includes('closed')) {
            return 'Clinic is closed on this date.';
        }
        return 'This date is not available for booking.';
    }

    function hasDoctorSelected() {
        return !!(doctorSelect && String(doctorSelect.value || '').trim() !== '');
    }

    // Per-day state for the shared calendar (assets/js/dental-calendar.js).
    function getBookingDay(dateStr, dateObj) {
        const lastBookable = addDaysIso(todayIso, DAYS_AHEAD);
        if (dateStr < todayIso || dateStr > lastBookable) {
            return { state: 'past' };
        }
        if (dateObj.getDay() === 0) {
            return { state: 'closed', tags: [{ text: 'Closed', tone: 'muted' }], title: 'Clinic is closed on Sundays' };
        }
        if (!hasDoctorSelected() || !calendarDataLoaded) {
            return { state: 'muted' };
        }
        const item = availabilityByDate[dateStr];
        if (item && item.available) {
            const free = Math.max(0, item.total - item.count);
            // Slots are 10 minutes each, so a raw count means little to a patient;
            // only call out when an hour or less of openings is left.
            const few = item.total > 0 && free <= 6;
            // The "Select" button already says the day is bookable; only add a label
            // when it tells the patient something extra.
            return {
                state: 'available',
                selectable: true,
                mark: few ? 'warn' : 'ok',
                title: few ? 'Few slots left' : 'Available',
                tags: few ? [{ text: 'Few slots left', tone: 'warn' }] : []
            };
        }
        const reason = String(item && item.reason || '').toLowerCase();
        if (reason.includes('leave')) {
            return { state: 'leave', tags: [{ text: 'On leave', tone: 'danger' }], title: 'Doctor is on leave' };
        }
        if (reason.includes('remaining') || reason.includes('slot')) {
            return { state: 'unavailable', tags: [{ text: 'Fully booked', tone: 'warn' }] };
        }
        if (reason.includes('sunday') || reason.includes('closed')) {
            return { state: 'closed', tags: [{ text: 'Closed', tone: 'muted' }] };
        }
        return { state: 'unavailable', tags: [{ text: 'No schedule', tone: 'muted' }], title: 'Doctor has no schedule on this date' };
    }

    function calendarOverlayMessage() {
        if (!hasDoctorSelected()) return 'Choose a doctor to see which days are available.';
        if (!calendarDataLoaded) return 'Loading available days…';
        return null;
    }

    function renderCalendar() {
        const calendarEl = getCalendarEl();
        if (!calendarEl || typeof DentalCalendar === 'undefined') return;
        appointmentCalendar = DentalCalendar.create(calendarEl, {
            month: todayIso.slice(0, 7),
            minMonth: todayIso.slice(0, 7),
            maxMonth: addDaysIso(todayIso, DAYS_AHEAD).slice(0, 7),
            selected: dateSelect && dateSelect.value ? dateSelect.value : null,
            selectLabel: 'Select',
            overlay: calendarOverlayMessage(),
            getDay: getBookingDay,
            onSelect: function(dateStr) {
                if (dateSelect) dateSelect.value = dateStr;
                updateSelectedDateLabel(dateStr);
                loadTimeslots();
            },
            onBlocked: function(dateStr) {
                if (dateStr < todayIso || dateStr > addDaysIso(todayIso, DAYS_AHEAD)) return;
                showAlert(getBlockedReason(dateStr), 'warning');
            },
            legend: [
                { tone: 'ok', label: 'Available' },
                { tone: 'warn', label: 'Few slots left / fully booked' },
                { tone: 'danger', label: 'Doctor on leave' },
                { tone: 'muted', label: 'Closed / no schedule' },
                { tone: 'selected', label: 'Your selected date' }
            ]
        });
    }

    function refreshDayCells() {
        if (!appointmentCalendar) return;
        appointmentCalendar.overlay = calendarOverlayMessage();
        appointmentCalendar.refresh();
    }

    async function loadDates() {
        const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';

        setCalendarPanelVisible(true);

        if (!doctorId) {
            availabilityByDate = {};
            calendarDataLoaded = false;
            clearSelectedDate();
            refreshDayCells();
            if (timeslotHint) {
                timeslotHint.textContent = 'Choose a doctor first to see available time slots.';
            }
            return;
        }

        clearSelectedDate();
        calendarDataLoaded = false;
        refreshDayCells();

        try {
            let url = '../assets/api/appointments/get_available_dates.php?days=' + encodeURIComponent(String(DAYS_AHEAD));
            if (doctorId) {
                url += '&user_id=' + encodeURIComponent(doctorId);
            }

            var data;
            if (Cache) {
                data = await Cache.cachedFetch(url, { ttl: 60000 });
            } else {
                const r = await fetch(url);
                if (!r.ok) throw new Error('Network response was not ok');
                data = await r.json();
            }
            if (!data.success || !Array.isArray(data.dates)) {
                availabilityByDate = {};
                calendarDataLoaded = true;
                refreshDayCells();
                showAlert('Unable to load available dates.', 'warning');
                return;
            }

            availabilityByDate = {};
            data.dates.forEach(function(item) {
                if (!item || !item.date) return;
                availabilityByDate[String(item.date)] = {
                    available: !!item.available,
                    count: parseInt(item.count || 0, 10) || 0,
                    total: parseInt(item.total || 0, 10) || 0,
                    reason: String(item.reason || ''),
                    doctors_available: parseInt(item.doctors_available || 0, 10) || 0
                };
            });

            calendarDataLoaded = true;
            refreshDayCells();
        } catch (err) {
            console.error(err);
            availabilityByDate = {};
            calendarDataLoaded = true;
            refreshDayCells();
            showAlert('Error loading available dates.', 'danger');
        }
    }

    async function loadTimeslots() {
        const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
        const apptDate = dateSelect ? String(dateSelect.value || '').trim() : '';
        const selectedDuration = Number(window.patientSelectedServiceDuration || 0);
        if (!timeslotWrap || !timeslotSelect) return;

        if (!doctorId) {
            resetTimeslots();
            if (timeslotHint) {
                timeslotHint.textContent = 'Choose a doctor first to see available time slots.';
            }
            return;
        }

        timeslotSelect.innerHTML = '<option value="">-- Select a time slot --</option>';
        if (!apptDate) {
            resetTimeslots();
            if (timeslotHint) {
                timeslotHint.textContent = 'Select a date to see available time slots.';
            }
            return;
        }

        timeslotSelect.disabled = false;
        timeslotSelect.required = true;
        timeslotSelect.innerHTML = '<option value="">Loading time slots...</option>';

        try {
            let url = '../assets/api/appointments/get_timeslots.php?date=' + encodeURIComponent(apptDate);
            if (doctorId) {
                url += '&doctor_id=' + encodeURIComponent(doctorId);
            }
            if (selectedDuration > 0) {
                url += '&duration=' + encodeURIComponent(String(selectedDuration));
            }
            var data;
            if (Cache) {
                data = await Cache.cachedFetch(url, { ttl: 15000 });
            } else {
                const r = await fetch(url);
                data = await r.json();
            }
            timeslotSelect.innerHTML = '<option value="">-- Select a 10-minute slot --</option>';

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

    // Initialize calendar on page load (render immediately, fetch data async)
    renderCalendar();
    loadDates();

    if (doctorSelect) {
        doctorSelect.addEventListener('change', function() {
            calendarDataLoaded = false;
            loadDates();
            loadTimeslots();
        });
    }

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
            if (UI) UI.btnLoading(submitPatientRescheduleBtn, 'Submitting...');
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
                if (Cache) Cache.invalidate('appointments');

                const triggerBtn = document.querySelector('.patient-resched-btn[data-appointment-id="' + appointmentId + '"]');
                if (triggerBtn) {
                    const cell = triggerBtn.closest('.ah-action');
                    if (cell) {
                        cell.innerHTML = '<span class="ah-pending"><i class="bi bi-hourglass-split"></i> Reschedule requested</span>';
                    }
                }
            } catch (err) {
                console.error(err);
                showBookingToast('Server error while submitting request.', 'danger');
            } finally {
                submitPatientRescheduleBtn.disabled = false;
                if (UI) UI.btnRestore(submitPatientRescheduleBtn);
            }
        });
    }

    const form = document.getElementById('createApptForm');
    const symptomChecks = Array.from(document.querySelectorAll('.patient-symptom-check'));
    const notesField = document.getElementById('notes');
    const reasonField = document.getElementById('reason');

    function syncReasonField() {
        if (!reasonField) return;
        const symptoms = symptomChecks
            .filter(function(input) { return input.checked; })
            .map(function(input) { return String(input.value || '').trim(); })
            .filter(Boolean);
        const noteText = notesField ? String(notesField.value || '').trim() : '';
        let reasonText = symptoms.join(', ');
        if (!reasonText) {
            reasonText = noteText || 'General consultation';
        }
        reasonField.value = reasonText;
    }

    symptomChecks.forEach(function(input) {
        input.addEventListener('change', syncReasonField);
    });
    if (notesField) {
        notesField.addEventListener('input', syncReasonField);
    }
    syncReasonField();

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const doctorId = doctorSelect ? String(doctorSelect.value || '').trim() : '';
            if (!doctorId) {
                showAlert('Please choose a doctor before requesting an appointment.', 'warning');
                return;
            }
            const selectedDate = dateSelect ? String(dateSelect.value || '').trim() : '';
            if (!selectedDate) {
                showAlert('Please select an available date on the calendar.', 'warning');
                return;
            }

            const tsId = timeslotSelect ? String(timeslotSelect.value || '').trim() : '';
            if (!tsId) {
                showAlert('Please select a time slot before booking.', 'warning');
                return;
            }

            const selectedDoctor = doctorSelect && doctorId && doctorSelect.selectedOptions.length > 0
                ? String(doctorSelect.selectedOptions[0].textContent || '').trim()
                : 'To be assigned';
            const selectedSlot = timeslotSelect && timeslotSelect.selectedOptions.length > 0
                ? String(timeslotSelect.selectedOptions[0].textContent || '').trim()
                : 'TBD';
            syncReasonField();
            const selectedReason = reasonField ? String(reasonField.value || '').trim() : '';

            const submitBtn = document.getElementById('submitApptBtn');
            if (submitBtn) {
                UI.btnLoading(submitBtn, 'Submitting...');
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
                    UI.btnRestore(submitBtn);
                }
                if (resp.success) {
                    Cache.invalidate('appointments');
                    appendHistoryRow({
                        appointmentId: resp.appointment_id,
                        reference: resp.reference_code || '',
                        date: selectedDate,
                        doctor: selectedDoctor,
                        doctorId: doctorSelect ? String(doctorSelect.value || '') : '',
                        slot: selectedSlot,
                        reason: selectedReason,
                        status: resp.status || 'scheduled'
                    });
                    showBookingToast(resp.message || 'Appointment created successfully.', 'success');

                    form.reset();
                    syncReasonField();
                    clearSelectedDate();
                    // The form reset cleared the doctor, so this redraws the calendar with
                    // the "choose a doctor" prompt instead of leaving it gone until a reload.
                    loadDates();
                    // Reset service selector to initial state
                    const svcEl = document.getElementById('serviceSelector');
                    if (svcEl) svcEl.innerHTML = '';
                    if (typeof initPatientServiceSelectorIfPresent === 'function') {
                        initPatientServiceSelectorIfPresent();
                    }
                } else {
                    const failType = resp.warning ? 'warning' : 'danger';
                    showAlert(resp.message || 'Failed to create appointment.', failType);
                }
            }).catch(function(err) {
                console.error(err);
                if (submitBtn) {
                    UI.btnRestore(submitBtn);
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

    // Auto-refresh: lightweight change detection every 3s, full fetch only when changed
    var Cache = window.DentalCache;
    var UI = window.DentalUI;
    var lastHistoryHash = '';
    var lastSignature = '';
    var pollTimer = null;

    function computeHistoryHash(appointments) {
        return appointments.map(function(a) {
            return a.appointment_id + ':' + (a.reference_code || '') + ':' + a.appointment_date + ':' + a.status + ':' + (a.user_id || 0) + ':' + (a.start_time || '') + ':' + (a.pending_reschedule_count || 0);
        }).join('|');
    }

    function buildHistoryRow(a) {
        var statusKey = (a.status || 'scheduled').toLowerCase();
        var statusLabels = {
            'scheduled': 'Scheduled', 'confirmed': 'Confirmed', 'arrived': 'Arrived', 'checked_in': 'Checked In',
            'in_progress': 'In Progress', 'completed': 'Completed', 'records_complete': 'Completed',
            'awaiting_secretary_records': 'Completed', 'completed_by_doctor': 'Completed', 'missed': 'Missed',
            'cancelled': 'Cancelled', 'no_show': 'No Show', 'rescheduled': 'Rescheduled'
        };
        var statusBadges = {
            'scheduled': 'secondary', 'confirmed': 'primary', 'arrived': 'success', 'checked_in': 'info',
            'in_progress': 'warning', 'completed': 'success', 'records_complete': 'success',
            'awaiting_secretary_records': 'success', 'completed_by_doctor': 'success', 'missed': 'warning',
            'cancelled': 'danger', 'no_show': 'danger', 'rescheduled': 'secondary'
        };
        var dayStr = String(a.appointment_date || '').slice(0, 10);
        var apptDay = /^\d{4}-\d{2}-\d{2}$/.test(dayStr) ? new Date(dayStr + 'T00:00:00') : null;
        // Missed = still scheduled/confirmed after the slot ended (or after the day, if no slot).
        var endAt = apptDay && a.end_time ? new Date(dayStr + 'T' + String(a.end_time).slice(0, 8)) : null;
        var cutoff = endAt || (apptDay ? new Date(apptDay.getTime() + 86400000) : null);
        if (['scheduled', 'confirmed'].indexOf(statusKey) >= 0 && cutoff && cutoff < new Date()) statusKey = 'missed';

        var label = statusLabels[statusKey] || statusKey.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        var badge = statusBadges[statusKey] || 'secondary';
        var upcoming = ['scheduled', 'confirmed'].indexOf(statusKey) >= 0;

        var doctorName = (a.doctor_name || ((a.doctor_first_name || '') + ' ' + (a.doctor_last_name || ''))).trim();
        doctorName = doctorName ? 'Dr. ' + doctorName.replace(/^dr\.?\s*/i, '') : 'Doctor to be assigned';
        var slotLabel = a.slot_label || (a.start_time && a.end_time ? formatTime12(a.start_time) + ' \u2013 ' + formatTime12(a.end_time) : 'Time to be assigned');
        var reason = String(a.reason || '').trim();
        if (reason.toUpperCase() === 'N/A') reason = '';
        var services = String(a.services || '').trim();
        // What the visit is for: the services booked, else the reason given.
        var title = services || reason || 'Dental appointment';
        var description = services ? reason : '';

        var dateHtml = apptDay
            ? '<small class="ah-dow">' + escapeHtml(apptDay.toLocaleDateString(undefined, { weekday: 'short' })) + '</small><strong>' + apptDay.getDate() + '</strong><small>' + escapeHtml(apptDay.toLocaleDateString(undefined, { month: 'short' }) + ' ' + apptDay.getFullYear()) + '</small>'
            : '<strong>\u2014</strong>';
        var fullDate = apptDay ? apptDay.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) : '';

        var hasPending = (a.pending_reschedule_count || 0) > 0;
        var canResched = ['scheduled', 'confirmed', 'missed'].indexOf(statusKey) >= 0 && !hasPending;
        var actionHtml = '';
        if (canResched) {
            actionHtml = '<button type="button" class="btn btn-sm btn-outline-primary ah-btn patient-resched-btn" data-appointment-id="' + escapeHtml(a.appointment_id) + '" data-doctor-id="' + escapeHtml(a.user_id || 0) + '" data-current-date="' + escapeHtml(dayStr) + '"><i class="bi bi-calendar2-week"></i> Reschedule</button>';
        } else if (hasPending) {
            actionHtml = '<span class="ah-pending"><i class="bi bi-hourglass-split"></i> Reschedule requested</span>';
        }

        var sub = [slotLabel, doctorName];
        if (a.reference_code) sub.push('Ref ' + a.reference_code);

        return '<div class="ah-row patient-appt-history-row' + (upcoming ? ' is-upcoming' : '') + '">' +
            '<span class="ah-date" title="' + escapeHtml(fullDate) + '">' + dateHtml + '</span>' +
            '<span class="ah-main">' +
                '<span class="ah-name">' + escapeHtml(title) + '</span>' +
                (description ? '<span class="ah-desc">' + escapeHtml(description) + '</span>' : '') +
                '<span class="ah-meta"><i class="bi bi-clock"></i> ' + escapeHtml(sub.join(' \u00b7 ')) + '</span>' +
            '</span>' +
            '<span class="ah-status"><span class="badge bg-' + badge + '">' + escapeHtml(label) + '</span></span>' +
            '<span class="ah-action">' + actionHtml + '</span>' +
        '</div>';
    }

    function formatTime12(timeStr) {
        if (!timeStr) return '';
        var parts = timeStr.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var ampm = h >= 12 ? 'PM' : 'AM';
        if (h > 12) h -= 12;
        if (h === 0) h = 12;
        return h + ':' + m + ' ' + ampm;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str || '')));
        return div.innerHTML;
    }

    function updateHistoryTable(appointments) {
        if (!historyBody) return;
        var html = '';
        if (appointments.length === 0) {
            html = '<div class="dp-note patient-appt-history-empty"><i class="bi bi-calendar-x"></i><span>No appointments yet. Book one above and it will appear here.</span></div>';
        } else {
            appointments.forEach(function(a) { html += buildHistoryRow(a); });
        }
        historyBody.innerHTML = html;

        if (apptHistorySearchInput) {
            filterAppointmentHistory(apptHistorySearchInput.value);
        }
    }

    async function pollHistory() {
        try {
            var data = await Cache.cachedFetch('../assets/api/appointments/get_patient_history.php', {
                ttl: 10000, // cache for 10 seconds
                force: true // always fetch fresh on change
            });
            if (!data.success || !Array.isArray(data.appointments)) return;

            var newHash = computeHistoryHash(data.appointments);
            if (newHash !== lastHistoryHash) {
                lastHistoryHash = newHash;
                updateHistoryTable(data.appointments);
            }
        } catch (e) { /* silent */ }
    }

    async function checkForChanges() {
        try {
            var url = '../assets/api/appointments/check_patient_changes.php?last=' + encodeURIComponent(lastSignature);
            var data = await Cache.cachedFetch(url, { ttl: 2000, force: true });
            if (data.sig) lastSignature = data.sig;
            if (data.changed) {
                Cache.invalidate('get_patient_history');
                await pollHistory();
            }
        } catch (e) { /* silent */ }
    }

    // First render from the data the page was built with, then keep it live.
    (function() {
        var initial = Array.isArray(window.PATIENT_APPT_HISTORY) ? window.PATIENT_APPT_HISTORY : [];
        updateHistoryTable(initial);
        lastHistoryHash = computeHistoryHash(initial);
    })();

    // Fast polling: check for changes every 3 seconds
    pollTimer = setInterval(checkForChanges, 3000);
});
</script>
