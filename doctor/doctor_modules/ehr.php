<?php
// EHR view (renamed from previous profiling implementation)
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
check_session(['doctor']);

// Allow either appointment_id via GET OR selection by queue number
$appointment_id = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;

// If no appointment_id provided, fetch today's arrived patients for this doctor
$queueItems = [];
if (!$appointment_id) {
    $sql = "SELECT
                                a.appointment_id,
                                a.is_emergency,
                                a.reason,
                                a.notes,
                                a.is_online_appointment,
                                a.patient_id,
                                p.first_name,
                                p.last_name,
                                p.email,
                                p.phone_number,
                                p.profile_image,
                                COALESCE(t.slot_date, a.appointment_date) AS appointment_date,
                                CASE
                                    WHEN a.status = 'in_progress' THEN 'in_progress'
                                    WHEN a.status = 'arrived' THEN 'arrived'
                                    WHEN a.status = 'checked_in' THEN 'arrived'
                                    WHEN a.status = 'no_show' THEN 'no_show'
                                    ELSE 'pending'
                                END AS arrival_status,
                                t.start_time AS expected_time,
                                a.status AS appointment_status,
                                t.start_time,
                                cr.record_id AS record_id,
                                cr.status AS record_status
                        FROM appointments a
                        LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
                        LEFT JOIN clinical_records cr ON cr.appointment_id = a.appointment_id
                        JOIN patients p ON a.patient_id = p.patient_id
                        WHERE COALESCE(a.user_id, t.doctor_id) = ?
                            AND COALESCE(t.slot_date, a.appointment_date) = ?
                            AND (
                                a.status IN ('arrived','checked_in','in_progress','confirmed')
                                OR (cr.record_id IS NOT NULL AND a.status NOT IN ('completed','records_complete','cancelled','no_show'))
                            )
                        ORDER BY a.is_emergency DESC, COALESCE(t.start_time, '00:00:00') ASC";

    $qstmt = $conn->prepare($sql);
    if ($qstmt) {
        $today = date('Y-m-d');
        $qstmt->bind_param('is', $_SESSION['user_id'], $today);
        $qstmt->execute();
        $qres = $qstmt->get_result();
        while ($qr = $qres->fetch_assoc())
            $queueItems[] = $qr;
        $qstmt->close();
    }
} else {
    // preload appointment when appointment_id provided
    $stmt = $conn->prepare('SELECT a.*, p.first_name, p.last_name, p.email, p.phone_number, p.profile_image FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($appt) {
        // populate a single queue-like item so UI can reuse the same dropdown/prefill logic
        $queueItems[] = [
            'appointment_id' => $appt['appointment_id'],
            'patient_id' => $appt['patient_id'],
            'first_name' => $appt['first_name'],
            'last_name' => $appt['last_name'],
            'appointment_date' => $appt['appointment_date'],
            'reason' => $appt['reason'] ?? '',
            'notes' => $appt['notes'] ?? '',
            'is_emergency' => $appt['is_emergency'] ?? 0,
            'is_online_appointment' => $appt['is_online_appointment'] ?? 0,
            'email' => $appt['email'] ?? '',
            'phone_number' => $appt['phone_number'] ?? '',
            'profile_image' => $appt['profile_image'] ?? '',
            'appointment_status' => $appt['status'] ?? '',
        ];
    }
}

// Fetch services
$services = [];
$sstmt = $conn->prepare('SELECT service_id, service_name, initial_price, requires_schedule, default_duration_minutes FROM services WHERE is_active = 1');
if ($sstmt) {
    $sstmt->execute();
    $sres = $sstmt->get_result();
    while ($r = $sres->fetch_assoc())
        $services[] = $r;
    $sstmt->close();
}

?>
<?php $queueCount = $appointment_id ? 0 : count($queueItems); ?>
<div class="ses is-idle" id="sesRoot">
    <input type="hidden" name="appointment_id" id="appointment_id_hidden" value="<?php echo (int) $appointment_id; ?>">
    <input type="hidden" name="schedule_id" id="schedule_id_hidden"
        value="<?php echo isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : ''; ?>">

    <header class="ses-head">
        <div>
            <h2 class="ses-head__title">Patient Visit <button type="button" class="ses-tip" aria-label="Pick the patient in the chair, then chart their teeth, record findings and notes, and add the services done and any photos. Save the chart any time. Complete visit sends everything to the secretary for records and billing." data-tip="Pick the patient in the chair, then chart their teeth, record findings and notes, and add the services done and any photos. Save the chart any time. Complete visit sends everything to the secretary for records and billing."><i class="bi bi-exclamation-circle" aria-hidden="true"></i></button></h2>
        </div>
        <?php if (!$appointment_id): ?>
        <span class="ses-head__queue"><i class="bi bi-people" aria-hidden="true"></i> <?php echo $queueCount; ?> in today's queue</span>
        <?php endif; ?>
    </header>

    <!-- Who is in the chair: queue picker, then a compact patient card once someone is picked.
         The avatar and the "Patient details" button both open the full details modal. -->
    <section class="ses-patient">
        <div class="ses-patient__pick">
            <label class="ses-label" for="queueSelector">Patient in the chair</label>
            <select id="queueSelector" class="form-select">
                <option value=""><?php echo ($queueCount || $appointment_id) ? "Choose a patient from today's queue" : "No patients in today's queue"; ?></option>
                <?php foreach ($queueItems as $qi): ?>
                    <?php
                        $display = trim($qi['first_name'] . ' ' . (!empty($qi['middle_name']) && strtoupper(trim((string)$qi['middle_name'])) !== 'N/A' ? $qi['middle_name'] . ' ' : '') . $qi['last_name']);
                        $qiStartTime = !empty($qi['start_time']) ? date('g:i A', strtotime($qi['start_time'])) : '';
                        if ($qiStartTime !== '') $display = $qiStartTime . ' · ' . $display;
                        if (!empty($qi['is_emergency'])) $display .= ' (Emergency)';
                        if (!empty($qi['record_id'])) $display .= ' · record started';
                    ?>
                    <option value="<?php echo (int) $qi['appointment_id']; ?>" data-emergency="<?php echo !empty($qi['is_emergency']) ? '1' : '0'; ?>" data-has-record="<?php echo !empty($qi['record_id']) ? '1' : '0'; ?>" data-patient-id="<?php echo (int) $qi['patient_id']; ?>" data-patient-name="<?php echo htmlspecialchars(trim($qi['first_name'] . ' ' . (!empty($qi['middle_name']) && strtoupper(trim((string)$qi['middle_name'])) !== 'N/A' ? $qi['middle_name'] . ' ' : '') . $qi['last_name']), ENT_QUOTES); ?>" data-reason="<?php echo htmlspecialchars((string) ($qi['reason'] ?? ''), ENT_QUOTES); ?>" data-notes="<?php echo htmlspecialchars((string) ($qi['notes'] ?? ''), ENT_QUOTES); ?>" data-appt-date="<?php echo htmlspecialchars((string) ($qi['appointment_date'] ?? ''), ENT_QUOTES); ?>" data-appt-time="<?php echo htmlspecialchars($qiStartTime, ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars((string) ($qi['email'] ?? ''), ENT_QUOTES); ?>" data-phone="<?php echo htmlspecialchars((string) ($qi['phone_number'] ?? ''), ENT_QUOTES); ?>" data-online="<?php echo !empty($qi['is_online_appointment']) ? '1' : '0'; ?>" data-status="<?php echo htmlspecialchars((string) ($qi['appointment_status'] ?? ''), ENT_QUOTES); ?>" data-profile-image="<?php echo htmlspecialchars((string) ($qi['profile_image'] ?? ''), ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($display); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ehr-patient-card" id="ehrPatientCard" style="display:none;">
            <button type="button" class="ehr-patient-avatar" id="ehrPatientAvatarBtn" title="View full patient details" onclick="openDoctorPatientDetailsModal()">
                <img id="ehrPatientAvatarImg" alt="" style="display:none;">
                <i class="bi bi-person-fill" id="ehrPatientAvatarIcon"></i>
            </button>
            <div class="ehr-patient-meta">
                <div class="ehr-patient-name" id="ehrPatientName">—</div>
                <div class="ehr-patient-chips" id="ehrPatientChips"></div>
            </div>
            <button type="button" class="ses-btn ses-btn--ghost ehr-patient-details-btn" onclick="openDoctorPatientDetailsModal()">
                <i class="bi bi-person-lines-fill"></i> Patient details
            </button>
        </div>
    </section>

    <div class="ses-idle-note" id="sesIdleNote">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        <div>
            <strong>Choose a patient to start their visit.</strong>
        </div>
    </div>

    <div class="ses-work" id="sesWork">
        <!-- Dental chart: the full odontogram, opened on demand. -->
        <section class="ses-card">
            <div class="ses-card__head">
                <span class="ses-card__icon"><i class="bi bi-grid-3x3-gap"></i></span>
                <div class="ses-card__heading">
                    <h3 class="ses-card__title">Dental chart</h3>
                </div>
                <div class="ses-card__actions">
                    <button type="button" class="ses-btn ses-btn--ghost collapsed" id="sesChartToggle" data-bs-toggle="collapse" data-bs-target="#chartingSection" aria-expanded="false" aria-controls="chartingSection">
                        <i class="bi bi-chevron-down ses-chev"></i> <span class="ses-chart-toggle-label">Open chart</span>
                    </button>
                </div>
            </div>
            <div class="collapse" id="chartingSection">
                <div class="ses-chart">
                    <div class="ses-chart__bar">
                        <button type="button" id="openOdontogramFullscreenBtn" class="ses-btn ses-btn--ghost">Full screen</button>
                        <button type="button" id="saveChartBtn" class="ses-btn ses-btn--primary">
                            <i class="bi bi-save"></i> Save chart
                        </button>
                    </div>
                    <div id="odontogramViewport" class="odontogram-viewport">
                        <button type="button" id="closeOdontogramFullscreenBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                            <i class="bi bi-x-lg"></i> Close
                        </button>
                        <div id="odontogramContainer"></div>
                        <?php odontogram_render_rotate_prompt(); ?>
                    </div>
                    <input type="hidden" id="odontogram_data" name="odontogram">
                </div>
            </div>
        </section>

        <div class="ses-grid">
            <!-- Services done this visit: what billing is built from. -->
            <section class="ses-card">
                <div class="ses-card__head">
                    <span class="ses-card__icon"><i class="bi bi-receipt"></i></span>
                    <div class="ses-card__heading">
                        <h3 class="ses-card__title">Services done this visit</h3>
                    </div>
                </div>
                <label class="visually-hidden" for="ehrServiceSelect">Add a service</label>
                <select id="ehrServiceSelect" class="form-select">
                    <option value="">Add a service…</option>
                    <?php foreach ($services as $svc): ?>
                        <option value="<?php echo (int)$svc['service_id']; ?>"
                            data-price="<?php echo (float)$svc['initial_price']; ?>"
                            data-name="<?php echo htmlspecialchars($svc['service_name'], ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($svc['service_name']); ?> — ₱<?php echo number_format($svc['initial_price'], 0); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <table class="ses-services" data-skip-table-search="1">
                    <thead class="visually-hidden">
                        <tr><th>Service</th><th>Price</th><th>Remove</th></tr>
                    </thead>
                    <tbody id="ehrServicesBody">
                        <tr><td colspan="3" class="ses-services__empty">No services added yet.</td></tr>
                    </tbody>
                    <tfoot id="ehrServicesFoot">
                    </tfoot>
                </table>
            </section>

            <div class="ses-stack">
                <!-- Clinical findings + doctor's notes (modal). -->
                <section class="ses-card">
                    <div class="ses-card__head">
                        <span class="ses-card__icon"><i class="bi bi-clipboard2-pulse"></i></span>
                        <div class="ses-card__heading">
                            <h3 class="ses-card__title">Findings &amp; notes</h3>
                        </div>
                        <div class="ses-card__actions">
                            <button type="button" id="viewClinicalFindingsBtn" class="ses-btn ses-btn--primary" disabled onclick="openDoctorClinicalFindingsModal()">
                                <i class="bi bi-pencil-square"></i> Record
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Clinical photos: uploaded with the visit when it is completed. -->
                <section class="ses-card">
                    <div class="ses-card__head">
                        <span class="ses-card__icon"><i class="bi bi-images"></i></span>
                        <div class="ses-card__heading">
                            <h3 class="ses-card__title">Photos</h3>
                        </div>
                        <div class="ses-card__actions">
                            <input type="file" id="finishPhotoUpload" accept="image/*" multiple hidden>
                            <button type="button" class="ses-btn ses-btn--ghost" onclick="document.getElementById('finishPhotoUpload').click()" title="Upload photos">
                                <i class="bi bi-upload"></i> Upload
                            </button>
                            <button type="button" class="ses-btn ses-btn--ghost" data-bs-toggle="modal" data-bs-target="#cameraModal" title="Take a photo">
                                <i class="bi bi-camera"></i> Camera
                            </button>
                        </div>
                    </div>
                    <div id="photoGalleryContainer" class="ses-photos">
                        <span class="ses-photos__empty" id="noPhotosMsg">No photos yet.</span>
                    </div>
                </section>
            </div>
        </div>

        <!-- Treatment plan (multi-visit; an estimate only — billed via the services above when done). -->
        <section class="ses-card ses-card--plan">
            <div id="ehrTreatmentPlan">
                <div class="tp-empty">Select a patient to see their treatment plan.</div>
            </div>
        </section>
    </div>
    <!-- Camera Modal -->
    <div class="modal fade" id="cameraModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title"><i class="fas fa-camera"></i> Take Photo</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-2">
                    <video id="cameraPreviewVideo" style="width:100%;max-height:350px;border-radius:8px;"></video>
                    <canvas id="cameraCaptureCanvas" style="display:none;"></canvas>
                    <div id="capturedPreview" class="mt-2" style="display:none;">
                        <img id="capturedPreviewImg" style="max-width:100%;max-height:250px;border-radius:8px;">
                    </div>
                </div>
                <div class="modal-footer py-2 justify-content-center gap-2">
                    <button type="button" class="btn btn-primary btn-sm" id="capturePhotoBtn">
                        <i class="fas fa-camera"></i> Capture
                    </button>
                    <button type="button" class="btn btn-success btn-sm" id="confirmPhotoBtn" style="display:none;">
                        <i class="fas fa-check"></i> Use Photo
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" id="retakePhotoBtn" style="display:none;">
                        <i class="fas fa-redo"></i> Retake
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Photo Viewer Modal -->
    <div class="modal fade" id="photoViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header py-1 border-0">
                    <span class="text-white small" id="photoViewerTitle">Photo</span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-2">
                    <img id="photoViewerImg" style="max-width:100%;max-height:70vh;border-radius:8px;">
                </div>
            </div>
        </div>
    </div>
    <div class="ses-finish" id="sesFinish">
        <div class="ses-finish__text">
            <strong>Done with this patient?</strong>
        </div>
        <button type="button" id="submitFinishAppointmentBtn" class="ses-btn ses-btn--primary ses-btn--lg">
            <i class="bi bi-check2-circle"></i> Complete visit
        </button>
    </div>
</div>

<!-- Clinical Findings Modal — the doctor inputs clinical findings here during their own
     session (Occlusal Classification is what's actually used day-to-day, so it's shown
     first, with the doctor's own notes right after it; Clinical Assessment / Vitals & Lab
     are optional), pre-filled with whatever the secretary already captured during Create
     Record if any. -->
<div class="modal fade" id="doctorClinicalFindingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" style="font-size:1rem;"><i class="bi bi-clipboard2-pulse"></i> Clinical Findings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="doctorFindingsBody">
                    <div class="text-center text-muted py-5"><i class="bi bi-hourglass-split"></i> Loading...</div>
                </div>
                <!-- Parked here while the findings load; placeDoctorNotesSection() moves it to
                     second place (after Occlusal Classification) once they render. -->
                <div id="doctorNotesHolder">
                    <div id="doctorNotesSection" class="pf-section mb-2">
                        <div class="pf-section__title"><i class="bi bi-journal-medical"></i> Doctor's Notes</div>
                        <textarea id="doctor_notes" class="form-control form-control-sm" rows="3" placeholder="Notes..." style="font-size:0.82rem;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" id="saveClinicalFindingsBtn" class="btn btn-primary btn-sm">
                    <i class="bi bi-save"></i> Save Findings
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Patient Details Modal — one place for everything about the currently selected
     patient, opened from the patient card's avatar/"Patient Details" button. Tabs let
     the doctor page through Profile, this Appointment, and past Records without
     juggling several separate popups. -->
<div class="modal fade" id="doctorPatientDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" style="font-size:1rem;"><i class="bi bi-person-lines-fill"></i> Patient Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <!-- Identity strip (photo + name + age/gender/phone) — shown once here for all
                 three tabs instead of being repeated inside the Profile tab's content. -->
            <div class="pd-header-bar" id="pdHeaderBar">
                <div class="pd-header-avatar" id="pdHeaderAvatar">
                    <img id="pdHeaderAvatarImg" alt="" style="display:none;">
                    <i class="bi bi-person-fill" id="pdHeaderAvatarIcon"></i>
                </div>
                <div class="pd-header-meta">
                    <div class="pd-header-name" id="pdHeaderName">—</div>
                    <div class="dpp-chips" id="pdHeaderChips"></div>
                </div>
            </div>
            <div class="modal-body p-0">
                <ul class="nav nav-tabs px-3 pt-2" id="pdTabsNav" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pdTabProfileBtn" data-bs-toggle="tab" data-bs-target="#pdTabProfile" type="button" role="tab">Profile</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pdTabAppointmentBtn" data-bs-toggle="tab" data-bs-target="#pdTabAppointment" type="button" role="tab">Appointment</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pdTabRecordsBtn" data-bs-toggle="tab" data-bs-target="#pdTabRecords" type="button" role="tab">Records</button>
                    </li>
                </ul>
                <div class="tab-content p-3">
                    <div class="tab-pane fade show active" id="pdTabProfile" role="tabpanel">
                        <div id="pdProfileBody">
                            <div class="text-center text-muted py-5"><i class="bi bi-hourglass-split"></i> Loading...</div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="pdTabAppointment" role="tabpanel">
                        <div id="pdAppointmentBody"></div>
                    </div>
                    <div class="tab-pane fade" id="pdTabRecords" role="tabpanel">
                        <div class="small text-muted mb-2">Past visits for this patient — pick one to view its details.</div>
                        <div class="d-flex flex-wrap gap-2 mb-3" id="pdRecordsVisitPicker"></div>
                        <div id="pdRecordsBody"><span class="text-muted small">No visit selected.</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// The original profiling.js logic is intentionally preserved in this file to
// maintain existing behavior for saving clinical records, scheduling, and
// odontogram integration. No additional server endpoints are required.
?>

<style>
/* ---------- Patient Visit page (.ses-*): same cards, type and colors as the Patients page ---------- */
.ses { display: flex; flex-direction: column; gap: 14px; }
.ses-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
#main .ses-head__title { margin: 0; font-size: 1.25rem; font-weight: 700; color: #0f172a; }
.ses-head__queue {
    display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px;
    background: #eff8fb; color: #17698c; font-size: 0.8rem; font-weight: 700; white-space: nowrap;
}

.ses-label { display: block; margin-bottom: 5px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }

/* Who is in the chair */
.ses-patient {
    display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;
    background: #fff; border: 1px solid #e5eaf0; border-radius: 12px; padding: 14px 16px;
}
.ses-patient__pick { flex: 1 1 280px; min-width: 0; }
.ehr-patient-card {
    display: flex; align-items: center; gap: 12px; flex: 2 1 380px; min-width: 0;
    background: #f8fafc; border: 1px solid #e5eaf0; border-radius: 12px; padding: 8px 12px;
}
.ehr-patient-avatar {
    flex: 0 0 44px; height: 44px; border-radius: 50%; border: 0; padding: 0; overflow: hidden; cursor: pointer;
    display: flex; align-items: center; justify-content: center; background: #dcf0f7;
    transition: box-shadow .15s ease;
}
.ehr-patient-avatar:hover { box-shadow: 0 0 0 3px #cfe3ec; }
.ehr-patient-avatar img { width: 100%; height: 100%; object-fit: cover; }
.ehr-patient-avatar i { font-size: 1.3rem; color: #17698c; }
.ehr-patient-meta { flex: 1; min-width: 0; }
.ehr-patient-name { font-weight: 700; font-size: 0.98rem; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ehr-patient-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.ehr-patient-details-btn { flex-shrink: 0; }
.dpp-chip.ehr-chip--alert { background: #fef2f2; color: #b91c1c; }

/* Nothing to work on until a patient is chosen */
.ses-idle-note {
    display: none; align-items: center; gap: 12px; padding: 18px; border: 1px dashed #d6dee8; border-radius: 12px;
    background: #fafcfe; color: #64748b; font-size: 0.86rem;
}
.ses-idle-note > i { font-size: 1.5rem; color: #94a3b8; }
.ses-idle-note strong { display: block; color: #334155; font-size: 0.92rem; }
.ses.is-idle .ses-idle-note { display: flex; }
.ses.is-idle .ses-work, .ses.is-idle .ses-finish { opacity: .45; pointer-events: none; user-select: none; }

/* Work area */
.ses-work { display: flex; flex-direction: column; gap: 14px; transition: opacity .2s ease; }
.ses-grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 14px; align-items: start; }
.ses-stack { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.ses-card { background: #fff; border: 1px solid #e5eaf0; border-radius: 12px; padding: 14px 16px; min-width: 0; }
.ses-card__head { display: flex; align-items: center; gap: 12px; }
.ses-card__head + * { margin-top: 12px; }
.ses-card__icon {
    flex: 0 0 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
    background: #eff8fb; color: #1b7aa3; font-size: 1.05rem;
}
.ses-card__heading { flex: 1 1 auto; min-width: 0; }
#main .ses-card__title { margin: 0; font-size: 0.95rem; font-weight: 700; color: #0f172a; }
.ses-card__actions { display: flex; flex-wrap: wrap; gap: 6px; flex: 0 0 auto; }
.ses-card--plan { padding: 14px 16px; }

/* Page guide: the ! beside the title shows a summary on hover, or on tap/focus on touch. */
.ses-tip {
    position: relative; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle;
    width: 20px; height: 20px; margin-left: 2px; padding: 0; border: 0; border-radius: 50%;
    background: none; color: #94a3b8; font-size: 0.9rem; line-height: 1; cursor: help;
}
.ses-tip:hover, .ses-tip:focus-visible { color: #1b7aa3; }
.ses-tip:focus-visible { outline: 2px solid rgba(27, 122, 163, .4); outline-offset: 1px; }
.ses-tip::after {
    content: attr(data-tip); position: absolute; left: 50%; top: calc(100% + 8px); z-index: 20;
    width: max-content; max-width: min(340px, 70vw); transform: translateX(-50%) translateY(4px);
    padding: 7px 10px; border-radius: 8px; background: #0f172a; color: #fff;
    font-size: 0.76rem; font-weight: 500; line-height: 1.4; text-align: left; text-transform: none; letter-spacing: 0; white-space: normal;
    box-shadow: 0 8px 20px -8px rgba(15, 23, 42, .5);
    opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .15s ease, transform .15s ease, visibility .15s;
}
.ses-tip:hover::after, .ses-tip:focus::after { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
/* Opens to the right of the icon so it stays on screen. */
.ses-head__title .ses-tip::after { left: 0; transform: translateY(4px); }
.ses-head__title .ses-tip:hover::after, .ses-head__title .ses-tip:focus::after { transform: translateY(0); }

@media (max-width: 575.98px) {
    /* On phones the bubble becomes a strip at the bottom of the screen, so it always fits. */
    .ses .ses-tip::after,
    .ses .ses-tip:hover::after, .ses .ses-tip:focus::after {
        position: fixed; left: 16px; right: 16px; top: auto; bottom: 16px; z-index: 1080;
        width: auto; max-width: none; transform: none; font-size: 0.82rem; padding: 10px 12px;
    }
}

/* Buttons: one primary teal, one quiet outline */
.ses-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap;
    border: 1px solid transparent; border-radius: 999px; padding: 6px 14px;
    font: inherit; font-size: 0.82rem; font-weight: 600; cursor: pointer;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.ses-btn:disabled { opacity: .5; cursor: not-allowed; }
.ses-btn:focus-visible { outline: 3px solid rgba(27, 122, 163, .35); outline-offset: 2px; }
.ses-btn--primary { background: #1b7aa3; border-color: #1b7aa3; color: #fff; }
.ses-btn--primary:not(:disabled):hover { background: #17698c; border-color: #17698c; }
.ses-btn--ghost { background: #fff; border-color: #cfe3ec; color: #17698c; }
.ses-btn--ghost:not(:disabled):hover { background: #f3fafd; border-color: #1b7aa3; }
.ses-btn--lg { padding: 10px 22px; font-size: 0.92rem; }
.ses-chev { transition: transform .2s ease; }
#sesChartToggle:not(.collapsed) .ses-chev { transform: rotate(180deg); }

/* Dental chart */
.ses-chart { margin-top: 12px; }
.ses-chart__bar { display: flex; justify-content: flex-end; gap: 6px; margin-bottom: 8px; }

/* Services list */
.ses-services { width: 100%; margin-top: 10px; border-collapse: collapse; font-size: 0.86rem; }
.ses-services td { padding: 8px 4px; border-bottom: 1px dashed #e5eaf0; color: #0f172a; vertical-align: middle; }
.ses-services td:nth-child(2) { text-align: right; white-space: nowrap; font-weight: 600; }
.ses-services td:nth-child(3) { width: 34px; text-align: right; }
.ses-services td:nth-child(3) .btn { color: #94a3b8 !important; font-size: 1.05rem; }
.ses-services td:nth-child(3) .btn:hover { color: #dc2626 !important; }
.ses-services__empty, .ses-services td.text-center { text-align: center; color: #94a3b8 !important; font-size: 0.84rem; padding: 14px 4px !important; border-bottom: 0 !important; }
.ses-services tfoot td { border-bottom: 0; padding-top: 10px; font-size: 0.9rem !important; }
.ehr-services-total-row td:first-child { color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; font-size: 0.74rem !important; }
.ehr-services-total-row td:nth-child(2) { color: #17698c; font-size: 1rem !important; }

/* Photos */
.ses-photos { display: flex; flex-wrap: wrap; gap: 8px; }
.ses-photos > div { border-radius: 10px !important; border-color: #e5eaf0 !important; }
.ses-photos__empty, .ses-photos > .text-muted { font-size: 0.82rem; color: #94a3b8 !important; }

/* Complete visit */
.ses-finish {
    position: sticky; bottom: 12px; z-index: 5;
    display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
    background: #fff; border: 1px solid #cfe3ec; border-radius: 14px; padding: 12px 16px;
    box-shadow: 0 10px 30px -18px rgba(15, 35, 60, .45);
}
.ses-finish__text { min-width: 0; font-size: 0.82rem; color: #64748b; }
.ses-finish__text strong { display: block; color: #0f172a; font-size: 0.92rem; }

@media (max-width: 991.98px) {
    .ses-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 575.98px) {
    .ses-card__head { flex-wrap: wrap; }
    .ses-card__heading { flex: 1 1 0; }
    .ses-card__actions { flex-basis: 100%; padding-left: 48px; }
    .ehr-patient-card { flex-wrap: wrap; }
    .ehr-patient-details-btn { flex-basis: 100%; }
    .ses-finish .ses-btn { flex-basis: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .ses-work, .ses-chev, .ses-btn { transition: none; }
}

/* Patient Details modal identity strip — photo + name + Age/Gender/Phone, shown once
   for all three tabs instead of being repeated inside the Profile tab's content. */
.pd-header-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1rem;
    border-bottom: 1px solid #e6ebf1;
}
.pd-header-avatar {
    flex-shrink: 0;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #dcf0f7;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.pd-header-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.pd-header-avatar i {
    font-size: 1.5rem;
    color: #17698c;
}
.pd-header-name {
    font-weight: 700;
    font-size: 1.05rem;
    color: #0f172a;
}
/* Clinical Findings & Patient Details modals (.pf-* classes) — same grouped-section/
   pill-selector look the secretary's Create Record form uses, scoped to these modals. */
#doctorClinicalFindingsModal .pf-section,
#doctorPatientDetailsModal .pf-section {
    background: #f8fafc;
    border: 1px solid #e6ebf1;
    border-radius: 10px;
    padding: 0.8rem 0.9rem;
    margin-bottom: 0.75rem;
}
#doctorClinicalFindingsModal .pf-section__title,
#doctorPatientDetailsModal .pf-section__title {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #1b7aa3;
    margin-bottom: 0.65rem;
}
#doctorClinicalFindingsModal .pf-pill-group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
#doctorClinicalFindingsModal .pf-pill-group input.pf-check {
    position: absolute;
    clip: rect(0, 0, 0, 0);
    pointer-events: none;
}
#doctorClinicalFindingsModal .pf-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #fff;
    font-size: 0.74rem;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
    user-select: none;
    line-height: 1.3;
}
#doctorClinicalFindingsModal .pf-pill:hover {
    border-color: #1b7aa3;
    color: #1b7aa3;
}
#doctorClinicalFindingsModal .pf-check:checked + .pf-pill {
    background: #1b7aa3;
    border-color: #1b7aa3;
    color: #fff;
    box-shadow: 0 2px 6px rgba(27, 122, 163, 0.25);
}
#doctorClinicalFindingsModal .pf-check:focus-visible + .pf-pill {
    outline: 2px solid #1b7aa3;
    outline-offset: 1px;
}.odontogram-viewport {
    position: relative;
    width: 100%;
}

#odontogramContainer {
    width: 100%;
    height: <?php echo (int) odontogram_embed_height_px(); ?>px;
}

#odontogramContainer iframe,
#odontogramFrame {
    display: block;
    width: 100% !important;
    height: 100% !important;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.odontogram-viewport:fullscreen,
.odontogram-viewport:-webkit-full-screen {
    width: 100vw;
    height: 100vh;
    background: #0b0f19;
    padding: 0;
}

.odontogram-viewport:fullscreen #odontogramContainer,
.odontogram-viewport:-webkit-full-screen #odontogramContainer {
    height: 100% !important;
}

.odontogram-fs-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 30;
}

@media (max-width: 768px) {
    #odontogramContainer {
        height: 520px;
    }
}
@media (max-width: 480px) {
    #odontogramContainer {
        height: 440px;
    }
}

/* Patient Profile modal (.dpp-* classes) now styled globally in
   assets/css/module-content-ux.css so it's shared with other "Profile" popups. */
</style>

<script>
    const doctorCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const ODONTOGRAM_IFRAME_SRC = <?php echo json_encode(odontogram_embed_src()); ?>;
    const ODONTOGRAM_IFRAME_HEIGHT = <?php echo (int) odontogram_embed_height_px(); ?>;
    // Basic EHR frontend behavior: materials list, save draft / finalize, prefill
    (function() {
        let uploadedPhotos = [];

        // Prefill clinical record for an appointment
        async function prefillClinicalRecord(appointmentId) {
            if (!appointmentId) return;
            try {
                const r = await fetch('../assets/api/ehr/get_clinical_record.php?appointment_id=' + encodeURIComponent(appointmentId));
                const data = await r.json();
                if (!data) return;
                const rec = data.record || {};

                if (rec.doctor_notes) document.getElementById('doctor_notes').value = rec.doctor_notes;

                const odEl = document.getElementById('odontogram_data');
                if (odEl) {
                    odEl.value = rec.odontogram ? JSON.stringify(rec.odontogram) : '';
                }
                syncOdontogramFrame();

                // Pre-load services selected by the secretary at booking/arrival — present
                // either on the clinical record (rec.selected_services, once a record exists)
                // or at the top level (data.selected_services, when the doctor hasn't started
                // charting yet and no clinical_records row exists for this appointment).
                const preSelectedServices = (Array.isArray(rec.selected_services) && rec.selected_services.length > 0)
                    ? rec.selected_services
                    : (Array.isArray(data.selected_services) ? data.selected_services : []);
                if (preSelectedServices.length > 0) {
                    ehrSelectedServices = preSelectedServices.map(function(s) {
                        return {
                            service_id: s.service_id,
                            name: s.service_name,
                            price: parseFloat(s.initial_price || 0)
                        };
                    });
                    renderEhrServicesTable();
                }
            } catch (e) { console.warn('prefill failed', e); }
        }

        function syncOdontogramFrame() {
            ensureOdontogramFrame();
            const od = document.getElementById('odontogram_data')?.value || '';
            if (od && od !== '{}' && od !== 'null') {
                postOdontogramToFrame(od);
            }
        }

        function collectOdontogramPayload() {
            const hidden = document.getElementById('odontogram_data');
            ensureOdontogramFrame();
            const frame = document.getElementById('odontogramFrame');
            if (frame && frame.contentWindow && typeof frame.contentWindow.getOdontogramData === 'function') {
                try {
                    const live = frame.contentWindow.getOdontogramData();
                    if (live && typeof live === 'object' && Object.keys(live).length > 0) {
                        const serialized = JSON.stringify(live);
                        if (hidden) hidden.value = serialized;
                        return serialized;
                    }
                } catch (e) {
                    console.warn('getOdontogramData failed', e);
                }
            }
            const val = hidden?.value || '';
            return (val && val !== '{}' && val !== 'null') ? val : '';
        }

        // Patient card: avatar + name + quick chips, shown once a patient is picked.
        // Clicking the avatar (or the "Patient Details" button next to it) opens the
        // combined Profile/Appointment/Records modal — see openDoctorPatientDetailsModal().
        function updateEhrPatientCard(opt) {
            const card = document.getElementById('ehrPatientCard');
            if (!card) return;
            if (!opt || !opt.value) {
                card.style.display = 'none';
                return;
            }
            card.style.display = 'flex';
            document.getElementById('ehrPatientName').textContent = opt.dataset.patientName || 'Unknown patient';

            const imgEl = document.getElementById('ehrPatientAvatarImg');
            const iconEl = document.getElementById('ehrPatientAvatarIcon');
            const imgPath = (opt.dataset.profileImage || '').trim();
            if (imgPath && imgEl && iconEl) {
                imgEl.src = /^https?:\/\//i.test(imgPath) ? imgPath : '../' + imgPath.replace(/^\/+/, '');
                imgEl.style.display = 'block';
                iconEl.style.display = 'none';
            } else if (imgEl && iconEl) {
                imgEl.style.display = 'none';
                iconEl.style.display = '';
            }

            const chips = [];
            if (opt.dataset.apptDate) {
                let dateLabel = opt.dataset.apptDate;
                const d = new Date(String(dateLabel).replace(' ', 'T'));
                if (!Number.isNaN(d.getTime())) dateLabel = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                chips.push('<span class="dpp-chip"><i class="bi bi-calendar-event"></i> ' + escapeHtmlEhr(dateLabel) + (opt.dataset.apptTime ? ' &middot; ' + escapeHtmlEhr(opt.dataset.apptTime) : '') + '</span>');
            }
            if (opt.dataset.emergency === '1') {
                chips.push('<span class="dpp-chip ehr-chip--alert"><i class="bi bi-exclamation-triangle-fill"></i> Emergency</span>');
            }
            chips.push(opt.dataset.online === '1'
                ? '<span class="dpp-chip"><i class="bi bi-laptop"></i> Online</span>'
                : '<span class="dpp-chip"><i class="bi bi-building"></i> Walk-In</span>');
            document.getElementById('ehrPatientChips').innerHTML = chips.join('');
        }

        // Wire queue selector
        document.getElementById('queueSelector')?.addEventListener('change', function () {
            const apptId = parseInt(this.value || '0', 10);
            document.getElementById('appointment_id_hidden').value = apptId;
            updateEhrPatientCard(apptId ? this.options[this.selectedIndex] : null);
            const findingsBtn = document.getElementById('viewClinicalFindingsBtn');
            if (findingsBtn) findingsBtn.disabled = !apptId;
            if (apptId) {
                prefillClinicalRecord(apptId).then(() => {
                    showOdontogram();
                    try { updateOdontogramToggle(); } catch (e) { }
                    mountDoctorTreatmentPlan();
                });
            } else {
                mountDoctorTreatmentPlan();
            }
        });

        // On page load, prefill if appointment provided
        document.addEventListener('DOMContentLoaded', function () {
            const initialAppt = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            if (initialAppt) {
                const sel = document.getElementById('queueSelector');
                if (sel) for (let i = 0; i < sel.options.length; i++) if (parseInt(sel.options[i].value || '0', 10) === initialAppt) { sel.selectedIndex = i; break; }
                updateEhrPatientCard(sel ? sel.options[sel.selectedIndex] : null);
                const findingsBtn = document.getElementById('viewClinicalFindingsBtn');
                if (findingsBtn) findingsBtn.disabled = false;
                prefillClinicalRecord(initialAppt).then(() => {
                    showOdontogram();
                    try { updateOdontogramToggle(); } catch (e) { }
                    mountDoctorTreatmentPlan();
                });
            } else {
                try { updateOdontogramToggle(); } catch (e) { }
            }
        });

        // Create odontogram iframe if not exists
        function ensureOdontogramFrame() {
            if (document.getElementById('odontogramFrame')) return document.getElementById('odontogramFrame');
            const container = document.getElementById('odontogramContainer');
            if (!container) return null;
            const iframe = document.createElement('iframe');
            iframe.id = 'odontogramFrame';
            iframe.src = ODONTOGRAM_IFRAME_SRC;
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = '0';
            iframe.loading = 'eager';
            container.appendChild(iframe);
            // on load, if data present, post it
            iframe.addEventListener('load', function () {
                const od = document.getElementById('odontogram_data')?.value || '';
                if (od) postOdontogramToFrame(od);
                // update toggle button label
                updateOdontogramToggle();
            });
            return iframe;
        }

        function postOdontogramToFrame(odData) {
            try {
                const frame = document.getElementById('odontogramFrame');
                if (!frame || !frame.contentWindow) return;
                let payload = odData;
                if (typeof odData === 'string') {
                    try { payload = JSON.parse(odData); } catch (e) { payload = odData; }
                }
                const msg = { type: 'load_odontogram', toothState: payload, readonly: false };
                frame.contentWindow.postMessage(msg, '*');
            } catch (e) { console.warn('post odontogram', e); }
        }

            // Receive odontogram state updates from iframe and store in hidden input
            window.addEventListener('message', function (ev) {
                try {
                    if (!ev.data) return;
                    if (ev.data.type === 'odontogram_update' && ev.data.toothState) {
                        const el = document.getElementById('odontogram_data');
                        if (el) el.value = JSON.stringify(ev.data.toothState);
                    }
                } catch (e) { console.warn('receive odontogram', e); }
            });

        // show/hide controls
        function showOdontogram() {
            const container = document.getElementById('odontogramContainer');
            if (!container) return;
            ensureOdontogramFrame();
            container.style.display = '';
        }
        function hideOdontogram() {
            const container = document.getElementById('odontogramContainer');
            if (!container) return;
            container.style.display = 'none';
        }
        function updateOdontogramToggle() {
            // no-op: charting section is now a collapse toggle
        }

        function isOdontogramFullscreen() {
            const viewport = document.getElementById('odontogramViewport');
            return !!viewport && window.OdontogramFullscreen.isActive(viewport);
        }

        function requestOdontogramFullscreen() {
            const viewport = document.getElementById('odontogramViewport');
            if (!viewport) return;

            showOdontogram();
            window.OdontogramFullscreen.request(viewport);
        }

        function exitOdontogramFullscreen() {
            const viewport = document.getElementById('odontogramViewport');
            window.OdontogramFullscreen.exit(viewport);
        }

        function notifyOdontogramResize() {
            const frame = document.getElementById('odontogramFrame');
            try {
                if (frame?.contentWindow) {
                    frame.contentWindow.postMessage({ type: 'odontogram_resize' }, '*');
                }
            } catch (e) {}
        }

        function syncOdontogramFullscreenUI() {
            const closeBtn = document.getElementById('closeOdontogramFullscreenBtn');
            const openBtn = document.getElementById('openOdontogramFullscreenBtn');
            const container = document.getElementById('odontogramContainer');
            const active = isOdontogramFullscreen();

            if (closeBtn) closeBtn.style.display = active ? '' : 'none';
            if (openBtn) openBtn.textContent = active ? 'Exit full screen' : 'Full screen';
            if (container) {
                container.style.height = active ? '100%' : (ODONTOGRAM_IFRAME_HEIGHT + 'px');
            }
            setTimeout(notifyOdontogramResize, 50);
        }

        document.getElementById('openOdontogramFullscreenBtn')?.addEventListener('click', function () {
            if (isOdontogramFullscreen()) {
                exitOdontogramFullscreen();
                return;
            }
            requestOdontogramFullscreen();
        });

        document.getElementById('closeOdontogramFullscreenBtn')?.addEventListener('click', function () {
            exitOdontogramFullscreen();
        });

        document.addEventListener('fullscreenchange', syncOdontogramFullscreenUI);
        document.addEventListener('webkitfullscreenchange', syncOdontogramFullscreenUI);
        document.addEventListener('odontogram:pseudofullscreenchange', function (e) {
            if (e.target.id === 'odontogramViewport') syncOdontogramFullscreenUI();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOdontogramFullscreen()) {
                exitOdontogramFullscreen();
            }
        });

        syncOdontogramFullscreenUI();

        // Services management
        var ehrSelectedServices = [];
        // Exposed so the (globally-scoped) Appointment Details modal can read the
        // live list without duplicating state or re-fetching it.
        window.getEhrSelectedServices = function() { return ehrSelectedServices; };

        function addEhrService() {
            var select = document.getElementById('ehrServiceSelect');
            var serviceId = parseInt(select.value);
            if (!serviceId) { notifyDoctor('Select a service first.', 'warning'); return; }
            var option = select.options[select.selectedIndex];
            var exists = ehrSelectedServices.find(function(s) { return s.service_id === serviceId; });
            if (exists) { notifyDoctor('Service already added.', 'warning'); return; }
            ehrSelectedServices.push({
                service_id: serviceId,
                name: option.dataset.name,
                price: parseFloat(option.dataset.price || 0)
            });
            select.value = '';
            renderEhrServicesTable();
        }

        function removeEhrService(serviceId) {
            ehrSelectedServices = ehrSelectedServices.filter(function(s) { return s.service_id !== serviceId; });
            renderEhrServicesTable();
        }
        // Exposed globally because rows use an inline onclick="removeEhrService(...)"
        // handler, which resolves against the global scope, not this IIFE's closure.
        window.removeEhrService = removeEhrService;

        // Treatment plan sync: completing a plan item puts its service in Services
        // Performed (what billing is built from). Tracks which services the plan added,
        // so undoing a completion never removes a service the doctor picked by hand.
        var planAddedServiceIds = new Set();
        function ehrAddServiceFromPlan(item) {
            var sid = Number(item.service_id);
            if (!sid || ehrSelectedServices.some(function(s) { return s.service_id === sid; })) return;
            var opt = document.querySelector('#ehrServiceSelect option[value="' + sid + '"]');
            ehrSelectedServices.push({
                service_id: sid,
                name: item.service_name || (opt ? opt.dataset.name : 'Service #' + sid),
                price: opt ? parseFloat(opt.dataset.price || 0) : Number(item.estimated_cost || 0)
            });
            planAddedServiceIds.add(sid);
            renderEhrServicesTable();
        }
        function ehrRemoveServiceFromPlan(item, items) {
            var sid = Number(item.service_id);
            var appt = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            var stillDone = (items || []).some(function(i) {
                return Number(i.service_id) === sid && i.status === 'completed' && Number(i.completed_appointment_id) === appt;
            });
            if (!planAddedServiceIds.has(sid) || stillDone) return;
            planAddedServiceIds.delete(sid);
            removeEhrService(sid);
        }

        function mountDoctorTreatmentPlan() {
            var host = document.getElementById('ehrTreatmentPlan');
            var sel = document.getElementById('queueSelector');
            var opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
            var patientId = opt ? parseInt(opt.dataset.patientId || '0', 10) : 0;
            if (!host || typeof window.TreatmentPlan === 'undefined') return;
            planAddedServiceIds.clear();
            if (!patientId) {
                host.innerHTML = '<div class="tp-empty">Select a patient to see their treatment plan.</div>';
                return;
            }
            var services = Array.prototype.slice.call(document.querySelectorAll('#ehrServiceSelect option[value]'))
                .filter(function(o) { return o.value; })
                .map(function(o) { return { service_id: parseInt(o.value, 10), name: o.dataset.name, price: parseFloat(o.dataset.price || 0) }; });
            window.TreatmentPlan.mount(host, {
                patientId: patientId,
                appointmentId: parseInt(document.getElementById('appointment_id_hidden').value || '0', 10),
                role: 'doctor',
                csrfToken: doctorCsrfToken,
                services: services,
                onCompleted: ehrAddServiceFromPlan,
                onUncompleted: ehrRemoveServiceFromPlan,
                notify: notifyDoctor
            });
        }
        window.mountDoctorTreatmentPlan = mountDoctorTreatmentPlan;

        function renderEhrServicesTable() {
            var tbody = document.getElementById('ehrServicesBody');
            var tfoot = document.getElementById('ehrServicesFoot');
            if (!tbody) return;
            if (ehrSelectedServices.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="ses-services__empty">No services added yet.</td></tr>';
                if (tfoot) tfoot.innerHTML = '';
                return;
            }
            tbody.innerHTML = '';
            var total = 0;
            ehrSelectedServices.forEach(function(s) {
                total += s.price;
                var tr = document.createElement('tr');
                tr.innerHTML = '<td style="font-size:0.8rem;">' + escapeHtmlEhr(s.name) + '</td><td style="font-size:0.8rem;text-align:right;">₱' + s.price.toLocaleString() + '</td><td><button type="button" class="btn btn-sm py-0 px-1" onclick="removeEhrService(' + s.service_id + ')" style="color:#dc3545;background:none;border:none;"><i class="bi bi-x"></i></button></td>';
                tbody.appendChild(tr);
            });
            if (tfoot) {
                tfoot.innerHTML = '<tr class="ehr-services-total-row" style="font-weight:700;"><td style="font-size:0.82rem;">Total</td><td style="font-size:0.82rem;text-align:right;">₱' + total.toLocaleString() + '</td><td></td></tr>';
            }
        }

        function escapeHtmlEhr(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Auto-add on selection (no separate Add button)
        document.getElementById('ehrServiceSelect')?.addEventListener('change', addEhrService);

        // Photo Upload Handler
        document.getElementById('finishPhotoUpload').addEventListener('change', async function(e) {
            const files = Array.from(e.target.files);
            if (!files.length) return;
            for (const file of files) {
                if (!file.type.startsWith('image/')) { notifyDoctor('Only image files allowed', 'warning'); continue; }
                if (file.size > 5 * 1024 * 1024) { notifyDoctor('File ' + file.name + ' exceeds 5MB limit', 'warning'); continue; }
                uploadedPhotos.push(file);
            }
            updatePhotoGalleryPreview();
        });

        // Camera Modal Logic
        var cameraStream = null;
        document.getElementById('cameraModal')?.addEventListener('show.bs.modal', async function() {
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                var video = document.getElementById('cameraPreviewVideo');
                video.srcObject = cameraStream;
                video.style.display = 'block';
                video.play();
                document.getElementById('capturedPreview').style.display = 'none';
                document.getElementById('capturePhotoBtn').style.display = '';
                document.getElementById('confirmPhotoBtn').style.display = 'none';
                document.getElementById('retakePhotoBtn').style.display = 'none';
            } catch (err) {
                notifyDoctor('Camera access denied or unavailable', 'danger');
            }
        });

        document.getElementById('cameraModal')?.addEventListener('hidden.bs.modal', function() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(function(track) { track.stop(); });
                cameraStream = null;
            }
        });

        document.getElementById('capturePhotoBtn')?.addEventListener('click', function() {
            var video = document.getElementById('cameraPreviewVideo');
            var canvas = document.getElementById('cameraCaptureCanvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            document.getElementById('capturedPreviewImg').src = canvas.toDataURL('image/jpeg', 0.9);
            document.getElementById('capturedPreview').style.display = '';
            video.style.display = 'none';
            this.style.display = 'none';
            document.getElementById('confirmPhotoBtn').style.display = '';
            document.getElementById('retakePhotoBtn').style.display = '';
        });

        document.getElementById('confirmPhotoBtn')?.addEventListener('click', function() {
            var canvas = document.getElementById('cameraCaptureCanvas');
            canvas.toBlob(function(blob) {
                var file = new File([blob], 'capture_' + Date.now() + '.jpg', { type: 'image/jpeg' });
                uploadedPhotos.push(file);
                updatePhotoGalleryPreview();
                notifyDoctor('Photo captured', 'success');
                bootstrap.Modal.getInstance(document.getElementById('cameraModal'))?.hide();
            }, 'image/jpeg', 0.9);
        });

        document.getElementById('retakePhotoBtn')?.addEventListener('click', function() {
            var video = document.getElementById('cameraPreviewVideo');
            video.style.display = 'block';
            document.getElementById('capturedPreview').style.display = 'none';
            document.getElementById('capturePhotoBtn').style.display = '';
            this.style.display = 'none';
            document.getElementById('confirmPhotoBtn').style.display = 'none';
        });

        function updatePhotoGalleryPreview() {
            var container = document.getElementById('photoGalleryContainer');

            if (uploadedPhotos.length === 0) {
                container.innerHTML = '<span class="ses-photos__empty" id="noPhotosMsg">No photos yet.</span>';
                return;
            }

            container.innerHTML = '';
            uploadedPhotos.forEach(function(file, idx) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var wrap = document.createElement('div');
                    wrap.style.cssText = 'position:relative;width:80px;height:60px;border-radius:6px;overflow:hidden;border:1px solid #ddd;flex-shrink:0;cursor:pointer;';
                    wrap.title = 'Click to view full size';
                    wrap.setAttribute('data-photo-url', e.target.result);
                    wrap.setAttribute('data-photo-idx', idx);
                    wrap.innerHTML = '<img src="' + e.target.result + '" alt="Photo" style="width:100%;height:100%;object-fit:cover;">' +
                        '<button type="button" onclick="event.stopPropagation();removeUploadedPhoto(' + idx + ')" style="position:absolute;top:2px;right:2px;background:rgba(220,53,69,0.85);color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>';
                    wrap.addEventListener('click', function() {
                        var viewerImg = document.getElementById('photoViewerImg');
                        var viewerTitle = document.getElementById('photoViewerTitle');
                        if (viewerImg) viewerImg.src = this.getAttribute('data-photo-url');
                        if (viewerTitle) viewerTitle.textContent = 'Photo ' + (parseInt(this.getAttribute('data-photo-idx')) + 1);
                        var modal = new bootstrap.Modal(document.getElementById('photoViewerModal'));
                        modal.show();
                    });
                    container.appendChild(wrap);
                };
                reader.readAsDataURL(file);
            });
        }

        window.removeUploadedPhoto = function(idx) {
            uploadedPhotos.splice(idx, 1);
            updatePhotoGalleryPreview();
        }

        let ehrChartSaving = false;

        // Save Chart: persists the odontogram immediately (status stays in_progress,
        // does NOT move the appointment to the secretary queue) so charting is never
        // lost regardless of which "Finish Appointment" screen is used afterwards.
        document.getElementById('saveChartBtn')?.addEventListener('click', async function() {
            const appointmentId = document.getElementById('appointment_id_hidden').value;
            if (!appointmentId) {
                notifyDoctor('Choose a patient first.', 'warning');
                return;
            }
            if (ehrChartSaving) return;
            ehrChartSaving = true;

            const btnText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const odontogramPayload = collectOdontogramPayload();
                const fd = new FormData();
                fd.append('appointment_id', appointmentId);
                fd.append('doctor_notes', document.getElementById('doctor_notes')?.value || '');
                fd.append('csrf_token', doctorCsrfToken);
                if (odontogramPayload) fd.append('odontogram', odontogramPayload);

                const res = await fetch('../assets/api/ehr/save_clinical_record.php', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (!json.success) {
                    notifyDoctor(json.message || 'Failed to save chart', 'danger');
                } else {
                    notifyDoctor('Chart saved', 'success');
                }
            } catch (e) {
                console.error(e);
                notifyDoctor('Error saving chart', 'danger');
            } finally {
                ehrChartSaving = false;
                this.disabled = false;
                this.innerHTML = btnText;
            }
        });

        let ehrFinishSubmitting = false;
        let ehrFinishIdempotencyKey = null;

        // Submit Finish Appointment
        document.getElementById('submitFinishAppointmentBtn').addEventListener('click', async function() {
            const appointmentId = document.getElementById('appointment_id_hidden').value;
            if (!appointmentId) {
                notifyDoctor('Choose a patient first.', 'warning');
                return;
            }
            if (ehrFinishSubmitting) return;
            ehrFinishSubmitting = true;

            if (!ehrFinishIdempotencyKey) {
                ehrFinishIdempotencyKey = (window.Idempotency && Idempotency.newKey('finish')) || ('finish-' + Date.now() + '-' + Math.random().toString(36).slice(2));
            }
            
            this.disabled = true;
            const btnText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            try {
                const fd = new FormData();
                fd.append('appointment_id', appointmentId);
                fd.append('notes', document.getElementById('doctor_notes').value);
                fd.append('csrf_token', doctorCsrfToken);
                fd.append('idempotency_key', ehrFinishIdempotencyKey);

                // Add selected services
                ehrSelectedServices.forEach(function(s, i) {
                    fd.append('selected_service_ids[' + i + ']', s.service_id);
                });

                const odontogramPayload = collectOdontogramPayload();
                if (odontogramPayload) {
                    fd.append('odontogram', odontogramPayload);
                }
                
                uploadedPhotos.forEach((file) => {
                    fd.append(`photos[]`, file);
                });
                
                const res = await fetch('../assets/api/appointments/finish_appointment.php', {
                    method: 'POST',
                    body: fd
                });
                
                let json;
                const ct = (res.headers.get('Content-Type') || res.headers.get('content-type') || '');
                if (ct.indexOf('application/json') !== -1) {
                    json = await res.json();
                } else {
                    const txt = await res.text();
                    console.error('finish_appointment non-JSON response:', txt);
                    notifyDoctor('Server error: invalid response from server', 'danger');
                    ehrFinishSubmitting = false;
                    this.disabled = false;
                    this.innerHTML = btnText;
                    return;
                }
                if (!json.success) {
                    notifyDoctor(json.message || 'Could not complete the visit.', 'danger');
                    ehrFinishSubmitting = false;
                    this.disabled = false;
                    this.innerHTML = btnText;
                    return;
                }
                
                notifyDoctor(json.already_processed || json.idempotent_replay ? 'This visit was already completed.' : 'Visit completed and sent to the secretary.', 'success');
                if (json.upload_warnings && json.upload_warnings.length > 0) {
                    notifyDoctor('Upload warnings: ' + json.upload_warnings.join('; '), 'warning');
                }

                setTimeout(() => window.location.href = 'doctor.php?page=appointments', 1000);
            } catch (e) {
                console.error(e);
                notifyDoctor('Could not complete the visit. Please try again.', 'danger');
                ehrFinishSubmitting = false;
                this.disabled = false;
                this.innerHTML = btnText;
            }
        });

        function notifyDoctor(message, type = 'info') {
            window.showToast(String(message || ''), type);
        }

        // Page state: the work area stays dimmed until a patient is chosen, and the chart
        // toggle names what it will do next.
        function syncVisitIdle() {
            const root = document.getElementById('sesRoot');
            const appt = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            if (root) root.classList.toggle('is-idle', !appt);
        }
        document.getElementById('queueSelector')?.addEventListener('change', syncVisitIdle);
        syncVisitIdle();

        const chartingSection = document.getElementById('chartingSection');
        function setChartToggleLabel(open) {
            const label = document.querySelector('#sesChartToggle .ses-chart-toggle-label');
            if (label) label.textContent = open ? 'Hide chart' : 'Open chart';
        }
        chartingSection?.addEventListener('shown.bs.collapse', function (e) {
            if (e.target !== chartingSection) return;
            setChartToggleLabel(true);
            showOdontogram();
            setTimeout(notifyOdontogramResize, 50);
        });
        chartingSection?.addEventListener('hidden.bs.collapse', function (e) {
            if (e.target === chartingSection) setChartToggleLabel(false);
        });

    })();
</script>

<script>
// Patient Details modal — one popup with Profile / Appointment / Records tabs for the
// currently selected queue option, replacing the old separate "Profile" and
// "Appointment" toolbar buttons. data-patient-id/data-patient-name/etc. were already
// added to each <option>, so no extra lookup is needed to know who's selected.
let pdCurrentPatientId = 0;

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function openDoctorPatientDetailsModal() {
    const sel = document.getElementById('queueSelector');
    const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    if (!opt || !opt.value) {
        window.showToast('Select a patient first.', 'warning');
        return;
    }

    pdCurrentPatientId = parseInt(opt.dataset.patientId || '0', 10);

    // Show the photo + name right away from the already-on-page dataset (no flicker);
    // age/gender get filled in once the profile fetch below resolves.
    populatePdHeader({ profile_image: opt.dataset.profileImage, phone_number: opt.dataset.phone }, opt.dataset.patientName || '');
    loadPdProfileTab(pdCurrentPatientId, opt.dataset.patientName || '');
    renderPdAppointmentTab(opt);
    resetPdRecordsTab();

    const modalEl = document.getElementById('doctorPatientDetailsModal');
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();

    // Always land back on the Profile tab when reopened for a (possibly different) patient.
    const profileTabBtn = document.getElementById('pdTabProfileBtn');
    if (profileTabBtn && window.bootstrap?.Tab) bootstrap.Tab.getOrCreateInstance(profileTabBtn).show();
}

// Identity strip at the top of the modal (photo, name, Age/Gender/Phone chips) — shared
// across all three tabs instead of being repeated inside the Profile tab's own content
// (computePatientHeaderInfo() lives in assets/js/doctor-patient-profile.js).
function populatePdHeader(patient, fallbackName) {
    patient = patient || {};
    const info = (typeof computePatientHeaderInfo === 'function')
        ? computePatientHeaderInfo(patient, fallbackName)
        : { fullName: fallbackName || 'Unknown Patient', chipsHtml: '' };

    const nameEl = document.getElementById('pdHeaderName');
    if (nameEl) nameEl.textContent = info.fullName;
    const chipsEl = document.getElementById('pdHeaderChips');
    if (chipsEl) chipsEl.innerHTML = info.chipsHtml;

    const imgEl = document.getElementById('pdHeaderAvatarImg');
    const iconEl = document.getElementById('pdHeaderAvatarIcon');
    const imgPath = String(patient.profile_image || '').trim();
    if (imgPath && imgEl && iconEl) {
        imgEl.src = /^https?:\/\//i.test(imgPath) ? imgPath : '../' + imgPath.replace(/^\/+/, '');
        imgEl.style.display = 'block';
        iconEl.style.display = 'none';
    } else if (imgEl && iconEl) {
        imgEl.style.display = 'none';
        iconEl.style.display = '';
    }
}

function loadPdProfileTab(patientId, patientName) {
    const bodyEl = document.getElementById('pdProfileBody');
    if (!bodyEl) return;
    if (!patientId) {
        bodyEl.innerHTML = '<div class="text-muted text-center py-4">No patient selected.</div>';
        return;
    }
    bodyEl.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    fetch('../assets/api/patients/get_profiling.php?patient_id=' + encodeURIComponent(patientId))
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                bodyEl.innerHTML = '<div class="text-muted text-center py-4">Unable to load patient profile.</div>';
                return;
            }
            populatePdHeader(data.patient || {}, patientName);
            // renderDoctorPatientProfile() lives in assets/js/doctor-patient-profile.js
            // (shared with doctor's My Patients "Profile" popup and secretary's View
            // Profiling) — showHeader:false since the name/age/gender/phone strip is
            // already shown once in this modal's own persistent header above the tabs.
            bodyEl.innerHTML = renderDoctorPatientProfile(data.patient || {}, data.profiling || {}, patientName, { showHeader: false });
        })
        .catch(() => {
            bodyEl.innerHTML = '<div class="text-muted text-center py-4">Error loading patient profile.</div>';
        });
}

const PD_STATUS_LABELS = {
    scheduled: 'Pending', confirmed: 'Confirmed', arrived: 'Arrived', checked_in: 'Checked-In',
    completed: 'Completed', cancelled: 'Cancelled', in_progress: 'In Progress', no_show: 'No Show',
    awaiting_secretary_records: 'Awaiting Records', records_complete: 'Records Complete',
};

// Built straight from the selected <option>'s dataset (plus the live "Services
// Performed" list) — no extra fetch needed since that data is already on the page.
function renderPdAppointmentTab(opt) {
    const bodyEl = document.getElementById('pdAppointmentBody');
    if (!bodyEl) return;

    const services = (typeof window.getEhrSelectedServices === 'function') ? window.getEhrSelectedServices() : [];
    const status = String(opt.dataset.status || '').toLowerCase();
    const statusLabel = PD_STATUS_LABELS[status] || (status || 'Pending');

    let dateLabel = opt.dataset.apptDate || '';
    const d = new Date(String(dateLabel).replace(' ', 'T'));
    if (!Number.isNaN(d.getTime())) dateLabel = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

    const servicesHtml = services.length > 0
        ? services.map(function(s) { return '<span class="dpp-chip">' + escapeHtml(s.name || '') + '</span>'; }).join(' ')
        : '<span class="text-muted small">None recorded yet</span>';

    bodyEl.innerHTML =
        '<div class="dpp-field"><div class="dpp-field-label">Status</div><div class="dpp-field-value">' + escapeHtml(statusLabel) + '</div></div>' +
        '<div class="dpp-field"><div class="dpp-field-label">Date &amp; Time</div><div class="dpp-field-value">' + escapeHtml(dateLabel) + (opt.dataset.apptTime ? ' &middot; ' + escapeHtml(opt.dataset.apptTime) : '') + '</div></div>' +
        '<div class="dpp-field"><div class="dpp-field-label">Type</div><div class="dpp-field-value">' + (opt.dataset.online === '1' ? 'Online' : 'Walk-In') + '</div></div>' +
        '<div class="dpp-field"><div class="dpp-field-label">Reason for Visit</div><div class="dpp-field-value">' + escapeHtml(opt.dataset.reason || '—') + '</div></div>' +
        (opt.dataset.notes ? '<div class="dpp-field"><div class="dpp-field-label">Notes</div><div class="dpp-field-value">' + escapeHtml(opt.dataset.notes) + '</div></div>' : '') +
        '<div class="dpp-field"><div class="dpp-field-label">Email</div><div class="dpp-field-value">' + escapeHtml(opt.dataset.email || '—') + '</div></div>' +
        '<div class="dpp-field"><div class="dpp-field-label">Phone</div><div class="dpp-field-value">' + escapeHtml(opt.dataset.phone || '—') + '</div></div>' +
        '<div class="dpp-field"><div class="dpp-field-label">Services (this visit)</div><div class="dpp-field-value">' + servicesHtml + '</div></div>';
}

// Records tab — lists this patient's past finalized visits as switchable buttons
// (assets/api/ehr/get_patient_record_summaries.php), loading the first one's full
// detail (assets/api/ehr/get_records_by_appointment_detailed.php?record_id=...) so the
// doctor can page through history without leaving this modal.
function resetPdRecordsTab() {
    const picker = document.getElementById('pdRecordsVisitPicker');
    const body = document.getElementById('pdRecordsBody');
    if (!picker || !body) return;
    if (!pdCurrentPatientId) {
        picker.innerHTML = '';
        body.innerHTML = '<span class="text-muted small">No patient selected.</span>';
        return;
    }
    picker.innerHTML = '<span class="text-muted small">Loading visit history...</span>';
    body.innerHTML = '';
    fetch('../assets/api/ehr/get_patient_record_summaries.php?patient_id=' + encodeURIComponent(pdCurrentPatientId))
        .then(r => r.json())
        .then(data => {
            const visits = (data && data.success && Array.isArray(data.visits)) ? data.visits : [];
            if (visits.length === 0) {
                picker.innerHTML = '<span class="text-muted small">No past records for this patient.</span>';
                return;
            }
            picker.innerHTML = visits.map(function(v, idx) {
                let label = v.submitted_at || v.appointment_date || ('Visit #' + v.record_id);
                const vd = new Date(String(label).replace(' ', 'T'));
                if (!Number.isNaN(vd.getTime())) label = vd.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                return '<button type="button" class="btn btn-sm ' + (idx === 0 ? 'btn-primary' : 'btn-outline-secondary') + ' pd-visit-btn" data-record-id="' + v.record_id + '">' + escapeHtml(label) + '</button>';
            }).join('');
            picker.querySelectorAll('.pd-visit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    picker.querySelectorAll('.pd-visit-btn').forEach(function(b) {
                        b.classList.remove('btn-primary');
                        b.classList.add('btn-outline-secondary');
                    });
                    this.classList.remove('btn-outline-secondary');
                    this.classList.add('btn-primary');
                    loadPdRecordDetail(parseInt(this.dataset.recordId, 10));
                });
            });
            loadPdRecordDetail(visits[0].record_id);
        })
        .catch(() => {
            picker.innerHTML = '<span class="text-muted small">Error loading visit history.</span>';
        });
}

function loadPdRecordDetail(recordId) {
    const body = document.getElementById('pdRecordsBody');
    if (!body || !recordId) return;
    body.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?record_id=' + encodeURIComponent(recordId))
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success || !data.record) {
                body.innerHTML = '<div class="text-muted small">Could not load this record.</div>';
                return;
            }
            const rec = data.record;
            const servicesAvailed = Array.isArray(data.services_availed) ? data.services_availed : [];
            const servicesHtml = servicesAvailed.length > 0
                ? servicesAvailed.map(function(s) { return '<span class="dpp-chip">' + escapeHtml(s.description || '') + '</span>'; }).join(' ')
                : '<span class="text-muted small">None recorded</span>';

            body.innerHTML =
                '<div class="pf-section mb-2">' +
                    '<div class="pf-section__title"><i class="bi bi-info-circle"></i> Visit Summary</div>' +
                    '<div class="dpp-field"><div class="dpp-field-label">Status</div><div class="dpp-field-value">' + escapeHtml(rec.status || '—') + '</div></div>' +
                    '<div class="dpp-field"><div class="dpp-field-label">Doctor\'s Notes</div><div class="dpp-field-value">' + escapeHtml(rec.doctor_notes || '—') + '</div></div>' +
                    '<div class="dpp-field"><div class="dpp-field-label">Services</div><div class="dpp-field-value">' + servicesHtml + '</div></div>' +
                '</div>' +
                (window.TreatmentPlan ? '<div class="pf-section mb-2"><div class="pf-section__title"><i class="bi bi-list-check"></i> Treatments</div>' + window.TreatmentPlan.renderVisit(data.treatments) + '</div>' : '') +
                '<div id="pdRecordFindings"></div>';

            const findingsEl = document.getElementById('pdRecordFindings');
            if (findingsEl && typeof renderVisitClinicalFields === 'function') {
                findingsEl.innerHTML = '<div class="pf-section mb-0"><div class="pf-section__title"><i class="bi bi-clipboard2-pulse"></i> Clinical Findings</div>' + renderVisitClinicalFields(rec.clinical_findings || {}) + '</div>';
            }
        })
        .catch(() => {
            body.innerHTML = '<div class="text-muted small">Error loading this record.</div>';
        });
}
</script>

<script>
// Clinical Findings modal — the doctor inputs/edits clinical findings here during their own
// session (pre-filled with whatever the secretary already captured in Create Record, if
// any). Mirrors the Create Record tab's field order/widgets: Occlusal Classification first
// (what's actually used), then the optional groups. Saved via
// assets/api/ehr/save_clinical_findings.php, collected with the shared
// collectVisitClinicalData() helper (assets/js/clinical-assessment-fields.js) using the
// same 'visit-clinical-field' convention the secretary's Create Record form uses.
const CLINICAL_FINDINGS_GROUPS = [
    {
        title: 'Occlusal Classification',
        optional: false,
        fields: [
            ['occlusion', 'Occlusion', 'text'], ['cl_i_type', 'Cl. I Type', 'text'],
            ['cl_ii_division', 'Cl. II Division', 'text'], ['cl_ii_subdivision', 'Cl. II SubDivision', 'text'],
            ['cl_ii_type', 'Cl. II Type', 'text'], ['cl_iii_division', 'Cl. III Division', 'text'],
            ['cl_iii_subdivision', 'Cl. III SubDivision', 'text'], ['operation_if_any', 'Operation if any', 'text'],
        ],
    },
    {
        title: 'Clinical Assessment',
        optional: true,
        fields: [
            ['gingiva_consistency', 'Consistency Gingiva', 'radio', ['Firm', 'Hyper plastic', 'Smooth']],
            ['oral_hygiene', 'Oral Hygiene', 'radio', ['Good', 'Fair', 'Bad']],
            ['gingival_color', 'Gingival Color', 'radio', ['Pink', 'Bright red']],
            ['lymph_nodes', 'Lymph Nodes', 'radio', ['Not palpable', 'Palpable']],
            ['tongue', 'Tongue', 'radio', ['Normal', 'Coated']],
        ],
    },
    {
        title: 'Vitals & Lab Findings',
        optional: true,
        fields: [
            ['blood_sugar', 'Blood Sugar', 'text'],
            ['blood_sugar_normal', 'Blood Sugar: Normal', 'checkbox'],
            ['blood_sugar_high_low', 'Blood Sugar: High/Low', 'checkbox'],
            ['blood_pressure', 'B.P.', 'text'],
            ['bleeding_time', 'Bleeding Time', 'text'], ['clotting_time', 'Clotting Time', 'text'],
            ['fainting', 'Fainting', 'text'], ['allergy_notes', 'Allergy', 'text'],
            ['blood_diseases', 'Blood Diseases', 'text'],
        ],
    },
    {
        title: 'Radiographic Interpretation',
        optional: true,
        fields: [['radiographic_interpretation', 'Radiographic Interpretation', 'textarea']],
    },
];

function renderClinicalFindingsHtml(findings) {
    findings = findings || {};
    let idSeq = 0;
    return CLINICAL_FINDINGS_GROUPS.map(function(group) {
        const rows = group.fields.map(function(f) {
            const key = f[0], label = f[1], kind = f[2] || 'text';
            const value = findings[key];
            let control;
            let colClass = 'col-sm-6 col-lg-4';
            if (kind === 'radio') {
                const pills = (f[3] || []).map(function(opt) {
                    const id = 'df_' + key + '_' + (idSeq++);
                    const checked = String(value || '') === opt ? ' checked' : '';
                    return '<input class="pf-check visit-clinical-field" type="radio" name="' + key + '" value="' + escapeHtml(opt) + '" id="' + id + '"' + checked + '><label class="pf-pill" for="' + id + '">' + escapeHtml(opt) + '</label>';
                }).join('');
                control = '<div class="pf-pill-group">' + pills + '</div>';
            } else if (kind === 'checkbox') {
                const id = 'df_' + key;
                const checked = (value === true || value === '1' || value === 1) ? ' checked' : '';
                control = '<div class="pf-pill-group"><input class="pf-check visit-clinical-field" type="checkbox" name="' + key + '" id="' + id + '"' + checked + '><label class="pf-pill" for="' + id + '">Yes</label></div>';
            } else if (kind === 'textarea') {
                colClass = 'col-12';
                control = '<textarea class="form-control form-control-sm visit-clinical-field" name="' + key + '" rows="2" style="font-size:0.82rem;">' + escapeHtml(value || '') + '</textarea>';
            } else {
                control = '<input type="text" class="form-control form-control-sm visit-clinical-field" name="' + key + '" value="' + escapeHtml(value || '') + '" style="font-size:0.82rem;">';
            }
            return '<div class="' + colClass + ' mb-2"><div class="small text-muted mb-1">' + escapeHtml(label) + '</div>' + control + '</div>';
        }).join('');
        return '<div class="pf-section mb-2"><div class="pf-section__title">' + escapeHtml(group.title) +
            (group.optional ? ' <span class="badge bg-secondary fw-normal" style="font-size:0.65rem;">Optional</span>' : '') +
            '</div><div class="row g-1">' + rows + '</div></div>';
    }).join('');
}

let doctorFindingsApptId = 0;

// Doctor's Notes sits second, right after Occlusal Classification. The section is one
// persistent element (#doctor_notes is read on save/finish and filled on prefill), so it's
// moved rather than re-rendered, and parked back in its holder before the findings body is
// replaced so typed notes aren't lost.
function parkDoctorNotesSection() {
    const section = document.getElementById('doctorNotesSection');
    const holder = document.getElementById('doctorNotesHolder');
    if (section && holder && section.parentNode !== holder) holder.appendChild(section);
}

function placeDoctorNotesSection(bodyEl) {
    const section = document.getElementById('doctorNotesSection');
    const first = bodyEl && bodyEl.querySelector('.pf-section');
    if (section && first) first.insertAdjacentElement('afterend', section);
}

function renderDoctorFindings(bodyEl, findings) {
    parkDoctorNotesSection();
    bodyEl.innerHTML = renderClinicalFindingsHtml(findings);
    placeDoctorNotesSection(bodyEl);
    if (typeof initToggleableClinicalRadios === 'function') initToggleableClinicalRadios(bodyEl);
}

function openDoctorClinicalFindingsModal() {
    const sel = document.getElementById('queueSelector');
    const apptId = sel ? parseInt(sel.value || document.getElementById('appointment_id_hidden')?.value || '0', 10) : parseInt(document.getElementById('appointment_id_hidden')?.value || '0', 10);
    if (!apptId) {
        window.showToast('Select a patient first.', 'warning');
        return;
    }
    doctorFindingsApptId = apptId;

    const modalEl = document.getElementById('doctorClinicalFindingsModal');
    const bodyEl = document.getElementById('doctorFindingsBody');
    if (!modalEl || !bodyEl) return;
    const modal = new bootstrap.Modal(modalEl);
    parkDoctorNotesSection();
    bodyEl.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    modal.show();

    fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?appointment_id=' + encodeURIComponent(apptId))
        .then(r => r.json())
        .then(data => {
            const findings = (data && data.success && data.record) ? (data.record.clinical_findings || {}) : {};
            renderDoctorFindings(bodyEl, findings);
        })
        .catch(() => {
            renderDoctorFindings(bodyEl, {});
        });
}

document.getElementById('saveClinicalFindingsBtn')?.addEventListener('click', async function() {
    if (!doctorFindingsApptId) {
        window.showToast('Select a patient first.', 'warning');
        return;
    }
    const findings = typeof collectVisitClinicalData === 'function'
        ? collectVisitClinicalData('#doctorFindingsBody')
        : {};

    const btnText = this.innerHTML;
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    try {
        const fd = new FormData();
        fd.append('appointment_id', doctorFindingsApptId);
        fd.append('clinical_findings', JSON.stringify(findings));
        fd.append('csrf_token', doctorCsrfToken);
        const res = await fetch('../assets/api/ehr/save_clinical_findings.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!json.success) {
            window.showToast(json.message || 'Failed to save clinical findings', 'danger');
        } else {
            window.showToast('Clinical findings saved', 'success');
        }
    } catch (e) {
        console.error(e);
        window.showToast('Error saving clinical findings', 'danger');
    } finally {
        this.disabled = false;
        this.innerHTML = btnText;
    }
});
</script>

