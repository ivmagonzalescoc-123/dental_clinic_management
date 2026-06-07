<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
check_session(['doctor']);

$doctor_id = $_SESSION['user_id'] ?? 0;

$records = [];
    $sql = "
    SELECT cr.record_id, cr.appointment_id, cr.status, cr.submitted_at, a.appointment_date, p.first_name, p.last_name,
    -- latest follow-up schedule summary for the appointment
    (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = cr.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_start,
    (SELECT s.status FROM patient_schedules s WHERE s.appointment_id = cr.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_status
    FROM clinical_records cr
    INNER JOIN appointments a ON cr.appointment_id = a.appointment_id
    INNER JOIN patients p ON cr.patient_id = p.patient_id
    WHERE cr.doctor_id = ? OR a.user_id = ?
    ORDER BY COALESCE(cr.submitted_at, a.appointment_date) DESC
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

<div class="card">
    <div class="card-body">
        <h4>Clinical Records</h4>
        <p class="text-muted">List of clinical records you created or are assigned to.</p>

        <div class="table-responsive">
            <table class="table table-hover datatable" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Record #</th>
                        <th>Appointment</th>
                        <th>Patient</th>
                        <th>Submitted At</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No clinical records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $rec): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($rec['record_id']); ?></td>
                                <td><?php echo htmlspecialchars($rec['appointment_date']); ?> (ID <?php echo (int)$rec['appointment_id']; ?>)</td>
                                <td>
                                    <?php if (!empty($rec['schedule_status'])): ?>
                                        <span class="badge bg-<?php echo ($rec['schedule_status'] === 'scheduled' ? 'primary' : ($rec['schedule_status'] === 'completed' ? 'success' : 'secondary')); ?>"><?php echo ($rec['schedule_status'] === 'scheduled') ? 'followup' : htmlspecialchars(ucfirst($rec['schedule_status'])); ?></span>
                                        <div class="small text-muted"><?php echo htmlspecialchars($rec['schedule_start'] ?? ''); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">No schedule</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($rec['submitted_at'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($rec['status'] ?? 'in_progress')); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="openHistoryByRecord(<?php echo (int)$rec['record_id']; ?>)">History</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
        <!-- History Modal (read-only) for Records page -->
        <div class="modal fade" id="historyModalRecords" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Clinical Record History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="max-height:75vh; overflow:auto;">
                        <div id="histContentRec">
                            <div class="mb-3"><strong>Patient:</strong> <span id="histPatientNameRec"></span></div>
                            <div class="mb-3"><strong>Submitted At:</strong> <span id="histSubmittedAtRec"></span></div>

                            <div class="mb-3" id="histBackgroundRecWrap">
                                <h6>Patient Background (Profiling)</h6>
                                <div id="histBackgroundRecContent" class="small"></div>
                            </div>

                            <div class="mb-3" id="histVisitClinicalRecWrap">
                                <h6>Visit Clinical Exam (This Appointment)</h6>
                                <div id="histVisitClinicalRecContent" class="small"></div>
                            </div>

                            <div class="mb-3"><strong>Clinical Notes:</strong>
                                <pre id="histNotesRec" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                            </div>
                            <div class="mb-3"><strong>Services Availed:</strong>
                                <ul id="histServicesRec"></ul>
                            </div>
                            <div class="mb-3" id="histTreatmentRecWrap" style="display:none;"><strong>Treatment Plan:</strong>
                                <pre id="histTreatmentRec" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                            </div>
                            <div class="mb-3" id="histMaterialsRecWrap" style="display:none;"><strong>Materials Used:</strong>
                                <pre id="histMaterialsRec" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                            </div>
                            <div class="mb-3"><strong>Images:</strong>
                                <div id="histImagesRec" class="row g-2"></div>
                            </div>

                            <div class="mb-3"><strong>Related Schedules</strong>
                                <div id="histSchedulesRec" class="list-group small"></div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-2 gap-2">
                                    <label class="form-label mb-0"><strong>Odontogram (read-only)</strong></label>
                                    <div class="d-flex gap-2">
                                        <button type="button" id="histOdontogramFullscreenBtnRec" class="btn btn-sm btn-outline-dark">Full Screen</button>
                                    </div>
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
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

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
        </style>

        <script>
        const doctorCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
        function notifyDoctorRecord(message, type) {
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), type || 'info');
                return;
            }
            alert(String(message || ''));
        }
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

        function isClinicalFieldEmptyRec(value) {
            if (value === null || value === undefined) return true;
            if (typeof value === 'string') {
                const trimmed = value.trim();
                return trimmed === '' || trimmed === '{}' || trimmed === '[]';
            }
            if (Array.isArray(value)) return value.length === 0;
            if (typeof value === 'object') return Object.keys(value).length === 0;
            return false;
        }

        function formatTreatmentPlanDisplayRec(plan) {
            if (isClinicalFieldEmptyRec(plan)) return '';
            if (typeof plan === 'string') return plan.trim();
            if (typeof plan === 'object' && plan !== null) {
                const months = plan.follow_up_interval_months;
                const visits = plan.follow_up_total_visits;
                const parts = [];
                if (months !== undefined && months !== null && String(months).trim() !== '' && Number(months) > 0) {
                    parts.push('Follow-up interval: every ' + months + ' month(s)');
                }
                if (visits !== undefined && visits !== null && String(visits).trim() !== '' && Number(visits) > 0) {
                    parts.push('Total follow-up visits: ' + visits);
                }
                if (parts.length) return parts.join('\n');
                return JSON.stringify(plan, null, 2);
            }
            return String(plan);
        }

        function formatMaterialsDisplayRec(materials) {
            if (isClinicalFieldEmptyRec(materials)) return 'No materials recorded.';
            if (typeof materials === 'string') return materials.trim() || 'No materials recorded.';
            if (Array.isArray(materials)) {
                if (!materials.length) return 'No materials recorded.';
                return materials.map(function(item, index) {
                    if (item && typeof item === 'object') {
                        const name = String(item.name || item.material || item.item || ('Material ' + (index + 1))).trim();
                        return '- ' + name;
                    }
                    return '- ' + String(item);
                }).join('\n');
            }
            if (typeof materials === 'object') {
                const keys = Object.keys(materials);
                if (!keys.length) return 'No materials recorded.';
                return keys.map(function(key) {
                    return key + ': ' + String(materials[key]);
                }).join('\n');
            }
            return String(materials);
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

        async function openHistoryByRecord(recordId) {
            const modalEl = document.getElementById('historyModalRecords');
            const bs = new bootstrap.Modal(modalEl);
            document.getElementById('histPatientNameRec').textContent = '';
            document.getElementById('histSubmittedAtRec').textContent = '';
            document.getElementById('histNotesRec').textContent = '';
            document.getElementById('histServicesRec').innerHTML = '';
            document.getElementById('histTreatmentRec').textContent = '';
            document.getElementById('histMaterialsRec').textContent = '';
            document.getElementById('histTreatmentRecWrap').style.display = 'none';
            document.getElementById('histMaterialsRecWrap').style.display = 'none';
            exitHistoryOdontogramFullscreenRec();

            try {
                const res = await fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?record_id=' + encodeURIComponent(recordId));
                const j = await res.json();
                if (!j.success) { notifyDoctorRecord('Could not load record', 'danger'); return; }
                const rec = j.record;
                const schedules = j.schedules || [];
                if (!rec) { notifyDoctorRecord('No record found', 'warning'); return; }

                document.getElementById('histPatientNameRec').textContent = rec._patient_name || 'Unknown patient';
                document.getElementById('histSubmittedAtRec').textContent = rec.submitted_at || rec._appointment_date || '';
                document.getElementById('histNotesRec').textContent = rec.doctor_notes || '';

                const backgroundEl = document.getElementById('histBackgroundRecContent');
                const visitClinicalEl = document.getElementById('histVisitClinicalRecContent');
                if (backgroundEl && typeof renderBackgroundProfilingFields === 'function') {
                    backgroundEl.innerHTML = renderBackgroundProfilingFields(rec.patient_profiling || null);
                }
                if (visitClinicalEl && typeof renderVisitClinicalFields === 'function') {
                    visitClinicalEl.innerHTML = renderVisitClinicalFields(rec.visit_clinical || rec.profiling || null);
                }

                populateServicesAvailedListRec(document.getElementById('histServicesRec'), j.services_availed || []);

                const treatmentWrapRec = document.getElementById('histTreatmentRecWrap');
                const materialsWrapRec = document.getElementById('histMaterialsRecWrap');
                const treatmentTextRec = formatTreatmentPlanDisplayRec(rec.treatment_plan);
                const materialsTextRec = formatMaterialsDisplayRec(rec.materials_used);
                const materialsEmptyRec = isClinicalFieldEmptyRec(rec.materials_used) || materialsTextRec === 'No materials recorded.';

                if (!isClinicalFieldEmptyRec(rec.treatment_plan) && treatmentTextRec !== '') {
                    document.getElementById('histTreatmentRec').textContent = treatmentTextRec;
                    treatmentWrapRec.style.display = '';
                } else {
                    document.getElementById('histTreatmentRec').textContent = '';
                    treatmentWrapRec.style.display = 'none';
                }

                if (!materialsEmptyRec) {
                    document.getElementById('histMaterialsRec').textContent = materialsTextRec;
                    materialsWrapRec.style.display = '';
                } else {
                    document.getElementById('histMaterialsRec').textContent = '';
                    materialsWrapRec.style.display = 'none';
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

                // schedules with expand/collapse and quick actions
                try {
                    const wrap = document.getElementById('histSchedulesRec');
                    wrap.innerHTML = '';
                    if (Array.isArray(schedules) && schedules.length) {
                        schedules.forEach(s => {
                            const id = s.schedule_id || Math.random().toString(36).substr(2,6);
                            const start = s.scheduled_start ? s.scheduled_start : '(no start)';
                            const end = s.scheduled_end ? (' — ' + s.scheduled_end) : '';
                            const item = document.createElement('div');
                            item.className = 'list-group-item';

                            const header = document.createElement('div');
                            header.className = 'd-flex w-100 justify-content-between align-items-center';
                            header.innerHTML = `<div><strong>${(s.status||'').toUpperCase()}</strong> <span class="text-muted">${start}${end}</span></div>`;

                            const toggleBtn = document.createElement('a');
                            toggleBtn.setAttribute('data-bs-toggle','collapse');
                            toggleBtn.href = '#sch-details-rec-' + id;
                            toggleBtn.className = 'btn btn-sm btn-outline-secondary';
                            toggleBtn.textContent = 'Details';
                            header.appendChild(toggleBtn);
                            item.appendChild(header);

                            const details = document.createElement('div');
                            details.className = 'collapse mt-2';
                            details.id = 'sch-details-rec-' + id;
                            let inner = `<div class="small text-muted">Created: ${s.created_at || 'N/A'}</div>`;
                            if (s.priority) inner += `<div class="small text-muted">Priority: ${s.priority}</div>`;
                            inner += `<div class="pt-2">Notes: ${s.notes || ''}</div>`;
                            inner += '<div class="mt-2">';
                            // actions
                            inner += `<button class="btn btn-sm btn-danger me-1" data-action="cancel" data-sid="${s.schedule_id}">Cancel</button>`;
                            if (<?php echo json_encode($_SESSION['role'] ?? ''); ?> === 'secretary' || <?php echo json_encode($_SESSION['role'] ?? ''); ?> === 'admin') {
                                inner += `<button class="btn btn-sm btn-success me-1" data-action="arrive" data-sid="${s.schedule_id}">Mark Arrived</button>`;
                            }
                            inner += `<button class="btn btn-sm btn-warning me-1" data-action="reschedule" data-sid="${s.schedule_id}">Reschedule</button>`;
                            inner += '</div>';
                            details.innerHTML = inner;
                            item.appendChild(details);

                            item.addEventListener('click', async function(e){
                                const btn = e.target.closest('button[data-action]');
                                if (!btn) return;
                                const act = btn.getAttribute('data-action');
                                const sid = btn.getAttribute('data-sid');
                                if (act === 'arrive') {
                                    btn.disabled = true;
                                    try {
                                        const fd = new FormData(); fd.append('schedule_id', sid);
                                        const r = await fetch('../assets/api/appointments/mark_schedule_arrived.php', { method: 'POST', body: fd });
                                        const j = await r.json();
                                        if (j.success) { btn.textContent = 'Arrived'; btn.classList.remove('btn-success'); btn.classList.add('btn-secondary'); }
                                        else notifyDoctorRecord(j.message || 'Failed', 'danger');
                                    } catch(e){ notifyDoctorRecord('Error', 'danger'); }
                                    btn.disabled = false;
                                } else if (act === 'cancel') {
                                    if (!confirm('Cancel this schedule?')) return;
                                    btn.disabled = true;
                                    try {
                                        const fd = new FormData(); fd.append('schedule_id', sid); fd.append('csrf_token', doctorCsrfToken);
                                        const r = await fetch('../assets/api/appointments/cancel_schedule.php', { method: 'POST', body: fd });
                                        const j = await r.json();
                                        if (j.success) { header.querySelector('strong').textContent = 'CANCELLED'; }
                                        else notifyDoctorRecord(j.message || 'Failed', 'danger');
                                    } catch(e){ notifyDoctorRecord('Error', 'danger'); }
                                    btn.disabled = false;
                                } else if (act === 'reschedule') {
                                    openRescheduleModal(s);
                                }
                            });

                            wrap.appendChild(item);
                        });
                    } else {
                        wrap.innerHTML = '<div class="text-muted small">No schedules found for this record.</div>';
                    }
                } catch (e) { console.warn('render schedules', e); }

                bs.show();
            } catch (e) { console.error(e); notifyDoctorRecord('Error loading record', 'danger'); }
        }
        </script>

        <!-- Reschedule Modal (records) -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <div class="modal fade" id="rescheduleModalRec" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Reschedule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="rescheduleScheduleIdRec" />
                        <div class="mb-2"><label class="form-label">Start</label><input id="rescheduleStartRec" class="form-control" type="datetime-local"/></div>
                        <div class="mb-2"><label class="form-label">End (optional)</label><input id="rescheduleEndRec" class="form-control" type="datetime-local"/></div>
                        <div class="mb-2"><button id="rescheduleSuggestRec" class="btn btn-sm btn-outline-primary">Suggest slots</button></div>
                        <div id="rescheduleSuggestionsRec" class="list-group"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button id="rescheduleSaveBtnRec" type="button" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        const resModalRec = new bootstrap.Modal(document.getElementById('rescheduleModalRec'));
        let fpStartRec = null, fpEndRec = null;
        function initRecPickers() {
            try {
                if (typeof flatpickr !== 'undefined') {
                    fpStartRec = flatpickr('#rescheduleStartRec', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
                    fpEndRec = flatpickr('#rescheduleEndRec', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
                }
            } catch (e) { console.warn('flatpickr rec init', e); }
        }
        initRecPickers();
        function toLocalInputRec(dtStr) { if (!dtStr) return ''; return dtStr.replace(' ', 'T').replace(/:00$/,''); }
        function toServerFormatRec(localVal) { if (!localVal) return ''; return localVal.replace('T',' '); }
        function openRescheduleModal(schedule) {
            document.getElementById('rescheduleScheduleIdRec').value = schedule.schedule_id || '';
            const startLocal = toLocalInputRec(schedule.scheduled_start || '');
            const endLocal = toLocalInputRec(schedule.scheduled_end || '');
            if (fpStartRec && typeof fpStartRec.setDate === 'function') fpStartRec.setDate(startLocal);
            else document.getElementById('rescheduleStartRec').value = startLocal;
            if (fpEndRec && typeof fpEndRec.setDate === 'function') fpEndRec.setDate(endLocal);
            else document.getElementById('rescheduleEndRec').value = endLocal;
            document.getElementById('rescheduleSuggestionsRec').innerHTML = '';
            resModalRec.show();
        }
        document.getElementById('rescheduleSuggestRec').addEventListener('click', async function(){
            const sid = document.getElementById('rescheduleScheduleIdRec').value;
            if (!sid) return notifyDoctorRecord('No schedule selected', 'warning');
            const btn = this; btn.disabled = true;
            try {
                const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
                const gs = await g.json();
                if (!gs.success) return notifyDoctorRecord(gs.message || 'Could not resolve schedule', 'danger');
                const user_id = gs.schedule.user_id || CURRENT_USER;
                const duration = gs.schedule.duration || 30;
                const fd = new FormData(); fd.append('user_id', user_id); fd.append('days_ahead', 14); fd.append('duration', duration);
                const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
                const j = await res.json();
                const wrap = document.getElementById('rescheduleSuggestionsRec'); wrap.innerHTML = '';
                if (!j.success) return notifyDoctorRecord(j.message || 'No suggestions', 'warning');
                (j.slots || []).slice(0,10).forEach(slot => {
                    const a = document.createElement('a'); a.href = '#'; a.className = 'list-group-item list-group-item-action';
                    a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : '');
                    a.addEventListener('click', function(e){
                        e.preventDefault();
                        const startLocal = slot.start.replace(' ', 'T').replace(/:00$/,'');
                        const endLocal = slot.end ? slot.end.replace(' ', 'T').replace(/:00$/,'') : '';
                        if (fpStartRec && typeof fpStartRec.setDate === 'function') fpStartRec.setDate(startLocal);
                        else document.getElementById('rescheduleStartRec').value = startLocal;
                        if (endLocal) {
                          if (fpEndRec && typeof fpEndRec.setDate === 'function') fpEndRec.setDate(endLocal);
                          else document.getElementById('rescheduleEndRec').value = endLocal;
                        }
                    });
                    wrap.appendChild(a);
                });
            } catch(e){ console.error(e); notifyDoctorRecord('Error fetching suggestions', 'danger'); }
            btn.disabled = false;
        });
        document.getElementById('rescheduleSaveBtnRec').addEventListener('click', async function(){
            const sid = document.getElementById('rescheduleScheduleIdRec').value; const start = toServerFormatRec(document.getElementById('rescheduleStartRec').value); const end = toServerFormatRec(document.getElementById('rescheduleEndRec').value);
            if (!sid || !start) return notifyDoctorRecord('Start datetime required', 'warning'); this.disabled = true; try { const fd = new FormData(); fd.append('schedule_id', sid); fd.append('scheduled_start', start); fd.append('scheduled_end', end); fd.append('csrf_token', doctorCsrfToken); const r = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd }); const j = await r.json(); if (!j.success) return notifyDoctorRecord(j.message || 'Failed to reschedule', 'danger'); notifyDoctorRecord('Rescheduled', 'success'); resModalRec.hide(); location.reload(); } catch(e){ console.error(e); notifyDoctorRecord('Error', 'danger'); } this.disabled = false; });
        </script>
