<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
check_session(['doctor']);

$doctor_id = $_SESSION['user_id'] ?? 0;

// One row per patient — a patient's clinical records used to list once per appointment,
// forcing staff to hunt for the right visit. The History modal now shows every visit for
// the patient (see openPatientHistory() below), so the list itself only needs to say who
// has records and when they were last seen.
$records = [];
    $sql = "
    SELECT p.patient_id, p.first_name, p.last_name,
    COUNT(cr.record_id) AS record_count,
    MAX(COALESCE(cr.submitted_at, a.appointment_date)) AS last_visit
    FROM clinical_records cr
    INNER JOIN appointments a ON cr.appointment_id = a.appointment_id
    INNER JOIN patients p ON cr.patient_id = p.patient_id
    WHERE cr.doctor_id = ? OR a.user_id = ?
    GROUP BY p.patient_id, p.first_name, p.last_name
    ORDER BY last_visit DESC
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ii', $doctor_id, $doctor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $records[] = $r;
    $stmt->close();
}
?>

<h4>Clinical Records</h4>

<div class="unified-table-search-toolbar mb-3">
    <div class="unified-table-search-wrap">
        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
        <input type="search" id="doctorRecordsSearch" class="form-control unified-table-search-input"
            placeholder="Search records by patient, appointment, or status..." aria-label="Search clinical records" autocomplete="off">
        <button type="button" class="unified-table-search-clear" id="doctorRecordsSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
    </div>
</div>

        <div class="table-responsive">
            <table class="table table-hover datatable" id="doctorRecordsTable" data-skip-datatable="1">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Total Visits</th>
                        <th>Last Visit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No clinical records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $rec): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?></td>
                                <td><?php echo (int)$rec['record_count']; ?></td>
                                <td><?php echo htmlspecialchars($rec['last_visit'] ?? '-'); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="openPatientHistory(<?php echo (int)$rec['patient_id']; ?>)">History</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- History Modal (read-only) for Records page -->
        <div class="modal fade" id="historyModalRecords" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Clinical Record History</h5>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="histPrintBtnRec">
                                <i class="bi bi-printer"></i> Print
                            </button>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body" style="max-height:75vh; overflow:auto;">
                        <!-- Visit sub-nav: one pill per appointment date, since a patient's full
                             history now lives behind a single History button instead of one row
                             per appointment. -->
                        <div id="histVisitTabsRec" class="d-flex flex-wrap gap-1 mb-3"></div>
                        <div id="histContentRec">
                            <!-- Patient Header: Avatar + Name + Details -->
                            <div class="d-flex align-items-center gap-3 mb-3 p-2 hist-patient-header">
                                <div id="histPatientAvatarRec" style="flex-shrink:0;"></div>
                                <div class="min-w-0">
                                    <div id="histPatientNameRec" style="font-size:1rem;font-weight:700;color:#1e293b;"></div>
                                    <div id="histPatientDetailsRec" style="font-size:0.82rem;color:#64748b;"></div>
                                </div>
                            </div>

                            <!-- Row 1: Submitted At -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-12">
                                    <div class="hist-field"><span class="hist-label">Submitted At</span><span id="histSubmittedAtRec" class="hist-value"></span></div>
                                </div>
                            </div>
                            <!-- Row 2: Background -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-12" id="histBackgroundRecWrap">
                                    <div class="hist-section">
                                        <span class="hist-section-title">Patient Background (Profiling)</span>
                                        <div id="histBackgroundRecContent" class="small"></div>
                                    </div>
                                </div>
                            <!-- Row 3: Clinical Notes (full width) -->
                            <div class="mb-2">
                                <div class="hist-section">
                                    <span class="hist-section-title">Clinical Notes</span>
                                    <pre id="histNotesRec" class="hist-pre"></pre>
                                </div>
                            </div>
                            <!-- Row 3b: Clinical Findings (Occlusal Classification, etc.) -->
                            <div class="mb-2">
                                <div class="hist-section">
                                    <span class="hist-section-title">Clinical Findings</span>
                                    <div id="histFindingsRec" class="small"></div>
                                </div>
                            </div>
                            <!-- Row 4: Services Availed -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-12">
                                    <div class="hist-section">
                                        <span class="hist-section-title">Services Availed</span>
                                        <ul id="histServicesRec" class="mb-0 small"></ul>
                                    </div>
                                </div>
                            <!-- Row 4b: Prescriptions (full width) -->
                            <div class="mb-2" id="histPrescriptionsRecWrap" style="display:none;">
                                <div class="hist-section">
                                    <span class="hist-section-title">Prescriptions</span>
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0" id="histPrescriptionsRec">
                                            <thead><tr><th>Medicine</th><th>Brand</th><th>Dosage</th><th>Duration</th><th>Notes</th></tr></thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <!-- Row 5: Images (full width) -->
                            <div class="mb-2">
                                <div class="hist-section">
                                    <span class="hist-section-title">Images</span>
                                    <div id="histImagesRec" class="row g-2"></div>
                                </div>
                            </div>
                            <!-- Row 6: Odontogram (full width) -->
                            <div class="mb-2">
                                <div class="hist-section">
                                    <div class="d-flex align-items-center justify-content-between mb-2 gap-2">
                                        <span class="hist-section-title mb-0">Odontogram (read-only)</span>
                                        <button type="button" id="histOdontogramFullscreenBtnRec" class="btn btn-sm btn-outline-dark">Full Screen</button>
                                    </div>
                                    <div id="histOdontogramViewportRec" class="odontogram-viewport">
                                        <button type="button" id="histOdontogramFullscreenCloseBtnRec" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;">
                                            <i class="bi bi-x-lg"></i> Close
                                        </button>
                                        <div id="histOdontogramWrapRec" style="border:1px solid #ddd; height:<?php echo (int) odontogram_embed_height_px(); ?>px;">
                                            <iframe id="histOdontoFrameRec" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;height:100%;border:0;"></iframe>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
        /* Compact history modal */
        .hist-field {
            background: #f8fafc;
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 4px;
        }
        .hist-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 1px;
        }
        .hist-value {
            display: block;
            font-size: 0.85rem;
            color: #1e293b;
            font-weight: 500;
        }
        .hist-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            height: 100%;
        }
        .hist-section-title {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .hist-pre {
            white-space: pre-wrap;
            background: #fff;
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            border-radius: 4px;
            font-size: 0.82rem;
            margin-bottom: 0;
            max-height: 120px;
            overflow-y: auto;
        }
        .odontogram-viewport {
            position: relative;
        }
        .odontogram-fs-close {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 30;
        }
        .hist-patient-header {
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        /* ---- Dark mode ---- */
        [data-theme="dark"] .hist-field,
        [data-theme="dark"] .hist-section,
        [data-theme="dark"] .hist-patient-header {
            background: #16223a;
            border-color: #2f3d52;
        }
        [data-theme="dark"] .hist-label {
            color: #94a3b8;
        }
        [data-theme="dark"] .hist-value {
            color: #e2e8f0;
        }
        [data-theme="dark"] .hist-section-title {
            color: #cbd5e1;
        }
        [data-theme="dark"] .hist-pre {
            background: #1c2c47;
            border-color: #2f3d52;
            color: #e2e8f0;
        }
        [data-theme="dark"] #histPatientNameRec {
            color: #e2e8f0 !important;
        }
        [data-theme="dark"] #histPatientDetailsRec {
            color: #94a3b8 !important;
        }
        </style>

        <script>
        function notifyDoctorRecord(message, type) {
            window.showToast(String(message || ''), type || 'info');
        }

        // Clinical records table search — the table opts out of automatic simpleDatatables
        // init (data-skip-datatable) so we can wire the search box straight to the
        // DataTable's own .search() API with a debounce, instead of depending on
        // main.js's generic native-input debounce hook finding the right element.
        (function () {
            const tableEl = document.getElementById('doctorRecordsTable');
            const searchInput = document.getElementById('doctorRecordsSearch');
            const clearBtn = document.getElementById('doctorRecordsSearchClear');
            if (!tableEl || typeof simpleDatatables === 'undefined') return;

            const recordsDataTable = new simpleDatatables.DataTable(tableEl, {
                perPageSelect: [5, 10, 15, ["All", -1]],
                labels: { placeholder: 'Search records...' }
            });

            let searchTimer = null;
            function runSearch() {
                const q = searchInput.value.trim();
                clearBtn.style.display = q ? '' : 'none';
                recordsDataTable.search(q);
            }

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(runSearch, 300);
                });
            }
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    clearTimeout(searchTimer);
                    searchInput.value = '';
                    runSearch();
                    searchInput.focus();
                });
            }
        })();
        // Service map lookup (load names)
        const serviceMapRec = {
            <?php
                $sv = $conn->query('SELECT service_id, service_name FROM services WHERE is_active=1');
                if ($sv) {
                    while ($s = $sv->fetch_assoc()) {
                        echo '"' . (int)$s['service_id'] . '": "' . htmlspecialchars(addslashes($s['service_name'])) . '",' . "\n";
                    }
                }
            ?>
        };

        // Mirrors odontogram/chart-constants.js CHART_LEGEND — the print window can't load
        // the odontogram iframe (it's a live editor app, not a static image, and has no
        // tooth data of its own once opened in a fresh window), so we render the findings
        // as a plain table from the same JSON the iframe would have been fed via postMessage.
        var ODONTOGRAM_LEGEND_REC = {
            C: 'Dental Caries', C1: 'Caries — Vital Pulp Expose', C2: 'Caries — Non-vital Pulp Expose',
            X: 'Indicated for Extraction', RF: 'Retained Root Fragment', Am: 'Amalgam Filling',
            S: 'Silicate Filling', GC: 'Gold Crown', Ab1: 'Bridge Abutment', P: 'Pontic',
            GCI: 'Gold Clasp', GI: 'Gold Inlays', M: 'Missing (Extraction)', Un: 'Unerupted'
        };
        var ODONTOGRAM_SURFACE_LABELS_REC = { O: 'Occlusal', M: 'Mesial', D: 'Distal', B: 'Buccal', L: 'Lingual', root: 'Root', whole: 'Whole Tooth' };

        function formatOdontogramFindingsHtmlRec(odontogramData) {
            if (!odontogramData || typeof odontogramData !== 'object') return '';
            var teeth = Object.keys(odontogramData).sort();
            var rows = [];
            teeth.forEach(function (fdi) {
                var entry = odontogramData[fdi];
                if (!entry || typeof entry !== 'object') return;
                var parts = [];
                if (entry.surfaces && typeof entry.surfaces === 'object') {
                    Object.keys(entry.surfaces).forEach(function (surface) {
                        var labels = entry.surfaces[surface];
                        if (!Array.isArray(labels) || !labels.length) return;
                        var names = labels.map(function (code) { return ODONTOGRAM_LEGEND_REC[code] || code; }).join(', ');
                        parts.push((ODONTOGRAM_SURFACE_LABELS_REC[surface] || surface) + ': ' + names);
                    });
                }
                var comment = String(entry.comment || '').trim();
                if (!parts.length && !comment) return;
                rows.push('<tr><td>#' + escapeHtml(fdi) + '</td><td>' + escapeHtml(parts.join('; ')) + '</td><td>' + escapeHtml(comment) + '</td></tr>');
            });
            if (!rows.length) return '<div class="text-muted small">No findings recorded on the dental chart.</div>';
            return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tooth</th><th>Findings</th><th>Notes</th></tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = String(str || '');
            return div.innerHTML;
        }

        // Occlusal Classification first (what's actually used day-to-day); the rest render
        // N/A when blank, mirroring the Create Record tab order.
        var CLINICAL_FINDINGS_LABELS_REC = [
            ['occlusion', 'Occlusion'], ['cl_i_type', 'Cl. I Type'], ['cl_ii_division', 'Cl. II Division'],
            ['cl_ii_subdivision', 'Cl. II SubDivision'], ['cl_ii_type', 'Cl. II Type'],
            ['cl_iii_division', 'Cl. III Division'], ['cl_iii_subdivision', 'Cl. III SubDivision'],
            ['operation_if_any', 'Operation if any'],
            ['gingiva_consistency', 'Consistency Gingiva'], ['oral_hygiene', 'Oral Hygiene'],
            ['gingival_color', 'Gingival Color'], ['lymph_nodes', 'Lymph Nodes'], ['tongue', 'Tongue'],
            ['blood_sugar', 'Blood Sugar'], ['blood_pressure', 'B.P.'], ['bleeding_time', 'Bleeding Time'],
            ['clotting_time', 'Clotting Time'], ['fainting', 'Fainting'], ['allergy_notes', 'Allergy'],
            ['blood_diseases', 'Blood Diseases'], ['radiographic_interpretation', 'Radiographic Interpretation'],
        ];

        function renderClinicalFindingsCompactRec(findings) {
            findings = findings || {};
            var rows = CLINICAL_FINDINGS_LABELS_REC.map(function (f) {
                var raw = findings[f[0]];
                var val = (raw === true) ? 'Yes' : (raw === null || raw === undefined || String(raw).trim() === '') ? 'N/A' : escapeHtml(String(raw));
                return '<div class="col-6 col-md-4 mb-1"><span class="text-muted">' + escapeHtml(f[1]) + ':</span> ' + val + '</div>';
            }).join('');
            return '<div class="row g-0">' + rows + '</div>';
        }

        function populateServicesAvailedListRec(listEl, services) {
            if (!listEl) return;
            listEl.innerHTML = '';
            if (!Array.isArray(services) || !services.length) {
                const li = document.createElement('li');
                li.className = 'text-muted';
                li.textContent = 'No services recorded for this appointment.';
                listEl.appendChild(li);
                return;
            }
            services.forEach(function(item) {
                const li = document.createElement('li');
                let label = '';
                if (item && typeof item === 'object') {
                    label = String(item.description || item.service_name || '').trim();
                    if (!label && item.service_id && (item.service_id in serviceMapRec)) {
                        label = serviceMapRec[item.service_id];
                    }
                    if (!label && item.service_id) {
                        label = 'Service';
                    }
                    const amount = item.amount !== undefined && item.amount !== null ? Number(item.amount) : null;
                    if (amount !== null && !Number.isNaN(amount) && amount > 0) {
                        label += ' (PHP ' + amount.toFixed(2) + ')';
                    }
                } else {
                    const sid = parseInt(item, 10);
                    label = (sid in serviceMapRec) ? serviceMapRec[sid] : 'Service';
                }
                li.textContent = label;
                listEl.appendChild(li);
            });
        }

        function postHistoryOdontogramRec(odontogramData) {
            const odf = document.getElementById('histOdontoFrameRec');
            if (!odf) return;
            const payload = { type: 'load_odontogram', toothState: odontogramData || {}, readonly: true };
            const send = function() {
                try {
                    if (odf.contentWindow) {
                        odf.contentWindow.postMessage(payload, '*');
                    }
                } catch (e) {}
            };
            if (odf.dataset.historyLoaded === '1') {
                send();
                return;
            }
            odf.onload = function() {
                odf.dataset.historyLoaded = '1';
                send();
            };
            if (odf.contentDocument && odf.contentDocument.readyState === 'complete') {
                odf.dataset.historyLoaded = '1';
                send();
            }
        }

        function getHistoryOdontogramFullscreenElementRec() {
            return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
        }

        function isHistoryOdontogramFullscreenRec() {
            const viewport = document.getElementById('histOdontogramViewportRec');
            return !!viewport && getHistoryOdontogramFullscreenElementRec() === viewport;
        }

        function requestHistoryOdontogramFullscreenRec() {
            const viewport = document.getElementById('histOdontogramViewportRec');
            if (!viewport) return;
            const requestFn = viewport.requestFullscreen || viewport.webkitRequestFullscreen || viewport.msRequestFullscreen;
            if (requestFn) requestFn.call(viewport);
        }

        function exitHistoryOdontogramFullscreenRec() {
            const exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
            if (exitFn) exitFn.call(document);
        }

        function syncHistoryOdontogramFullscreenUIRec() {
            const closeBtn = document.getElementById('histOdontogramFullscreenCloseBtnRec');
            const openBtn = document.getElementById('histOdontogramFullscreenBtnRec');
            const active = isHistoryOdontogramFullscreenRec();
            if (closeBtn) closeBtn.style.display = active ? '' : 'none';
            if (openBtn) openBtn.textContent = active ? 'Exit Full Screen' : 'Full Screen';
        }

        document.getElementById('histOdontogramFullscreenBtnRec')?.addEventListener('click', function() {
            if (isHistoryOdontogramFullscreenRec()) {
                exitHistoryOdontogramFullscreenRec();
                return;
            }
            requestHistoryOdontogramFullscreenRec();
        });
        document.getElementById('histOdontogramFullscreenCloseBtnRec')?.addEventListener('click', exitHistoryOdontogramFullscreenRec);
        document.addEventListener('fullscreenchange', syncHistoryOdontogramFullscreenUIRec);
        document.addEventListener('webkitfullscreenchange', syncHistoryOdontogramFullscreenUIRec);
        document.getElementById('historyModalRecords')?.addEventListener('hidden.bs.modal', exitHistoryOdontogramFullscreenRec);
        syncHistoryOdontogramFullscreenUIRec();

        window.currentHistoryRecordDataRec = null;
        window.currentHistoryPatientIdRec = null;

        // Opens the modal for a patient and loads their most recent visit. Called from the
        // one-row-per-patient table's History button.
        async function openPatientHistory(patientId) {
            currentHistoryPatientIdRec = patientId;
            const modalEl = document.getElementById('historyModalRecords');
            const bs = new bootstrap.Modal(modalEl);
            const tabsEl = document.getElementById('histVisitTabsRec');
            if (tabsEl) tabsEl.innerHTML = '<span class="text-muted small">Loading visits...</span>';
            bs.show();

            try {
                const res = await fetch('../assets/api/ehr/get_patient_record_summaries.php?patient_id=' + encodeURIComponent(patientId));
                const j = await res.json();
                if (!j.success || !Array.isArray(j.visits) || !j.visits.length) {
                    if (tabsEl) tabsEl.innerHTML = '';
                    notifyDoctorRecord('No visit history found for this patient', 'warning');
                    return;
                }
                renderHistoryVisitTabsRec(j.visits, j.visits[0].record_id);
                await loadHistoryRecordContent(j.visits[0].record_id);
            } catch (e) {
                console.error(e);
                if (tabsEl) tabsEl.innerHTML = '';
                notifyDoctorRecord('Unable to load patient visit history', 'danger');
            }
        }

        function renderHistoryVisitTabsRec(visits, activeRecordId) {
            const tabsEl = document.getElementById('histVisitTabsRec');
            if (!tabsEl) return;
            tabsEl.innerHTML = '';
            visits.forEach(function (v) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm ' + (v.record_id === activeRecordId ? 'btn-primary' : 'btn-outline-secondary');
                const dateLabel = (v.appointment_date || v.submitted_at || '').toString().split(' ')[0] || ('Record #' + v.record_id);
                btn.textContent = dateLabel + (v.doctor_name ? ' — Dr. ' + v.doctor_name : '');
                btn.addEventListener('click', function () {
                    renderHistoryVisitTabsRec(visits, v.record_id);
                    loadHistoryRecordContent(v.record_id);
                });
                tabsEl.appendChild(btn);
            });
        }

        async function loadHistoryRecordContent(recordId) {
            currentHistoryRecordDataRec = null;
            document.getElementById('histPatientNameRec').textContent = '';
            document.getElementById('histSubmittedAtRec').textContent = '';
            document.getElementById('histNotesRec').textContent = '';
            document.getElementById('histServicesRec').innerHTML = '';
            exitHistoryOdontogramFullscreenRec();

            try {
                const res = await fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?record_id=' + encodeURIComponent(recordId));
                const j = await res.json();
                if (!j.success) { notifyDoctorRecord('Could not load record', 'danger'); return; }
                const rec = j.record;
                if (!rec) { notifyDoctorRecord('No record found', 'warning'); return; }

                document.getElementById('histPatientNameRec').textContent = rec._patient_name || 'Unknown patient';
                document.getElementById('histSubmittedAtRec').textContent = rec.submitted_at || rec._appointment_date || '';

                // Render patient avatar and details
                const patientInfo = j.patient_info || {};
                const avatarRecEl = document.getElementById('histPatientAvatarRec');
                if (avatarRecEl) {
                    const imgPath = String(patientInfo.profile_image || '').trim();
                    if (imgPath !== '') {
                        const imgUrl = /^https?:\/\//i.test(imgPath) ? imgPath : '../' + imgPath.replace(/^\/+/, '');
                        avatarRecEl.innerHTML = '<img src="' + escapeHtml(imgUrl) + '" alt="" style="width:46px;height:46px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;">';
                    } else {
                        avatarRecEl.innerHTML = '<div style="width:46px;height:46px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;"><i class="bi bi-person-fill" style="font-size:1.2rem;color:#94a3b8;"></i></div>';
                    }
                }
                const detailsRecEl = document.getElementById('histPatientDetailsRec');
                if (detailsRecEl) {
                    const parts = [patientInfo.email, patientInfo.phone, patientInfo.address].filter(Boolean);
                    detailsRecEl.textContent = parts.join(' | ') || '';
                }
                document.getElementById('histNotesRec').textContent = rec.doctor_notes || '';

                const findingsEl = document.getElementById('histFindingsRec');
                if (findingsEl) findingsEl.innerHTML = renderClinicalFindingsCompactRec(rec.clinical_findings || {});

                const backgroundEl = document.getElementById('histBackgroundRecContent');
                if (backgroundEl && typeof renderBackgroundProfilingFields === 'function') {
                    backgroundEl.innerHTML = renderBackgroundProfilingFields(rec.patient_profiling || null, patientInfo.gender);
                }

                populateServicesAvailedListRec(document.getElementById('histServicesRec'), j.services_availed || []);

                const presWrapRec = document.getElementById('histPrescriptionsRecWrap');
                const presBodyRec = document.querySelector('#histPrescriptionsRec tbody');
                if (presBodyRec) presBodyRec.innerHTML = '';
                if (Array.isArray(j.prescriptions) && j.prescriptions.length > 0) {
                    j.prescriptions.forEach(function (p) {
                        const tr = document.createElement('tr');
                        [p.medicine_name, p.medicine_brand, p.dosage, p.duration, p.notes].forEach(function (val) {
                            const td = document.createElement('td');
                            td.textContent = val || '';
                            tr.appendChild(td);
                        });
                        if (presBodyRec) presBodyRec.appendChild(tr);
                    });
                    if (presWrapRec) presWrapRec.style.display = '';
                } else if (presWrapRec) {
                    presWrapRec.style.display = 'none';
                }

                // images
                const imgWrapRec = document.getElementById('histImagesRec');
                if (typeof renderMediaGallery === 'function') {
                    renderMediaGallery(imgWrapRec, (rec.images || []).map(function (src, idx) {
                        return { file_path: src, file_name: 'appointment-photo-' + (idx + 1) + '.jpg' };
                    }), function (item) {
                        return typeof resolveMediaPath === 'function'
                            ? resolveMediaPath(item.file_path, '../')
                            : item.file_path;
                    });
                } else if (imgWrapRec) {
                    imgWrapRec.innerHTML = '';
                    if (Array.isArray(rec.images) && rec.images.length) {
                        rec.images.forEach(src => {
                            const a = document.createElement('a');
                            a.href = src;
                            a.target = '_blank';
                            const img = document.createElement('img');
                            img.src = src;
                            img.style.maxWidth = '150px';
                            img.style.maxHeight = '120px';
                            img.className = 'img-thumbnail';
                            a.appendChild(img);
                            imgWrapRec.appendChild(a);
                        });
                    }
                }

                postHistoryOdontogramRec(rec.odontogram || null);

                currentHistoryRecordDataRec = rec;
            } catch (e) { console.error(e); notifyDoctorRecord('Error loading record', 'danger'); }
        }

        // Print the currently open record. The odontogram is a live editor app running in
        // an iframe — a fresh print window can't load its tooth-state data into a brand new
        // iframe, so we print the findings as a plain table instead (same approach used on
        // the patient portal's clinic records print).
        document.getElementById('histPrintBtnRec')?.addEventListener('click', function () {
            if (!currentHistoryRecordDataRec) {
                notifyDoctorRecord('Load a record before printing.', 'warning');
                return;
            }
            const printWindow = window.open('', '_blank', 'width=900,height=700');
            if (!printWindow) return;

            const contentEl = document.getElementById('histContentRec');
            const printBody = document.createElement('div');
            printBody.innerHTML = contentEl.innerHTML;

            const odontoWrapEl = printBody.querySelector('#histOdontogramWrapRec');
            if (odontoWrapEl) {
                odontoWrapEl.outerHTML = formatOdontogramFindingsHtmlRec(currentHistoryRecordDataRec.odontogram || null);
            }
            printBody.querySelector('#histOdontogramFullscreenBtnRec')?.remove();
            printBody.querySelector('#histOdontogramFullscreenCloseBtnRec')?.remove();

            const title = 'Clinical Record — ' + (document.getElementById('histPatientNameRec').textContent || 'Patient');

            printWindow.document.write(
                '<!doctype html><html><head><title>' + escapeHtml(title) + '</title>' +
                '<style>' +
                'body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#212529;}' +
                'h1{font-size:18px;margin:0 0 4px;}' +
                '.hist-patient-header,.hist-field,.hist-section{border:1px solid #dee2e6;border-radius:8px;padding:10px;margin-bottom:12px;background:#fff;}' +
                '.hist-section-title,.hist-label{display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:4px;}' +
                '.hist-value{font-size:14px;} .hist-pre{white-space:pre-wrap;font-size:13px;margin:0;}' +
                'table{width:100%;border-collapse:collapse;} th,td{border:1px solid #dee2e6;padding:6px;text-align:left;font-size:13px;}' +
                'ul{margin:0;padding-left:18px;}' +
                '.row,.g-2{display:block;} .col-md-6,.col-md-4,.col-md-12{display:block;width:100%;}' +
                '.btn,button{display:none !important;}' +
                'img{max-width:150px;max-height:120px;}' +
                '</style></head><body>' +
                '<h1>' + escapeHtml(title) + '</h1>' +
                printBody.innerHTML +
                '</body></html>'
            );
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function () { printWindow.print(); }, 300);
        });
        </script>
