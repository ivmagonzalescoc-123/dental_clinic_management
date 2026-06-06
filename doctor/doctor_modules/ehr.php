<?php
// EHR view (renamed from previous profiling implementation)
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

// Allow either appointment_id via GET OR selection by queue number
$appointment_id = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;

// If no appointment_id provided, fetch today's arrived patients for this doctor
$queueItems = [];
if (!$appointment_id) {
    $sql = "SELECT
                                a.appointment_id,
                                a.is_emergency,
                                a.patient_id,
                                p.first_name,
                                p.last_name,
                                COALESCE(t.slot_date, a.appointment_date) AS appointment_date,
                                CASE
                                    WHEN a.status = 'in_progress' THEN 'in_progress'
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
                            AND (a.status IN ('checked_in','in_progress','confirmed') OR cr.record_id IS NOT NULL)
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
    $stmt = $conn->prepare('SELECT a.*, p.first_name, p.last_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($appt) {
        // populate a single queue-like item so UI can reuse the same dropdown/prefill logic
        $queueItems[] = ['appointment_id' => $appt['appointment_id'], 'patient_id' => $appt['patient_id'], 'first_name' => $appt['first_name'], 'last_name' => $appt['last_name'], 'appointment_date' => $appt['appointment_date']];
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

// Fetch treatment catalog for lookup selection
$treatments = [];
$tstmt = $conn->prepare('SELECT treatment_id, treatment_name, treatment_type FROM treatments ORDER BY treatment_name ASC');
if ($tstmt) {
    $tstmt->execute();
    $tres = $tstmt->get_result();
    while ($tr = $tres->fetch_assoc()) {
        $treatments[] = $tr;
    }
    $tstmt->close();
}
?>

<div class="card border-0 shadow-none">
    <input type="hidden" name="appointment_id" id="appointment_id_hidden" value="<?php echo (int) $appointment_id; ?>">
    <input type="hidden" name="schedule_id" id="schedule_id_hidden"
        value="<?php echo isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : ''; ?>">

    <!-- Integrated Profiling Fields (top of form) -->
    <div class="mb-3">
        <div id="queueSelectorWrap">
            <label class="form-label">Select Patient from Queue</label>
            <select id="queueSelector" class="form-select">
                <option value="">-- Select patient --</option>
                <?php foreach ($queueItems as $qi): ?>
                                <?php
                                    $display = trim($qi['first_name'] . ' ' . (!empty($qi['middle_name']) ? $qi['middle_name'] . ' ' : '') . $qi['last_name']);
                                    if (!empty($qi['is_emergency'])) {
                                        $display .= ' (EMERGENCY)';
                                    }
                                    // Indicate if a clinical record already exists for this appointment
                                    $hasRecord = !empty($qi['record_id']);
                                    if ($hasRecord) {
                                        $display .= ' — Record';
                                    }
                                ?>
                                <option value="<?php echo (int) $qi['appointment_id']; ?>" data-emergency="<?php echo !empty($qi['is_emergency']) ? '1' : '0'; ?>" data-has-record="<?php echo $hasRecord ? '1' : '0'; ?>">
                                    <?php echo htmlspecialchars($display); ?>
                                </option>
                    <?php endforeach; ?>
            </select>
            <div class="form-text">Select a patient to begin or continue a session.</div>
        </div>
        <div id="apptInfo" class="alert alert-info mt-3" style="display:none;"></div>
    </div>

    <!-- Clinical Inputs -->
    <div class="mb-4 p-3 border rounded">
        <h5 class="mb-3">Clinical Inputs</h5>
        <!-- Photo Gallery Section -->
        <div class="mb-4">
            <h6 class="mb-3">
                <i class="fas fa-images"></i> Appointment Photos
                <span class="badge bg-info ms-2" id="photoCountBadge">0</span>
            </h6>
            <div id="photoGalleryContainer" class="row g-2">
                <p class="text-muted small">No photos uploaded yet</p>
            </div>
        </div>

        <!-- Upload Photo Section -->
        <div class="mb-4 p-3 bg-light rounded">
            <h6 class="mb-3">
                <i class="fas fa-plus-circle"></i> Add Photos
            </h6>
            <div class="row g-2">
                <div class="col-6">
                    <input type="file" id="finishPhotoUpload" class="form-control" accept="image/*" multiple hidden>
                    <button type="button" class="btn btn-outline-primary w-100" onclick="document.getElementById('finishPhotoUpload').click()">
                        <i class="fas fa-upload"></i> Upload Photos
                    </button>
                </div>
                <div class="col-6">
                    <button type="button" class="btn btn-outline-info w-100" id="cameraButton">
                        <i class="fas fa-camera"></i> Take Photo
                    </button>
                </div>
            </div>
            <small class="text-muted d-block mt-2">Support: JPG, PNG. Max 5MB each</small>
            <!-- Hidden video element for camera capture -->
            <video id="cameraPreviewVideo" style="display: none; max-width: 100%; max-height: 300px; margin: 10px 0; border: 2px solid #0d6efd; border-radius: 5px;"></video>
            <canvas id="cameraCaptureCanvas" style="display: none;"></canvas>
        </div>

        <!-- Doctor Notes Section -->
        <div class="mb-4">
            <h6 class="mb-2">
                <i class="fas fa-sticky-note"></i> Clinical Notes
            </h6>
            <textarea id="doctor_notes" class="form-control" rows="4" placeholder="Enter clinical notes, findings, recommendations..." style="resize: vertical;"></textarea>
            <small class="text-muted d-block mt-1">These notes will be visible to the secretary when creating records</small>
        </div>

        <!-- Odontogram moved to be the last input section -->
        <div class="mb-3">
            <label class="form-label"><i class="bi bi-tooth"></i> Odontogram (3D)</label>
            <div class="d-flex align-items-center mb-2 gap-2">
                <button type="button" id="toggleOdontogramBtn" class="btn btn-sm btn-outline-secondary ms-2">Show</button>
                <button type="button" id="openOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark">Full Screen</button>
            </div>
            <div id="odontogramViewport" class="odontogram-viewport">
                <button type="button" id="closeOdontogramFullscreenBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                    <i class="bi bi-x-lg"></i> Close
                </button>
                <div id="odontogramContainer">
                    <!-- iframe will be created here on demand -->
                </div>
            </div>
            <input type="hidden" id="odontogram_data" name="odontogram">
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="button" id="submitFinishAppointmentBtn" class="btn btn-primary">
            <i class="fas fa-check"></i> Finish Appointment
        </button>
    </div>
    </form>
    </div>
    </div>

<?php
// The original profiling.js logic is intentionally preserved in this file to
// maintain existing behavior for saving clinical records, scheduling, and
// odontogram integration. No additional server endpoints are required.
?>

<style>
.odontogram-viewport {
    position: relative;
}

.odontogram-fs-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 30;
}

.odontogram-viewport:fullscreen {
    background: #ffffff;
    padding: 10px;
}

.odontogram-viewport:fullscreen #odontogramContainer,
.odontogram-viewport:-webkit-full-screen #odontogramContainer {
    height: calc(100vh - 20px);
}

.odontogram-viewport:fullscreen #odontogramFrame,
.odontogram-viewport:-webkit-full-screen #odontogramFrame {
    height: calc(100vh - 20px) !important;
    border: 0 !important;
}
</style>

<script>
    const doctorCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
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
                if (rec.follow_up_date) document.getElementById('follow_up_date').value = rec.follow_up_date;

                if (rec.odontogram) { document.getElementById('odontogram_data').value = JSON.stringify(rec.odontogram); }
            } catch (e) { console.warn('prefill failed', e); }
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
            if (apptId) prefillClinicalRecord(apptId);
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
                prefillClinicalRecord(initialAppt);
            }
            // Auto-load odontogram iframe if data exists
            try {
                const odData = document.getElementById('odontogram_data')?.value || '';
                if (odData) {
                    ensureOdontogramFrame();
                    postOdontogramToFrame(odData);
                    showOdontogram();
                } else {
                    // start hidden if no data
                    hideOdontogram();
                }
            } catch (e) { console.warn('odontogram autoload', e); }
            // ensure toggle reflects initial state
            try { updateOdontogramToggle(); } catch (e) { }
        });

        // Create odontogram iframe if not exists
        function ensureOdontogramFrame() {
            if (document.getElementById('odontogramFrame')) return document.getElementById('odontogramFrame');
            const container = document.getElementById('odontogramContainer');
            if (!container) return null;
            const iframe = document.createElement('iframe');
            iframe.id = 'odontogramFrame';
            iframe.src = '../odontogram/index.html';
            iframe.style.width = '100%';
            iframe.style.height = '480px';
            iframe.style.border = '1px solid #ddd';
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
                const msg = { type: 'load_odontogram', toothState: payload };
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
            document.getElementById('toggleOdontogramBtn').textContent = 'Hide';
        }
        function hideOdontogram() {
            const container = document.getElementById('odontogramContainer');
            if (!container) return;
            container.style.display = 'none';
            document.getElementById('toggleOdontogramBtn').textContent = 'Show';
        }
        function updateOdontogramToggle() {
            const container = document.getElementById('odontogramContainer');
            const btn = document.getElementById('toggleOdontogramBtn');
            if (!container || !btn) return;
            if (container.style.display === 'none') btn.textContent = 'Show';
            else btn.textContent = 'Hide';
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

        function syncOdontogramFullscreenUI() {
            const closeBtn = document.getElementById('closeOdontogramFullscreenBtn');
            const openBtn = document.getElementById('openOdontogramFullscreenBtn');
            const active = isOdontogramFullscreen();

            if (closeBtn) closeBtn.style.display = active ? '' : 'none';
            if (openBtn) openBtn.textContent = active ? 'Exit Full Screen' : 'Full Screen';
        }

        // toggle button
        document.getElementById('toggleOdontogramBtn')?.addEventListener('click', function () {
            const container = document.getElementById('odontogramContainer');
            if (!container) return;
            if (container.style.display === 'none') {
                showOdontogram();
                const od = document.getElementById('odontogram_data')?.value || '';
                if (od) postOdontogramToFrame(od);
            } else {
                hideOdontogram();
            }
        });

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

        // Photo Upload Handler
        document.getElementById('finishPhotoUpload').addEventListener('change', async function(e) {
            const files = Array.from(e.target.files);
            if (!files.length) return;
            
            for (const file of files) {
                if (!file.type.startsWith('image/')) {
                    notifyDoctor('Only image files allowed', 'warning');
                    continue;
                }
                if (file.size > 5 * 1024 * 1024) {
                    notifyDoctor(`File ${file.name} exceeds 5MB limit`, 'warning');
                    continue;
                }
                uploadedPhotos.push(file);
            }
            
            updatePhotoGalleryPreview();
        });

        // Camera Capture Handler
        document.getElementById('cameraButton').addEventListener('click', async function() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                const video = document.getElementById('cameraPreviewVideo');
                video.srcObject = stream;
                video.style.display = 'block';
                video.play();
                
                let captureBtn = document.getElementById('photoCaptureBtn');
                if (!captureBtn) {
                    captureBtn = document.createElement('button');
                    captureBtn.id = 'photoCaptureBtn';
                    captureBtn.type = 'button';
                    captureBtn.className = 'btn btn-sm btn-success mt-2';
                    captureBtn.innerHTML = '<i class="fas fa-camera"></i> Capture';
                    captureBtn.addEventListener('click', () => capturePhoto(stream));
                    video.parentNode.insertBefore(captureBtn, video.nextSibling);
                }
                
                let stopBtn = document.getElementById('photoStopBtn');
                if (!stopBtn) {
                    stopBtn = document.createElement('button');
                    stopBtn.id = 'photoStopBtn';
                    stopBtn.type = 'button';
                    stopBtn.className = 'btn btn-sm btn-secondary mt-2 ms-2';
                    stopBtn.innerHTML = '<i class="fas fa-times"></i> Stop';
                    stopBtn.addEventListener('click', () => {
                        stream.getTracks().forEach(track => track.stop());
                        video.style.display = 'none';
                        captureBtn.remove();
                        stopBtn.remove();
                        document.getElementById('cameraButton').disabled = false;
                    });
                    captureBtn.parentNode.insertBefore(stopBtn, captureBtn.nextSibling);
                }
                
                this.disabled = true;
            } catch (err) {
                notifyDoctor('Camera access denied or unavailable', 'danger');
                console.error('Camera error:', err);
            }
        });

        function capturePhoto(stream) {
            const video = document.getElementById('cameraPreviewVideo');
            const canvas = document.getElementById('cameraCaptureCanvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            
            canvas.toBlob((blob) => {
                const file = new File([blob], `capture_${Date.now()}.jpg`, { type: 'image/jpeg' });
                uploadedPhotos.push(file);
                updatePhotoGalleryPreview();
                notifyDoctor('Photo captured', 'success');
            }, 'image/jpeg', 0.9);
        }

        function updatePhotoGalleryPreview() {
            const container = document.getElementById('photoGalleryContainer');
            document.getElementById('photoCountBadge').textContent = uploadedPhotos.length;
            
            if (uploadedPhotos.length === 0) {
                container.innerHTML = '<p class="text-muted small">No photos uploaded yet</p>';
                return;
            }
            
            container.innerHTML = '';
            uploadedPhotos.forEach((file, idx) => {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const col = document.createElement('div');
                    col.className = 'col-sm-6 col-md-4 position-relative';
                    col.innerHTML = `
                        <div style="position: relative; overflow: hidden; border-radius: 8px; background: #f0f0f0; aspect-ratio: 3/2;">
                            <img src="${e.target.result}" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;">
                            <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onclick="removeUploadedPhoto(${idx})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                    container.appendChild(col);
                };
                reader.readAsDataURL(file);
            });
        }

        window.removeUploadedPhoto = function(idx) {
            uploadedPhotos.splice(idx, 1);
            updatePhotoGalleryPreview();
        }

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
                
                const odontogramData = document.getElementById('odontogram_data')?.value || '';
                if (odontogramData) {
                    fd.append('odontogram', odontogramData);
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
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), type);
                return;
            }
            alert(String(message || ''));
        }

    })();
</script>