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
                                t.start_time
                        FROM appointments a
                        LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
                        JOIN patients p ON a.patient_id = p.patient_id
                        WHERE COALESCE(a.user_id, t.doctor_id) = ?
                            AND COALESCE(t.slot_date, a.appointment_date) = ?
                            AND a.status IN ('checked_in','in_progress','confirmed')
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
        <div class="row g-2">
            <div class="mb-3" id="queueSelectorWrap">
                <label class="form-label">Select Queue Number / Patient</label>
                <select id="queueSelector" class="form-select">
                    <option value="">-- Select patient --</option>
                    <?php foreach ($queueItems as $qi): ?>
                            <?php
                                $display = trim($qi['first_name'] . ' ' . $qi['last_name']);
                                // Indicate emergency in dropdown text (plain text) so it's visible to doctors
                                if (!empty($qi['is_emergency'])) {
                                    $display .= ' (EMERGENCY)';
                                }
                            ?>
                            <option value="<?php echo (int) $qi['appointment_id']; ?>" data-emergency="<?php echo !empty($qi['is_emergency']) ? '1' : '0'; ?>">
                                <?php echo htmlspecialchars($display); ?>
                            </option>
                        <?php endforeach; ?>
                </select>
                <div class="form-text">If you arrived from queue accept, the appointment will already be
                    selected.</div>
            </div>

            <div class="col-12">
                <label class="form-label">Oral Exam</label>
                <textarea id="pf_periodontal_ehr" name="pf_periodontal" class="form-control" rows="2"
                    placeholder="Input oral exam"></textarea>
            </div>

            <div class="col-12">
                <label class="form-label">Findings</label>
                <textarea id="pf_dental_chart_ehr" name="pf_dental_chart" class="form-control" rows="2"
                    placeholder="Input findings"></textarea>
            </div>
        </div>
    </div>


    <div class="mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
            <label class="form-label mb-0">Recommended Services and Treatments</label>
            <button type="button" id="ehrServiceToggleBtn" class="btn btn-outline-primary btn-sm" aria-expanded="false">
                <i id="ehrServiceToggleIcon" class="bi bi-plus-circle"></i>
                <span id="ehrServiceToggleText">Add Services</span>
            </button>
        </div>
        <small class="text-muted d-block mb-2">Toggle this section to add services and treatments without extending the form.</small>

        <div id="ehrServiceTreatmentPanel" style="display:none;">
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="border rounded p-3 h-100">
                        <label class="form-label">Recommended Services</label>
                        <div id="ehrServicesList" style="max-height: 220px; overflow-y: auto;">
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
                </div>

                <div class="col-lg-6">
                    <div class="border rounded p-3 h-100">
                        <label class="form-label">Treatment Lookup</label>
                        <input type="text" class="form-control mb-2" id="treatmentLookupInput" placeholder="Search treatment name or type...">
                        <div id="treatmentLookupList" class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                            <?php if (!empty($treatments)): ?>
                                <?php foreach ($treatments as $t): ?>
                                    <?php
                                        $lookupText = strtolower(trim((string)($t['treatment_name'] ?? '') . ' ' . (string)($t['treatment_type'] ?? '')));
                                    ?>
                                    <div class="form-check treatment-lookup-item" data-lookup="<?php echo htmlspecialchars($lookupText); ?>">
                                        <input class="form-check-input treatment-lookup-checkbox" type="checkbox" value="<?php echo (int)$t['treatment_id']; ?>"
                                            id="trt-<?php echo (int)$t['treatment_id']; ?>">
                                        <label class="form-check-label" for="trt-<?php echo (int)$t['treatment_id']; ?>">
                                            <?php echo htmlspecialchars($t['treatment_name']); ?>
                                            <?php if (!empty($t['treatment_type'])): ?>
                                                <span class="text-muted">(<?php echo htmlspecialchars($t['treatment_type']); ?>)</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small">No treatments found. Add treatments from Admin Services.</div>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">Select specific treatments performed or recommended for this session.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">Treatment Plan</label>
        <textarea class="form-control" name="treatment_plan" id="treatment_plan" rows="3"></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label">Materials Used</label>
        <div class="d-flex flex-wrap gap-2 mb-2" id="materialQuickPick"></div>
        <div class="input-group input-group-sm mb-2 compact-control">
            <input type="text" id="materialPresetInput" class="form-control" list="materialCatalogList" placeholder="Type or pick common material">
            <button type="button" class="btn btn-outline-secondary" id="addPresetMaterialBtn">Add</button>
        </div>
        <datalist id="materialCatalogList"></datalist>
        <div id="materialsList"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addMaterialBtn">Add
            Material</button>
        <small class="text-muted d-block mt-2">Tip: use quick picks for common items, then adjust quantity and price per case.</small>
    </div>


    <div class="mb-3">
        <label class="form-label">Doctor Notes</label>
        <textarea class="form-control" name="doctor_notes" id="doctor_notes" rows="3"></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label">Upload X‑rays / Tooth Images</label>
        <input type="file" id="toothImagesInput" name="tooth_images[]" accept="image/*" multiple class="form-control">
        <small class="text-muted">Upload one or more x‑ray or tooth images (max 5MB each). These will be saved
            to the clinical record.</small>
    </div>

    <div class="mb-3">
        <label class="form-label">Radiographs / Imaging Notes</label>
        <input id="radiographs_notes" name="radiographs_notes" class="form-control" placeholder="e.g., OPG 2025-08-01">
        <small class="text-muted">Enter radiograph references or imaging notes.</small>
    </div>

    <div class="mb-3">
        <label class="form-label">Follow-up Date</label>
        <input type="date" name="follow_up_date" class="form-control" id="follow_up_date">
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

    <div class="d-flex gap-2">
        <button type="button" id="saveDraftBtn" class="btn btn-secondary">Save Draft</button>
        <button type="button" id="finalizeBtn" class="btn btn-primary justify-content-center">Submit & Finalize (Create
            Billing)</button>
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
    // Basic EHR frontend behavior: materials list, save draft / finalize, prefill
    (function () {
        const serviceToggleBtn = document.getElementById('ehrServiceToggleBtn');
        const serviceToggleText = document.getElementById('ehrServiceToggleText');
        const serviceToggleIcon = document.getElementById('ehrServiceToggleIcon');
        const servicePanel = document.getElementById('ehrServiceTreatmentPanel');

        function setServicePanelState(expanded) {
            if (!servicePanel || !serviceToggleText || !serviceToggleIcon) return;
            servicePanel.style.display = expanded ? 'block' : 'none';
            serviceToggleText.textContent = expanded ? 'Hide Services' : 'Add Services';
            serviceToggleIcon.className = expanded ? 'bi bi-dash-circle' : 'bi bi-plus-circle';
            if (serviceToggleBtn) {
                serviceToggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        }

        if (serviceToggleBtn && servicePanel) {
            setServicePanelState(false);
            serviceToggleBtn.addEventListener('click', function () {
                const isExpanded = servicePanel.style.display !== 'none';
                setServicePanelState(!isExpanded);
            });
        }

        const defaultMaterialCatalog = [
            'Local anesthesia cartridge',
            'Topical anesthetic',
            'Cotton rolls',
            'Gauze',
            'Gloves (pair)',
            'Mask',
            'Saliva ejector',
            'Suction tip',
            'Etchant gel',
            'Bonding agent',
            'Composite resin',
            'Glass ionomer cement',
            'Temporary filling material',
            'Impression material',
            'Prophy paste',
            'Fluoride varnish'
        ];

        const materialsList = document.getElementById('materialsList');
        const quickPickWrap = document.getElementById('materialQuickPick');
        const presetInput = document.getElementById('materialPresetInput');
        const datalistEl = document.getElementById('materialCatalogList');

        function materialRowMarkup(materialName, qty, price) {
            return '<div class="row g-2 align-items-end material-row mb-2">' +
                '<div class="col-12 col-md-6">' +
                '<input class="form-control form-control-sm material-name" placeholder="Material name" list="materialCatalogList" value="' + materialName + '">' +
                '</div>' +
                '<div class="col-6 col-md-2">' +
                '<input class="form-control form-control-sm material-qty" type="number" min="0" step="0.01" placeholder="Qty" value="' + qty + '">' +
                '</div>' +
                '<div class="col-6 col-md-2">' +
                '<input class="form-control form-control-sm material-price" type="number" min="0" step="0.01" placeholder="Price" value="' + price + '">' +
                '</div>' +
                '<div class="col-12 col-md-2">' +
                '<button type="button" class="btn btn-sm btn-outline-danger w-100 material-remove">Remove</button>' +
                '</div>' +
                '</div>';
        }

        function addMaterialRow(material = {}) {
            if (!materialsList) return;
            const name = String(material.name || '').replace(/"/g, '&quot;');
            const qty = (material.qty !== undefined && material.qty !== null) ? String(material.qty).replace(/"/g, '&quot;') : '';
            const price = (material.price !== undefined && material.price !== null) ? String(material.price).replace(/"/g, '&quot;') : '';

            const wrap = document.createElement('div');
            wrap.innerHTML = materialRowMarkup(name, qty, price);
            const row = wrap.firstChild;
            row.querySelector('.material-remove')?.addEventListener('click', function () {
                row.remove();
            });
            materialsList.appendChild(row);
            return row;
        }

        function setupMaterialCatalog() {
            if (datalistEl) {
                datalistEl.innerHTML = '';
                defaultMaterialCatalog.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item;
                    datalistEl.appendChild(opt);
                });
            }

            if (quickPickWrap) {
                quickPickWrap.innerHTML = '';
                defaultMaterialCatalog.slice(0, 8).forEach(item => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-outline-secondary rounded-pill';
                    btn.textContent = item;
                    btn.addEventListener('click', function () {
                        const row = addMaterialRow({ name: item, qty: 1, price: '' });
                        const qtyInput = row?.querySelector('.material-qty');
                        if (qtyInput) qtyInput.focus();
                    });
                    quickPickWrap.appendChild(btn);
                });
            }
        }

        setupMaterialCatalog();

        document.getElementById('addPresetMaterialBtn')?.addEventListener('click', function () {
            const value = (presetInput?.value || '').trim();
            if (!value) return;
            addMaterialRow({ name: value, qty: 1, price: '' });
            presetInput.value = '';
        });

        presetInput?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('addPresetMaterialBtn')?.click();
            }
        });

        // Add manual material row
        document.getElementById('addMaterialBtn')?.addEventListener('click', function () {
            addMaterialRow();
        });

        if (materialsList && materialsList.children.length === 0) {
            addMaterialRow();
        }

        async function saveClinical(finalize) {
            const appointment_id = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
            const schedule_id = parseInt(document.getElementById('schedule_id_hidden').value || '0', 10) || null;
            const recommended = [...document.querySelectorAll('input[type=checkbox][id^="srv-"]:checked')].map(cb => parseInt(cb.value, 10));
            const selectedTreatments = [...document.querySelectorAll('.treatment-lookup-checkbox:checked')]
                .map(cb => parseInt(cb.value, 10))
                .filter(v => Number.isInteger(v) && v > 0);
            const treatment_plan = document.getElementById('treatment_plan').value || '';
            const materials = [...document.querySelectorAll('#materialsList .material-row')].map(row => ({
                name: (row.querySelector('.material-name')?.value || '').trim(),
                qty: parseFloat(row.querySelector('.material-qty')?.value || '') || 0,
                price: parseFloat(row.querySelector('.material-price')?.value || '') || 0
            })).filter(item => item.name !== '');
            const odontogram = document.getElementById('odontogram_data')?.value || '';
            const doctor_notes = document.getElementById('doctor_notes')?.value || '';
            const follow_up_date = document.getElementById('follow_up_date')?.value || '';

            const radiographs_notes = document.getElementById('radiographs_notes')?.value || '';
            const profiling = {
                periodontal: document.getElementById('pf_periodontal_ehr')?.value || '',
                dental_chart: document.getElementById('pf_dental_chart_ehr')?.value || ''
            };

            const fd = new FormData();
            fd.append('appointment_id', appointment_id);
            if (schedule_id) fd.append('schedule_id', schedule_id);
            fd.append('recommended_services', JSON.stringify(recommended));
            fd.append('selected_treatments', JSON.stringify(selectedTreatments));
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
                    alert(finalize ? ('Finalized. Billing: ₱' + (j.billing_amount || 0)) : 'Saved.');
                    if (j.schedule_id) document.getElementById('schedule_id_hidden').value = j.schedule_id;
                    if (finalize) {
                        window.location.href = 'doctor.php?page=appointments';
                    }
                } else {
                    alert('Error: ' + (j.message || 'unknown'));
                }
            } catch (e) { alert('Request failed'); console.error(e); }
        }

        document.getElementById('saveDraftBtn')?.addEventListener('click', () => saveClinical(false));
        document.getElementById('finalizeBtn')?.addEventListener('click', () => { if (confirm('Finalize and create billing?')) saveClinical(true); });

        function applyTreatmentLookupFilter() {
            const q = (document.getElementById('treatmentLookupInput')?.value || '').trim().toLowerCase();
            document.querySelectorAll('.treatment-lookup-item').forEach(item => {
                const hay = (item.getAttribute('data-lookup') || '').toLowerCase();
                item.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
            });
        }

        document.getElementById('treatmentLookupInput')?.addEventListener('input', applyTreatmentLookupFilter);
        applyTreatmentLookupFilter();

        // Scheduling modal and automatic suggestions were removed per UX decision.

        // Prefill clinical record for an appointment
        async function prefillClinicalRecord(appointmentId) {
            if (!appointmentId) return;
            try {
                const r = await fetch('../assets/api/ehr/get_clinical_record.php?appointment_id=' + encodeURIComponent(appointmentId));
                const data = await r.json();
                if (!data || !data.record) return;
                const rec = data.record;

                document.querySelectorAll('input[type=checkbox][id^="srv-"]').forEach(cb => { cb.checked = false; });
                document.querySelectorAll('.treatment-lookup-checkbox').forEach(cb => { cb.checked = false; });

                if (rec.recommended_services && Array.isArray(rec.recommended_services)) {
                    rec.recommended_services.forEach(item => {
                        const sid = (item && typeof item === 'object') ? parseInt(item.service_id, 10) : parseInt(item, 10);
                        if (!Number.isInteger(sid) || sid <= 0) return;
                        const cb = document.getElementById('srv-' + sid);
                        if (cb) cb.checked = true;
                    });
                }

                if (rec.treatment_plan !== undefined && rec.treatment_plan !== null) {
                    const tp = rec.treatment_plan;
                    let notesValue = '';
                    let selectedTreatmentIds = [];

                    if (typeof tp === 'string') {
                        notesValue = tp;
                    } else if (Array.isArray(tp)) {
                        notesValue = JSON.stringify(tp, null, 2);
                        if (Array.isArray(tp._selected_treatment_ids)) {
                            selectedTreatmentIds = tp._selected_treatment_ids;
                        }
                    } else if (tp && typeof tp === 'object') {
                        if (Array.isArray(tp._selected_treatment_ids)) {
                            selectedTreatmentIds = tp._selected_treatment_ids;
                        } else if (Array.isArray(tp.selected_treatment_ids)) {
                            selectedTreatmentIds = tp.selected_treatment_ids;
                        }

                        if (typeof tp.notes === 'string') {
                            notesValue = tp.notes;
                        } else if (Array.isArray(tp.items)) {
                            notesValue = JSON.stringify(tp.items, null, 2);
                        } else {
                            const clone = Object.assign({}, tp);
                            delete clone._selected_treatment_ids;
                            delete clone.selected_treatment_ids;
                            if (Object.keys(clone).length > 0) {
                                notesValue = JSON.stringify(clone, null, 2);
                            }
                        }
                    }

                    document.getElementById('treatment_plan').value = notesValue;
                    selectedTreatmentIds
                        .map(v => parseInt(v, 10))
                        .filter(v => Number.isInteger(v) && v > 0)
                        .forEach(tid => {
                            const tcb = document.getElementById('trt-' + tid);
                            if (tcb) tcb.checked = true;
                        });
                } else {
                    document.getElementById('treatment_plan').value = '';
                }

                if (rec.materials_used && Array.isArray(rec.materials_used)) {
                    const wrap = document.getElementById('materialsList');
                    if (wrap) {
                        wrap.innerHTML = '';
                        rec.materials_used.forEach(m => addMaterialRow(m || {}));
                    }
                } else if (materialsList && materialsList.children.length === 0) {
                    addMaterialRow();
                }
                if (rec.doctor_notes) document.getElementById('doctor_notes').value = rec.doctor_notes;
                if (rec.follow_up_date) document.getElementById('follow_up_date').value = rec.follow_up_date;
                // prefill profiling if present
                try {
                    const pf = rec.profiling || rec.patient_profiling || null;
                    if (pf) {
                        const p = (typeof pf === 'string') ? JSON.parse(pf) : pf;
                        document.getElementById('pf_periodontal_ehr').value = p.periodontal || '';
                        document.getElementById('pf_dental_chart_ehr').value = p.dental_chart || '';
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

        // cleanup: removed extra controls; toggle button handles show/hide and reload
    })();
</script>