<?php
// EHR view (renamed from previous profiling implementation)
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

// Allow either appointment_id via GET OR selection by queue number
$appointment_id = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;

// If no appointment_id provided, fetch today's queue items for this doctor
$queueItems = [];
if (!$appointment_id) {
    $qstmt = $conn->prepare("SELECT q.appointment_id, q.que_number, a.patient_id, p.first_name, p.last_name, a.appointment_date FROM check_in_queue q INNER JOIN appointments a ON q.appointment_id = a.appointment_id JOIN patients p ON a.patient_id = p.patient_id WHERE a.user_id = ? AND a.appointment_date = ? AND q.status IN ('waiting','called') ORDER BY q.que_number ASC");
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
        $queueItems[] = ['appointment_id' => $appt['appointment_id'], 'que_number' => '', 'patient_id' => $appt['patient_id'], 'first_name' => $appt['first_name'], 'last_name' => $appt['last_name'], 'appointment_date' => $appt['appointment_date']];
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
            <input type="hidden" name="appointment_id" id="appointment_id_hidden"
                value="<?php echo (int) $appointment_id; ?>">
            <input type="hidden" name="schedule_id" id="schedule_id_hidden"
                value="<?php echo isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : ''; ?>">

            <!-- Integrated Profiling Fields (top of form) -->
            <div class="mb-3">
                <div class="row g-2">
                    <div class="mb-3" id="queueSelectorWrap">
                        <label class="form-label">Select Queue Number / Patient</label>
                        <select id="queueSelector" class="form-select">
                            <option value="">-- Select queue / appointment --</option>
                            <?php foreach ($queueItems as $qi): ?>
                                <?php $display = trim(($qi['que_number'] ? ('Queue #' . $qi['que_number'] . ' - ') : '') . ($qi['first_name'] . ' ' . $qi['last_name'])); ?>
                                <option value="<?php echo (int) $qi['appointment_id']; ?>"
                                    data-que="<?php echo htmlspecialchars($qi['que_number']); ?>">
                                    <?php echo htmlspecialchars($display); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">If you arrived from queue accept, the appointment will already be
                            selected.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Medical History (conditions)</label>
                        <textarea id="pf_medical_history_ehr" name="pf_medical_history" class="form-control" rows="2"
                            placeholder="e.g., Diabetes, Hypertension"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Medications</label>
                        <input id="pf_medications_ehr" name="pf_medications" class="form-control"
                            placeholder="List current medications">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Allergies</label>
                        <input id="pf_allergies_ehr" name="pf_allergies" class="form-control"
                            placeholder="Allergies (e.g., Penicillin)">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Smoking</label>
                        <select id="pf_smoking_ehr" name="pf_smoking" class="form-select">
                            <option value="never">Never</option>
                            <option value="former">Former</option>
                            <option value="current">Current</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Alcohol</label>
                        <select id="pf_alcohol_ehr" name="pf_alcohol" class="form-select">
                            <option value="none">None</option>
                            <option value="social">Social</option>
                            <option value="regular">Regular</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ASA Class</label>
                        <select id="pf_asa_ehr" name="pf_asa" class="form-select">
                            <option value="I">I</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Dental Visit</label>
                        <input type="date" id="pf_last_visit_ehr" name="pf_last_visit" class="form-control">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Periodontal / Oral Exam Notes</label>
                        <textarea id="pf_periodontal_ehr" name="pf_periodontal" class="form-control" rows="2"
                            placeholder="BPE, pocket depths, recession notes"></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Dental Chart / Findings</label>
                        <textarea id="pf_dental_chart_ehr" name="pf_dental_chart" class="form-control" rows="2"
                            placeholder="e.g., caries: #16, restored: #11"></textarea>
                    </div>
                </div>
            </div>


            <div class="mb-3">
                <label class="form-label">Recommended Services</label>
                <div>
                    <?php foreach ($services as $s): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="<?php echo (int) $s['service_id']; ?>"
                                id="srv-<?php echo (int) $s['service_id']; ?>"
                                data-default-duration="<?php echo (int) $s['default_duration_minutes']; ?>">
                            <label class="form-check-label"
                                for="srv-<?php echo (int) $s['service_id']; ?>"><?php echo htmlspecialchars($s['service_name']); ?>
                                — ₱<?php echo number_format($s['initial_price'], 2); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Treatment Plan (structured JSON allowed)</label>
                <textarea class="form-control" name="treatment_plan" id="treatment_plan" rows="3"></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Materials Used</label>
                <div id="materialsList"></div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addMaterialBtn">Add
                    Material</button>
            </div>


            <div class="mb-3">
                <label class="form-label">Doctor Notes</label>
                <textarea class="form-control" name="doctor_notes" id="doctor_notes" rows="3"></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Radiographs / Imaging Notes</label>
                <input id="radiographs_notes" name="radiographs_notes" class="form-control"
                    placeholder="e.g., OPG 2025-08-01">
                <small class="text-muted">Enter radiograph references or imaging notes.</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Upload X‑rays / Tooth Images</label>
                <input type="file" id="toothImagesInput" name="tooth_images[]" accept="image/*" multiple
                    class="form-control">
                <small class="text-muted">Upload one or more x‑ray or tooth images (max 5MB each). These will be saved
                    to the clinical record.</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Follow-up Date</label>
                <input type="date" name="follow_up_date" class="form-control" id="follow_up_date">
            </div>

            <!-- Odontogram moved to be the last input section -->
            <div class="mb-3">
                <label class="form-label"><i class="bi bi-tooth"></i> Odontogram (3D)</label>
                <div class="d-flex align-items-center mb-2 gap-2">
                    <button type="button" id="toggleOdontogramBtn"
                        class="btn btn-sm btn-outline-secondary ms-2">Show</button>
                </div>
                <div id="odontogramContainer">
                    <!-- iframe will be created here on demand -->
                </div>
                <input type="hidden" id="odontogram_data" name="odontogram">
            </div>

            <div class="d-flex gap-2">
                <button type="button" id="saveDraftBtn" class="btn btn-secondary">Save Draft</button>
                <button type="button" id="finalizeBtn" class="btn btn-primary justify-content-center">Submit & Finalize (Create
                    Billing)</button>
            </div>
        </form>
    </div>
</div>

<!-- Scheduling suggestions modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Suggested Time Slots</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="slotsContainer">Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
// The original profiling.js logic is intentionally preserved in this file to
// maintain existing behavior for saving clinical records, scheduling, and
// odontogram integration. No additional server endpoints are required.
?>

<script>
    // Basic EHR frontend behavior: materials list, save draft / finalize, prefill
    (function () {
        // Minimal addMaterial implementation (simple inputs)
        document.getElementById('addMaterialBtn')?.addEventListener('click', function () {
            const wrap = document.getElementById('materialsList');
            const div = document.createElement('div');
            div.className = 'd-flex gap-2 mb-2';
            div.innerHTML = '<input class="form-control form-control-sm" placeholder="Name">\n' +
                '<input class="form-control form-control-sm" placeholder="Qty" style="width:80px;">\n' +
                '<input class="form-control form-control-sm" placeholder="Price" style="width:120px;">\n' +
                '<button type="button" class="btn btn-sm btn-danger">Remove</button>';
            wrap.appendChild(div);
            div.querySelector('button')?.addEventListener('click', () => div.remove());
        });

        async function saveClinical(finalize) {
            const appointment_id = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            const schedule_id = parseInt(document.getElementById('schedule_id_hidden').value || '0', 10) || null;
            const recommended = [...document.querySelectorAll('input[type=checkbox][id^="srv-"]:checked')].map(cb => parseInt(cb.value, 10));
            const treatment_plan = document.getElementById('treatment_plan').value || '';
            const materials = [...document.getElementById('materialsList').querySelectorAll('div')].map(d => {
                const inputs = d.querySelectorAll('input');
                return { name: inputs[0]?.value || '', qty: parseFloat(inputs[1]?.value) || 0, price: parseFloat(inputs[2]?.value) || 0 };
            });
            const odontogram = document.getElementById('odontogram_data')?.value || '';
            const doctor_notes = document.getElementById('doctor_notes')?.value || '';
            const follow_up_date = document.getElementById('follow_up_date')?.value || '';

            // profiling payload
            const profiling = {
                medical_history: document.getElementById('pf_medical_history_ehr')?.value || '',
                medications: document.getElementById('pf_medications_ehr')?.value || '',
                allergies: document.getElementById('pf_allergies_ehr')?.value || '',
                smoking: document.getElementById('pf_smoking_ehr')?.value || 'never',
                alcohol: document.getElementById('pf_alcohol_ehr')?.value || 'none',
                asa: document.getElementById('pf_asa_ehr')?.value || 'I',
                periodontal: document.getElementById('pf_periodontal_ehr')?.value || '',
                dental_chart: document.getElementById('pf_dental_chart_ehr')?.value || '',
                last_visit: document.getElementById('pf_last_visit_ehr')?.value || ''
            };

            const radiographs_notes = document.getElementById('radiographs_notes')?.value || '';

            const fd = new FormData();
            fd.append('appointment_id', appointment_id);
            if (schedule_id) fd.append('schedule_id', schedule_id);
            fd.append('recommended_services', JSON.stringify(recommended));
            fd.append('treatment_plan', treatment_plan);
            fd.append('materials_used', JSON.stringify(materials));
            fd.append('odontogram', odontogram);
            fd.append('doctor_notes', doctor_notes);
            if (follow_up_date) fd.append('follow_up_date', follow_up_date);
            fd.append('finalize', finalize ? '1' : '0');

            // attach profiling + imaging notes
            fd.append('profiling', JSON.stringify(profiling));
            fd.append('radiographs_notes', radiographs_notes);

            // attach tooth image files (if any)
            const toothInput = document.getElementById('toothImagesInput');
            if (toothInput && toothInput.files && toothInput.files.length) {
                for (let i = 0; i < toothInput.files.length; i++) fd.append('tooth_images[]', toothInput.files[i]);
            }

            try {
                const resp = await fetch('../assets/api/ehr/save_clinical_record.php', { method: 'POST', body: fd });
                const j = await resp.json();
                if (j.success) {
                    alert('Saved' + (finalize ? ' and finalized. Billing created: ₱' + (j.billing_amount || 0) : ''));
                    if (j.schedule_id) document.getElementById('schedule_id_hidden').value = j.schedule_id;
                    if (j.needs_scheduling) {
                        showSchedulingModal({ appointment_id, duration: 30 });
                    } else if (finalize) {
                        window.location.href = 'doctor.php?page=appointments';
                    }
                } else {
                    alert('Error: ' + (j.message || 'unknown'));
                }
            } catch (e) { alert('Request failed'); console.error(e); }
        }

        document.getElementById('saveDraftBtn')?.addEventListener('click', () => saveClinical(false));
        document.getElementById('finalizeBtn')?.addEventListener('click', () => { if (confirm('Finalize and create billing?')) saveClinical(true); });

        // Show scheduling modal (lightweight)
        async function showSchedulingModal(opts) {
            const modalEl = document.getElementById('scheduleModal');
            const bsModal = new bootstrap.Modal(modalEl);
            const slotsContainer = document.getElementById('slotsContainer');
            slotsContainer.innerHTML = 'Loading...';
            bsModal.show();
            try {
                const fd = new URLSearchParams();
                fd.append('user_id', '<?php echo (int) $_SESSION['user_id']; ?>');
                fd.append('appointment_id', opts.appointment_id);
                fd.append('duration', opts.duration || 30);
                const r = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (!j.success) { slotsContainer.innerHTML = '<div class="text-danger">Could not load slots</div>'; return; }
                const slots = j.slots || [];
                if (!slots.length) { slotsContainer.innerHTML = '<div class="text-muted">No available slots.</div>'; return; }
                const list = document.createElement('div'); list.className = 'list-group';
                slots.forEach(s => {
                    const item = document.createElement('div'); item.className = 'list-group-item d-flex justify-content-between align-items-center';
                    item.innerHTML = `<div>${s.start} — ${s.end}</div>`;
                    const btn = document.createElement('button'); btn.className = 'btn btn-sm btn-primary'; btn.textContent = 'Book';
                    btn.addEventListener('click', async () => {
                        btn.disabled = true; btn.textContent = 'Booking...';
                        const bfd = new URLSearchParams();
                        bfd.append('user_id', '<?php echo (int) $_SESSION['user_id']; ?>');
                        bfd.append('appointment_id', opts.appointment_id);
                        bfd.append('book_start', s.start);
                        bfd.append('book_end', s.end);
                        const br = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: bfd });
                        const bj = await br.json();
                        if (bj.success) { alert('Scheduled'); document.getElementById('schedule_id_hidden').value = bj.schedule_id; bsModal.hide(); }
                        else { alert('Book failed: ' + (bj.message || '')); btn.disabled = false; btn.textContent = 'Book'; }
                    });
                    item.appendChild(btn); list.appendChild(item);
                });
                slotsContainer.innerHTML = ''; slotsContainer.appendChild(list);
            } catch (e) { slotsContainer.innerHTML = '<div class="text-danger">Error loading slots.</div>'; }
        }

        // Prefill clinical record for an appointment
        async function prefillClinicalRecord(appointmentId) {
            if (!appointmentId) return;
            try {
                const r = await fetch('../assets/api/ehr/get_clinical_record.php?appointment_id=' + encodeURIComponent(appointmentId));
                const data = await r.json();
                if (!data || !data.record) return;
                const rec = data.record;
                if (rec.recommended_services && Array.isArray(rec.recommended_services)) rec.recommended_services.forEach(id => { const cb = document.getElementById('srv-' + id); if (cb) cb.checked = true; });
                if (rec.treatment_plan) document.getElementById('treatment_plan').value = typeof rec.treatment_plan === 'object' ? JSON.stringify(rec.treatment_plan, null, 2) : rec.treatment_plan;
                if (rec.materials_used && Array.isArray(rec.materials_used)) rec.materials_used.forEach(m => {/* not recreating rows to keep simple */ });
                if (rec.doctor_notes) document.getElementById('doctor_notes').value = rec.doctor_notes;
                if (rec.follow_up_date) document.getElementById('follow_up_date').value = rec.follow_up_date;
                // prefill profiling if present
                try {
                    const pf = rec.profiling || rec.patient_profiling || null;
                    if (pf) {
                        const p = (typeof pf === 'string') ? JSON.parse(pf) : pf;
                        document.getElementById('pf_medical_history_ehr').value = p.medical_history || '';
                        document.getElementById('pf_medications_ehr').value = p.medications || '';
                        document.getElementById('pf_allergies_ehr').value = p.allergies || '';
                        document.getElementById('pf_smoking_ehr').value = p.smoking || 'never';
                        document.getElementById('pf_alcohol_ehr').value = p.alcohol || 'none';
                        document.getElementById('pf_asa_ehr').value = p.asa || 'I';
                        document.getElementById('pf_periodontal_ehr').value = p.periodontal || '';
                        document.getElementById('pf_dental_chart_ehr').value = p.dental_chart || '';
                        document.getElementById('pf_last_visit_ehr').value = p.last_visit || '';
                    }
                } catch (e) { console.warn('prefill profiling', e); }
                if (rec.odontogram) { document.getElementById('odontogram_data').value = JSON.stringify(rec.odontogram); }
                if (rec.radiographs_notes) { document.getElementById('radiographs_notes').value = rec.radiographs_notes || ''; }
                // show existing images / tooth image thumbnails
                try {
                    const wrap = document.getElementById('existingImages');
                    if (wrap) {
                        wrap.innerHTML = '';
                        if (rec.images && Array.isArray(rec.images)) {
                            rec.images.forEach(src => {
                                const a = document.createElement('a'); a.href = src; a.target = '_blank';
                                const img = document.createElement('img'); img.src = src; img.className = 'img-thumbnail'; img.style.maxWidth = '120px'; img.style.maxHeight = '90px';
                                a.appendChild(img); wrap.appendChild(a);
                            });
                        }
                        if (rec.tooth_image) {
                            const src = rec.tooth_image;
                            const a = document.createElement('a'); a.href = src; a.target = '_blank';
                            const img = document.createElement('img'); img.src = src; img.className = 'img-thumbnail border-primary'; img.style.maxWidth = '120px'; img.style.maxHeight = '90px';
                            a.appendChild(img); wrap.appendChild(a);
                        }
                    }
                } catch (e) { console.warn('show existing images', e); }
            } catch (e) { console.warn('prefill failed', e); }
        }

        // Wire queue selector
        document.getElementById('queueSelector')?.addEventListener('change', function () {
            const apptId = parseInt(this.value || '0', 10);
            document.getElementById('appointment_id_hidden').value = apptId;
            const label = this.options[this.selectedIndex]?.text || '';
            document.getElementById('apptInfo').textContent = apptId ? label : 'Not selected';
            if (apptId) prefillClinicalRecord(apptId);
        });

        // On page load, prefill if appointment provided
        document.addEventListener('DOMContentLoaded', function () {
            const initialAppt = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            if (initialAppt) {
                const sel = document.getElementById('queueSelector');
                if (sel) for (let i = 0; i < sel.options.length; i++) if (parseInt(sel.options[i].value || '0', 10) === initialAppt) { sel.selectedIndex = i; break; }
                const chosenText = sel ? (sel.options[sel.selectedIndex]?.text || initialAppt) : initialAppt;
                document.getElementById('apptInfo').textContent = chosenText;
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

        // cleanup: removed extra controls; toggle button handles show/hide and reload
    })();
</script>