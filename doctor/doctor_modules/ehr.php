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
    $stmt = $conn->prepare('SELECT a.*, p.first_name, p.last_name, p.email, p.phone_number FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?');
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
<div class="card border-0 shadow-none">
    <input type="hidden" name="appointment_id" id="appointment_id_hidden" value="<?php echo (int) $appointment_id; ?>">
    <input type="hidden" name="schedule_id" id="schedule_id_hidden"
        value="<?php echo isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : ''; ?>">

    <!-- Row 1: Patient Selector | Patient Info | Clinical Notes -->
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-bold" style="font-size:0.8rem;">Select Patient</label>
            <select id="queueSelector" class="form-select form-select-sm" style="font-size:0.82rem;">
                <option value="">-- Select patient --</option>
                <?php foreach ($queueItems as $qi): ?>
                    <?php
                        $display = trim($qi['first_name'] . ' ' . (!empty($qi['middle_name']) ? $qi['middle_name'] . ' ' : '') . $qi['last_name']);
                        if (!empty($qi['is_emergency'])) $display .= ' (EMERGENCY)';
                        if (!empty($qi['record_id'])) $display .= ' — Record';
                        $qiStartTime = !empty($qi['start_time']) ? date('g:i A', strtotime($qi['start_time'])) : '';
                    ?>
                    <option value="<?php echo (int) $qi['appointment_id']; ?>" data-emergency="<?php echo !empty($qi['is_emergency']) ? '1' : '0'; ?>" data-has-record="<?php echo !empty($qi['record_id']) ? '1' : '0'; ?>" data-patient-id="<?php echo (int) $qi['patient_id']; ?>" data-patient-name="<?php echo htmlspecialchars(trim($qi['first_name'] . ' ' . (!empty($qi['middle_name']) ? $qi['middle_name'] . ' ' : '') . $qi['last_name']), ENT_QUOTES); ?>" data-reason="<?php echo htmlspecialchars((string) ($qi['reason'] ?? ''), ENT_QUOTES); ?>" data-notes="<?php echo htmlspecialchars((string) ($qi['notes'] ?? ''), ENT_QUOTES); ?>" data-appt-date="<?php echo htmlspecialchars((string) ($qi['appointment_date'] ?? ''), ENT_QUOTES); ?>" data-appt-time="<?php echo htmlspecialchars($qiStartTime, ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars((string) ($qi['email'] ?? ''), ENT_QUOTES); ?>" data-phone="<?php echo htmlspecialchars((string) ($qi['phone_number'] ?? ''), ENT_QUOTES); ?>" data-online="<?php echo !empty($qi['is_online_appointment']) ? '1' : '0'; ?>" data-status="<?php echo htmlspecialchars((string) ($qi['appointment_status'] ?? ''), ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($display); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold" style="font-size:0.8rem;">Selected Patient</label>
            <div id="apptInfo" class="form-control form-control-sm bg-light" style="font-size:0.8rem;min-height:38px;display:flex;align-items:center;">
                <span class="text-muted">No patient selected</span>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold" style="font-size:0.8rem;">Clinical Notes</label>
            <textarea id="doctor_notes" class="form-control form-control-sm" rows="1" placeholder="Notes..." style="font-size:0.82rem;resize:vertical;"></textarea>
        </div>
    </div>

    <!-- Row 2: Photos + Actions -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <input type="file" id="finishPhotoUpload" accept="image/*" multiple hidden>
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('finishPhotoUpload').click()">
            <i class="fas fa-upload"></i> Upload
        </button>
        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#cameraModal">
            <i class="fas fa-camera"></i> Take Photo
        </button>
        <div class="ms-auto d-flex gap-2">
            <button type="button" id="viewAppointmentBtn" class="btn btn-secondary btn-sm" disabled onclick="openEhrAppointmentDetailsModal()">
                <i class="bi bi-calendar2-check"></i> Appointment
            </button>
            <button type="button" id="viewPatientProfileBtn" class="btn btn-secondary btn-sm" disabled onclick="openDoctorPatientProfileModal()">
                <i class="bi bi-person-badge"></i> Profile
            </button>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#chartingSection">
                <i class="bi bi-tooth"></i> Charting
            </button>
        </div>
    </div>

    <!-- Photo Gallery (compact thumbnails with click to view) -->
    <div id="photoGalleryContainer" class="d-flex flex-wrap gap-2 mb-3" style="min-height:40px;">
        <span class="text-muted small" id="noPhotosMsg">No photos uploaded</span>
    </div>

    <!-- Charting Section (hidden by default) -->
    <div class="collapse mb-3" id="chartingSection">
        <div class="p-2 border rounded bg-light">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <label class="form-label fw-bold mb-0"><i class="bi bi-tooth"></i> Odontogram / Charting</label>
                <div class="d-flex gap-2">
                    <button type="button" id="saveChartBtn" class="btn btn-success btn-sm">
                        <i class="bi bi-save"></i> Save Chart
                    </button>
                    <button type="button" id="openOdontogramFullscreenBtn" class="btn btn-secondary btn-sm">Full Screen</button>
                </div>
            </div>
            <div id="odontogramViewport" class="odontogram-viewport">
                <button type="button" id="closeOdontogramFullscreenBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                    <i class="bi bi-x-lg"></i> Close
                </button>
                <div id="odontogramContainer"></div>
            </div>
            <input type="hidden" id="odontogram_data" name="odontogram">
            <small class="text-muted d-block mt-1">Save Chart stores this odontogram immediately so it is not lost, no matter which screen is used to finish the appointment.</small>
        </div>
    </div>

    <!-- Row 3: Services -->
    <div class="mb-3 p-2 border rounded bg-light">
        <div class="d-flex align-items-center gap-2 mb-2">
            <label class="form-label fw-bold mb-0"><i class="fas fa-list-check"></i> Services Performed</label>
        </div>
        <div class="row g-1 mb-2">
            <div class="col-12">
                <select id="ehrServiceSelect" class="form-select form-select-sm">
                    <option value="">-- Select Service --</option>
                    <?php foreach ($services as $svc): ?>
                        <option value="<?php echo (int)$svc['service_id']; ?>"
                            data-price="<?php echo (float)$svc['initial_price']; ?>"
                            data-name="<?php echo htmlspecialchars($svc['service_name'], ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($svc['service_name']); ?> — ₱<?php echo number_format($svc['initial_price'], 0); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>Service</th><th style="width:100px;text-align:right;">Price</th><th style="width:35px;"></th></tr>
                </thead>
                <tbody id="ehrServicesBody">
                    <tr><td colspan="3" class="text-center text-muted small py-1">No services added</td></tr>
                </tbody>
                <tfoot id="ehrServicesFoot">
                </tfoot>
            </table>
        </div>
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

    <div class="d-flex justify-content-center mt-3">
        <button type="button" id="submitFinishAppointmentBtn" class="btn btn-primary btn-sm" style="font-size:0.82rem;">
            <i class="fas fa-check"></i> Finalize Session
        </button>
    </div>
    </form>
    </div>
</div>

<!-- Patient Profile Modal -->
<div class="modal fade" id="doctorPatientProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" style="font-size:1rem;"><i class="bi bi-person-badge"></i> Patient Profiling</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="doctorProfileModalBody">
                <div class="text-center text-muted py-5"><i class="bi bi-hourglass-split"></i> Loading...</div>
            </div>
        </div>
    </div>
</div>

<!-- Appointment Details Modal — ported from the secretary's "Booking Details" modal
     (same dpp-header/chip/section layout), used identically across the doctor portal.
     Since this is a confirmed appointment rather than a pending booking, the reference
     chip shows the appointment number instead of a booking tracking code. -->
<div class="modal fade" id="doctorAppointmentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar2-check"></i> Appointment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="dpp-header">
                    <div class="dpp-avatar" id="docApptDetailsAvatar">?</div>
                    <div>
                        <p class="dpp-name" id="docApptDetailsPatientName"></p>
                        <div class="dpp-chips">
                            <span class="dpp-chip"><i class="bi bi-hash"></i> <span id="docApptDetailsRef"></span></span>
                            <span class="dpp-chip"><i class="bi bi-calendar-event"></i> <span id="docApptDetailsDate"></span></span>
                            <span class="dpp-chip" id="docApptDetailsTimeChip" style="display:none;"><i class="bi bi-clock"></i> <span id="docApptDetailsTime"></span></span>
                            <span class="dpp-chip" id="docApptDetailsTypeChip"></span>
                        </div>
                    </div>
                </div>

                <div id="docApptDetailsStatusBox"></div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-clipboard2-pulse"></i> Visit Details</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Reason for Visit</div>
                        <div class="dpp-field-value" id="docApptDetailsReason"></div>
                    </div>
                    <div class="dpp-field" id="docApptDetailsNotesWrap">
                        <div class="dpp-field-label">Notes</div>
                        <div class="dpp-field-value" id="docApptDetailsNotes"></div>
                    </div>
                </div>

                <div class="dpp-section">
                    <div class="dpp-section-title"><i class="bi bi-person-lines-fill"></i> Contact Info</div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Email</div>
                        <div class="dpp-field-value" id="docApptDetailsEmail"></div>
                    </div>
                    <div class="dpp-field">
                        <div class="dpp-field-label">Phone</div>
                        <div class="dpp-field-value" id="docApptDetailsPhone"></div>
                    </div>
                </div>

                <div class="dpp-section" id="docApptDetailsServicesSection" style="display:none;">
                    <div class="dpp-section-title"><i class="bi bi-clipboard2-check"></i> Services</div>
                    <div id="docApptDetailsServicesList"></div>
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
.ehr-services-total-row {
    background: #f8f9fa;
}
[data-theme="dark"] .ehr-services-total-row {
    background: #1c2c47;
    color: #e2e8f0;
}

.odontogram-viewport {
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
                if (!data || !data.record) return;
                const rec = data.record;

                if (rec.doctor_notes) document.getElementById('doctor_notes').value = rec.doctor_notes;

                const odEl = document.getElementById('odontogram_data');
                if (odEl) {
                    odEl.value = rec.odontogram ? JSON.stringify(rec.odontogram) : '';
                }
                syncOdontogramFrame();

                // Pre-load selected services from secretary/arrival
                if (Array.isArray(rec.selected_services) && rec.selected_services.length > 0) {
                    ehrSelectedServices = rec.selected_services.map(function(s) {
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

        // Wire queue selector
        document.getElementById('queueSelector')?.addEventListener('change', function () {
            const apptId = parseInt(this.value || '0', 10);
            document.getElementById('appointment_id_hidden').value = apptId;
            const apptInfoDiv = document.getElementById('apptInfo');
            if (apptInfoDiv) {
                if (apptId) {
                    const label = this.options[this.selectedIndex]?.text || 'Selected';
                    apptInfoDiv.innerHTML = `<strong>Selected Patient:</strong> ${label}`;
                    apptInfoDiv.style.display = 'block';
                } else {
                    apptInfoDiv.style.display = 'none';
                }
            }
            const profileBtn = document.getElementById('viewPatientProfileBtn');
            if (profileBtn) profileBtn.disabled = !apptId;
            const apptBtn = document.getElementById('viewAppointmentBtn');
            if (apptBtn) apptBtn.disabled = !apptId;
            if (apptId) {
                prefillClinicalRecord(apptId).then(() => {
                    showOdontogram();
                    try { updateOdontogramToggle(); } catch (e) { }
                });
            }
        });

        // On page load, prefill if appointment provided
        document.addEventListener('DOMContentLoaded', function () {
            const initialAppt = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            if (initialAppt) {
                const sel = document.getElementById('queueSelector');
                if (sel) for (let i = 0; i < sel.options.length; i++) if (parseInt(sel.options[i].value || '0', 10) === initialAppt) { sel.selectedIndex = i; break; }
                const apptInfoDiv = document.getElementById('apptInfo');
                if (apptInfoDiv) {
                    const chosenText = sel ? (sel.options[sel.selectedIndex]?.text || initialAppt) : initialAppt;
                    apptInfoDiv.innerHTML = `<strong>Selected Patient:</strong> ${chosenText}`;
                    apptInfoDiv.style.display = 'block';
                }
                const profileBtn = document.getElementById('viewPatientProfileBtn');
                if (profileBtn) profileBtn.disabled = false;
                const apptBtn = document.getElementById('viewAppointmentBtn');
                if (apptBtn) apptBtn.disabled = false;
                prefillClinicalRecord(initialAppt).then(() => {
                    showOdontogram();
                    try { updateOdontogramToggle(); } catch (e) { }
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

        function getOdontogramFullscreenElement() {
            return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
        }

        function isOdontogramFullscreen() {
            const viewport = document.getElementById('odontogramViewport');
            return !!viewport && getOdontogramFullscreenElement() === viewport;
        }

        function requestOdontogramFullscreen() {
            const viewport = document.getElementById('odontogramViewport');
            if (!viewport) return;

            showOdontogram();

            const requestFn = viewport.requestFullscreen || viewport.webkitRequestFullscreen || viewport.msRequestFullscreen;
            if (requestFn) requestFn.call(viewport);
        }

        function exitOdontogramFullscreen() {
            const exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
            if (exitFn) exitFn.call(document);
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
            if (openBtn) openBtn.textContent = active ? 'Exit Full Screen' : 'Full Screen';
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

        function renderEhrServicesTable() {
            var tbody = document.getElementById('ehrServicesBody');
            var tfoot = document.getElementById('ehrServicesFoot');
            if (!tbody) return;
            if (ehrSelectedServices.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted small py-1">No services added</td></tr>';
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
                container.innerHTML = '<span class="text-muted small" id="noPhotosMsg">No photos uploaded</span>';
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
                notifyDoctor('Please select a patient/appointment first.', 'warning');
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
                notifyDoctor('Please select a patient/appointment first.', 'warning');
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
                    notifyDoctor(json.message || 'Failed to finish appointment', 'danger');
                    ehrFinishSubmitting = false;
                    this.disabled = false;
                    this.innerHTML = btnText;
                    return;
                }
                
                notifyDoctor(json.already_processed || json.idempotent_replay ? 'Appointment was already finished.' : 'Appointment finished successfully', 'success');
                if (json.upload_warnings && json.upload_warnings.length > 0) {
                    notifyDoctor('Upload warnings: ' + json.upload_warnings.join('; '), 'warning');
                }

                setTimeout(() => window.location.href = 'doctor.php?page=appointments', 1000);
            } catch (e) {
                console.error(e);
                notifyDoctor('Error saving appointment', 'danger');
                ehrFinishSubmitting = false;
                this.disabled = false;
                this.innerHTML = btnText;
            }
        });

        function notifyDoctor(message, type = 'info') {
            window.showToast(String(message || ''), type);
        }

    })();
</script>

<script>
// Patient Profile modal — reads the currently selected queue option (no separate
// patient_id lookup needed; data-patient-id/data-patient-name were added to each option).
function openDoctorPatientProfileModal() {
    const sel = document.getElementById('queueSelector');
    const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    const patientId = opt ? parseInt(opt.dataset.patientId || '0', 10) : 0;
    const patientName = opt ? (opt.dataset.patientName || '') : '';

    if (!patientId) {
        window.showToast('Select a patient first.', 'warning');
        return;
    }

    const modalEl = document.getElementById('doctorPatientProfileModal');
    const bodyEl = document.getElementById('doctorProfileModalBody');
    if (!modalEl || !bodyEl) return;
    const modal = new bootstrap.Modal(modalEl);
    bodyEl.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-hourglass-split"></i> Loading...</div>';
    modal.show();

    fetch('../assets/api/patients/get_profiling.php?patient_id=' + encodeURIComponent(patientId))
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                bodyEl.innerHTML = '<div class="text-muted text-center py-4">Unable to load patient profile.</div>';
                return;
            }
            bodyEl.innerHTML = renderDoctorPatientProfile(data.patient || {}, data.profiling || {}, patientName);
        })
        .catch(() => {
            bodyEl.innerHTML = '<div class="text-muted text-center py-4">Error loading patient profile.</div>';
        });
}

// renderDoctorPatientProfile() now lives in assets/js/doctor-patient-profile.js
// (shared with doctor's My Patients "Profile" popup and secretary's View Profiling).
</script>

<script>
// Appointment Details modal — identical markup/renderer to the one in the doctor's
// Appointments module ("docApptDetails*" ids, doctorAppointmentDetailsModal), so "view
// appointment" looks and behaves the same everywhere in the doctor portal. Here it's
// triggered from a single toolbar button rather than a per-row "View" button, so this
// thin wrapper builds the same "appt" shape from the selected queue <option>'s dataset
// (plus the live services list) before handing off to the shared renderer.
function openEhrAppointmentDetailsModal() {
    const sel = document.getElementById('queueSelector');
    const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    if (!opt || !opt.value) {
        window.showToast('Select a patient first.', 'warning');
        return;
    }

    const services = (typeof window.getEhrSelectedServices === 'function') ? window.getEhrSelectedServices() : [];

    openDoctorAppointmentDetailsModal({
        appointment_id: opt.value,
        patient_name: opt.dataset.patientName || '',
        email: opt.dataset.email || '',
        phone_number: opt.dataset.phone || '',
        reason: opt.dataset.reason || '',
        notes: opt.dataset.notes || '',
        cancellation_reason: '',
        appointment_date: opt.dataset.apptDate || '',
        appointment_time: opt.dataset.apptTime || '',
        is_online_appointment: opt.dataset.online === '1',
        status: opt.dataset.status || '',
        services_rendered: services.map(function(s) { return s.name; }).filter(Boolean),
    });
}

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Ported from the secretary's openBookingDetailsModal() — same chip/section layout
// and status-box logic, adapted for confirmed appointments: the reference chip shows
// the appointment number (appointments don't have a tracking code) and the "Booking
// Request"/status vocabulary is swapped for appointment statuses.
function openDoctorAppointmentDetailsModal(appt) {
    const statusLabels = {
        scheduled: 'Pending',
        confirmed: 'Confirmed',
        arrived: 'Arrived',
        checked_in: 'Checked-In',
        completed: 'Completed',
        cancelled: 'Cancelled',
        in_progress: 'In Progress',
        no_show: 'No Show',
        awaiting_secretary_records: 'Awaiting Records',
        records_complete: 'Records Complete'
    };
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value && String(value).trim() !== '' ? value : '—';
    };

    const patientName = appt.patient_name || 'Unknown';
    document.getElementById('docApptDetailsPatientName').textContent = patientName;
    const initials = patientName.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '?';
    document.getElementById('docApptDetailsAvatar').textContent = initials;

    setText('docApptDetailsRef', appt.appointment_id ? ('#' + appt.appointment_id) : '');

    let formattedDate = appt.appointment_date;
    if (formattedDate) {
        const d = new Date(String(formattedDate).replace(' ', 'T'));
        if (!Number.isNaN(d.getTime())) {
            formattedDate = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        }
    }
    setText('docApptDetailsDate', formattedDate);

    const timeChip = document.getElementById('docApptDetailsTimeChip');
    const time = (appt.appointment_time || '').trim();
    if (timeChip) timeChip.style.display = time ? '' : 'none';
    setText('docApptDetailsTime', time);

    const typeChip = document.getElementById('docApptDetailsTypeChip');
    if (typeChip) {
        typeChip.innerHTML = appt.is_online_appointment
            ? '<i class="bi bi-laptop"></i> Online'
            : '<i class="bi bi-building"></i> Walk-In';
    }

    setText('docApptDetailsReason', appt.reason);
    const notesWrap = document.getElementById('docApptDetailsNotesWrap');
    const notes = (appt.notes || '').trim();
    if (notesWrap) notesWrap.classList.toggle('d-none', !notes);
    setText('docApptDetailsNotes', notes);

    setText('docApptDetailsEmail', appt.email);
    setText('docApptDetailsPhone', appt.phone_number);

    const statusBox = document.getElementById('docApptDetailsStatusBox');
    const status = String(appt.status || '').toLowerCase();
    const statusLabel = statusLabels[status] || (status || 'Pending');
    if (status === 'cancelled') {
        statusBox.innerHTML = '<div class="dpp-alert-box"><div class="dpp-alert-title"><i class="bi bi-x-circle-fill"></i> Appointment Cancelled</div>'
            + (appt.cancellation_reason ? '<span class="dpp-alert-tag">' + escapeHtml(appt.cancellation_reason) + '</span>' : '<span class="text-muted small">No reason provided.</span>') + '</div>';
    } else if (status === 'no_show') {
        statusBox.innerHTML = '<div class="dpp-alert-box"><div class="dpp-alert-title"><i class="bi bi-exclamation-triangle-fill"></i> Marked as No-Show</div></div>';
    } else if (['completed', 'records_complete'].includes(status)) {
        statusBox.innerHTML = '<div class="dpp-safe-box"><i class="bi bi-check-circle-fill"></i> ' + escapeHtml(statusLabel) + '</div>';
    } else if (['confirmed', 'arrived', 'checked_in', 'in_progress'].includes(status)) {
        statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;"><i class="bi bi-info-circle-fill"></i> ' + escapeHtml(statusLabel) + '</div>';
    } else {
        statusBox.innerHTML = '<div class="dpp-safe-box" style="background:#fffbeb;border-color:#fde68a;color:#92400e;"><i class="bi bi-hourglass-split"></i> ' + escapeHtml(statusLabel) + '.</div>';
    }

    const servicesSection = document.getElementById('docApptDetailsServicesSection');
    const servicesList = document.getElementById('docApptDetailsServicesList');
    const services = Array.isArray(appt.services_rendered) ? appt.services_rendered.filter(Boolean) : [];
    if (services.length > 0) {
        servicesList.innerHTML = services.map(function (s) {
            return '<span class="dpp-chip">' + escapeHtml(s) + '</span>';
        }).join(' ');
        servicesSection.style.display = '';
    } else {
        servicesList.innerHTML = '';
        servicesSection.style.display = 'none';
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('doctorAppointmentDetailsModal')).show();
}
</script>