<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';

$patient_id = (int)($_SESSION['patient_id'] ?? 0);
$records = [];

if ($patient_id > 0) {
    $sql = "SELECT
                a.appointment_id,
                a.appointment_date,
                a.reason,
                a.status AS appointment_status,
                a.created_at,
                u.first_name AS doctor_first_name,
                u.last_name AS doctor_last_name,
                cr.record_id,
                cr.follow_up_date,
                cr.status AS record_status,
                cr.submitted_at
            FROM appointments a
            LEFT JOIN users u ON u.user_id = a.user_id
            LEFT JOIN clinical_records cr ON cr.appointment_id = a.appointment_id
                        WHERE a.patient_id = ?
                            AND cr.record_id IS NOT NULL
            ORDER BY a.appointment_date DESC, a.appointment_id DESC
                        LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
    }
}
?>

<div class="card border-0">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="card-title mb-0">Clinic Records</h5>
        </div>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2"><?php echo count($records); ?> record<?php echo count($records) === 1 ? '' : 's'; ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($records)): ?>
            <div class="text-center text-muted py-4">No completed clinic records yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Appointment #</th>
                            <th>Date</th>
                            <th>Doctor</th>
                            <th>Record Status</th>
                            <th>Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $doctorName = trim((string)($record['doctor_first_name'] ?? '') . ' ' . (string)($record['doctor_last_name'] ?? ''));
                            if ($doctorName === '') {
                                $doctorName = 'To be assigned';
                            }
                            $appointmentStatus = strtolower((string)($record['appointment_status'] ?? ''));
                            $recordStatus = strtolower((string)($record['record_status'] ?? ''));
                            $badge = 'secondary';
                            if ($recordStatus === 'finalized') {
                                $badge = 'success';
                            } elseif ($recordStatus === 'submitted') {
                                $badge = 'info';
                            } elseif ($appointmentStatus === 'completed') {
                                $badge = 'success';
                            }
                            ?>
                            <tr>
                                <td>#<?php echo (int)($record['appointment_id'] ?? 0); ?></td>
                                <td><?php echo !empty($record['appointment_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$record['appointment_date']))) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($doctorName); ?></td>
                                <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $recordStatus ?: $appointmentStatus))); ?></span></td>
                                <td class="text-truncate" style="max-width: 320px;"><?php echo htmlspecialchars((string)($record['reason'] ?? '')); ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary clinic-record-view-btn"
                                        data-record-id="<?php echo (int)($record['record_id'] ?? 0); ?>"
                                        data-appointment-id="<?php echo (int)($record['appointment_id'] ?? 0); ?>"
                                        data-appointment-date="<?php echo htmlspecialchars((string)($record['appointment_date'] ?? '')); ?>"
                                        data-doctor-name="<?php echo htmlspecialchars($doctorName); ?>"
                                    >
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="clinicRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1" id="clinicRecordModalTitle">Clinic Record</h5>
                    <div class="text-muted small" id="clinicRecordModalMeta"></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="clinicRecordPrintBtn">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <div id="clinicRecordModalBody" class="vstack gap-3">
                    <div class="text-muted">Select a record to view details.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.CLINIC_ODONTOGRAM_SRC = <?php echo json_encode(odontogram_embed_src()); ?>;
window.CLINIC_ODONTOGRAM_HEIGHT = <?php echo (int) odontogram_embed_height_px(); ?>;

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('clinicRecordModal');
    const modalTitle = document.getElementById('clinicRecordModalTitle');
    const modalMeta = document.getElementById('clinicRecordModalMeta');
    const modalBody = document.getElementById('clinicRecordModalBody');
    const viewButtons = document.querySelectorAll('.clinic-record-view-btn');
    if (!modalEl || !modalTitle || !modalMeta || !modalBody || viewButtons.length === 0) return;

    const apiUrl = '../assets/api/ehr/get_records_by_appointment_detailed.php';
    const modal = typeof bootstrap !== 'undefined' ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    function statusLabel(status) {
        return String(status || '').split('_').map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(' ');
    }

    function renderList(items, emptyText, formatter) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<div class="text-muted small">' + escapeHtml(emptyText) + '</div>';
        }
        return '<ul class="mb-0 ps-3">' + items.map(function (item) { return '<li>' + formatter(item) + '</li>'; }).join('') + '</ul>';
    }

    var currentRecordData = null;

    // Mirrors odontogram/chart-constants.js CHART_LEGEND — the print window can't load the
    // odontogram iframe (it's a live editor app, not a static image, and has no tooth data
    // of its own once opened in a fresh window), so we render the findings as a plain table
    // from the same JSON the iframe would have been fed via postMessage.
    var ODONTOGRAM_LEGEND = {
        C: 'Dental Caries', C1: 'Caries — Vital Pulp Expose', C2: 'Caries — Non-vital Pulp Expose',
        X: 'Indicated for Extraction', RF: 'Retained Root Fragment', Am: 'Amalgam Filling',
        S: 'Silicate Filling', GC: 'Gold Crown', Ab1: 'Bridge Abutment', P: 'Pontic',
        GCI: 'Gold Clasp', GI: 'Gold Inlays', M: 'Missing (Extraction)', Un: 'Unerupted'
    };
    var ODONTOGRAM_SURFACE_LABELS = { O: 'Occlusal', M: 'Mesial', D: 'Distal', B: 'Buccal', L: 'Lingual', root: 'Root', whole: 'Whole Tooth' };

    function formatOdontogramFindingsHtml(odontogramData) {
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
                    var names = labels.map(function (code) { return ODONTOGRAM_LEGEND[code] || code; }).join(', ');
                    parts.push((ODONTOGRAM_SURFACE_LABELS[surface] || surface) + ': ' + names);
                });
            }
            var comment = String(entry.comment || '').trim();
            if (!parts.length && !comment) return;
            rows.push('<tr><td>#' + escapeHtml(fdi) + '</td><td>' + escapeHtml(parts.join('; ')) + '</td><td>' + escapeHtml(comment) + '</td></tr>');
        });
        if (!rows.length) return '<div class="text-muted small">No findings recorded on the dental chart.</div>';
        return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tooth</th><th>Findings</th><th>Notes</th></tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
    }

    function postOdontogramToClinicFrame(odontogramData) {
        const frame = document.getElementById('clinicOdontogramFrame');
        if (!frame || !frame.contentWindow) return;
        frame.contentWindow.postMessage({ type: 'load_odontogram', toothState: odontogramData || {}, readonly: true }, '*');
    }

    function renderModal(data, fallbackMeta) {
        currentRecordData = data;
        const record = data && data.record ? data.record : {};
        const appointment = data && data.appointment ? data.appointment : {};
        const patientInfo = data && data.patient_info ? data.patient_info : {};
        const servicesAvailed = Array.isArray(data.services_availed) ? data.services_availed : [];
        const followUps = Array.isArray(data.follow_up_appointments) ? data.follow_up_appointments : [];
        const doctorName = fallbackMeta.doctorName || 'To be assigned';
        const appointmentDate = fallbackMeta.appointmentDate || appointment.appointment_date || '';
        const appointmentId = fallbackMeta.appointmentId || 0;
        const title = 'Appointment #' + appointmentId + ' Clinic Record';

        modalTitle.textContent = title;
        modalMeta.textContent = (appointmentDate ? new Date(appointmentDate + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' }) : 'N/A') + ' · ' + doctorName + (patientInfo.name ? ' · ' + patientInfo.name : '') + (appointment.status ? ' · ' + statusLabel(appointment.status) : '');

        const services = Array.isArray(record.recommended_services) ? record.recommended_services : [];
        const prescriptions = Array.isArray(data.prescriptions) ? data.prescriptions : [];
        const media = [];
        if (Array.isArray(record.images)) media.push.apply(media, record.images);
        if (record.primary_image) media.unshift(record.primary_image);

        const sections = [];
        sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Assessment</div>' +
            '<div class="mb-2"><div class="small text-muted fw-semibold">Reason</div><div>' + escapeHtml(record.reason || '') + '</div></div>' +
            '<div class="mb-2"><div class="small text-muted fw-semibold">Clinical Summary</div><div>' + escapeHtml(record.summary || 'No summary recorded.') + '</div></div>' +
            '<div class="mb-2"><div class="small text-muted fw-semibold">Doctor Notes</div><div>' + escapeHtml(record.doctor_notes || 'No doctor notes recorded.') + '</div></div>' +
            '<div class="small text-muted d-flex flex-wrap gap-3"><span>Record ID: ' + escapeHtml(record.record_id || '') + '</span>' +
            (record.follow_up_date ? '<span>Follow-up: ' + escapeHtml(new Date(record.follow_up_date + 'T00:00:00').toLocaleDateString()) + '</span>' : '') +
            (record.submitted_at ? '<span>Submitted: ' + escapeHtml(new Date(record.submitted_at.replace(' ', 'T')).toLocaleString()) + '</span>' : '') +
            '</div></div>');

        sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Services and Treatment</div>' +
            '<div class="mb-2"><div class="small text-muted fw-semibold">Services Availed</div>' + renderList(servicesAvailed.length ? servicesAvailed : services, 'No services recorded.', function (item) {
                if (typeof item !== 'object') return 'Service #' + escapeHtml(item);
                let label = escapeHtml(item.description || item.service_name || 'Service');
                const amount = item.amount !== undefined && item.amount !== null ? Number(item.amount) : null;
                if (amount !== null && !Number.isNaN(amount) && amount > 0) {
                    label += ' (₱' + amount.toFixed(2) + ')';
                }
                return label;
            }) + '</div>' +
            '</div>');

        sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Prescriptions</div>' +
            (prescriptions.length ? '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Medicine</th><th>Brand</th><th>Dosage</th><th>Duration</th><th>Notes</th></tr></thead><tbody>' + prescriptions.map(function (item) { return '<tr><td>' + escapeHtml(item.medicine_name || '') + '</td><td>' + escapeHtml(item.medicine_brand || '') + '</td><td>' + escapeHtml(item.dosage || '') + '</td><td>' + escapeHtml(item.duration || '') + '</td><td>' + escapeHtml(item.notes || '') + '</td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="text-muted small">No prescriptions recorded.</div>') +
            '</div>');

        sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Patient Background</div>' +
            '<div id="clinicRecordBackground"></div></div>');

        sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Clinical Images</div>' +
            '<div id="clinicRecordImages" class="row g-2"></div></div>');

        const toothComments = [];
        if (record.odontogram && typeof record.odontogram === 'object') {
            Object.keys(record.odontogram).forEach(function (toothNum) {
                const entry = record.odontogram[toothNum];
                if (entry && entry.comment) {
                    toothComments.push({ tooth: toothNum, comment: entry.comment });
                }
            });
        }

        sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Odontogram (read-only)</div>' +
            '<div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;height:' + CLINIC_ODONTOGRAM_HEIGHT + 'px;">' +
            '<iframe id="clinicOdontogramFrame" src="' + escapeHtml(CLINIC_ODONTOGRAM_SRC) + '" style="width:100%;height:100%;border:0;"></iframe>' +
            '</div>' +
            (toothComments.length ? '<div class="mt-2"><div class="small text-muted fw-semibold mb-1">Tooth Notes</div><ul class="mb-0 ps-3 small">' + toothComments.map(function (t) { return '<li><strong>Tooth #' + escapeHtml(t.tooth) + ':</strong> ' + escapeHtml(t.comment) + '</li>'; }).join('') + '</ul></div>' : '') +
            '</div>');

        if (followUps.length) {
            sections.push('<div class="border rounded-4 p-3"><div class="small text-muted fw-semibold mb-2">Follow-up Appointments</div>' +
                renderList(followUps, 'No follow-up appointments scheduled.', function (item) {
                    const d = item.appointment_date ? new Date(item.appointment_date + 'T00:00:00').toLocaleDateString() : 'N/A';
                    return d + ' — ' + escapeHtml(statusLabel(item.status)) + (item.reason ? ' (' + escapeHtml(item.reason) + ')' : '');
                }) + '</div>');
        }

        modalBody.innerHTML = sections.join('');

        const imagesEl = document.getElementById('clinicRecordImages');
        if (imagesEl && typeof renderMediaGallery === 'function') {
            renderMediaGallery(imagesEl, media.map(function (src, idx) {
                return { file_path: src, file_name: 'clinical-photo-' + (idx + 1) + '.jpg' };
            }), function (item) {
                return typeof resolveMediaPath === 'function' ? resolveMediaPath(item.file_path, '../') : item.file_path;
            });
        } else if (imagesEl) {
            imagesEl.innerHTML = '<div class="text-muted small">No clinical images uploaded.</div>';
        }

        const backgroundEl = document.getElementById('clinicRecordBackground');
        if (backgroundEl && typeof renderBackgroundProfilingFields === 'function') {
            backgroundEl.innerHTML = renderBackgroundProfilingFields(record.patient_profiling || null, patientInfo.gender);
        }

        const odontoFrame = document.getElementById('clinicOdontogramFrame');
        if (odontoFrame) {
            odontoFrame.addEventListener('load', function () { postOdontogramToClinicFrame(record.odontogram || null); }, { once: true });
        }
    }

    const printBtn = document.getElementById('clinicRecordPrintBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            const printWindow = window.open('', '_blank', 'width=900,height=700');
            if (!printWindow) return;

            // The odontogram is a live editor app running in an iframe — a fresh print
            // window has no way to load its tooth-state data into a brand new iframe, so
            // swap it for a plain findings table built from the same JSON instead of
            // trying (and failing) to print the iframe itself.
            const printBody = document.createElement('div');
            printBody.innerHTML = modalBody.innerHTML;
            const odontoFrameEl = printBody.querySelector('#clinicOdontogramFrame');
            if (odontoFrameEl && odontoFrameEl.parentElement) {
                const odontogramData = currentRecordData && currentRecordData.record ? currentRecordData.record.odontogram : null;
                odontoFrameEl.parentElement.outerHTML = formatOdontogramFindingsHtml(odontogramData);
            }

            printWindow.document.write(
                '<!doctype html><html><head><title>' + escapeHtml(modalTitle.textContent) + '</title>' +
                '<style>' +
                'body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#212529;}' +
                'h1{font-size:18px;margin:0 0 4px;}' +
                '.meta{color:#6c757d;font-size:13px;margin-bottom:20px;}' +
                '.border{border:1px solid #dee2e6;border-radius:8px;padding:12px;margin-bottom:12px;}' +
                '.small{font-size:12px;} .fw-semibold{font-weight:600;} .text-muted{color:#6c757d;}' +
                'table{width:100%;border-collapse:collapse;} th,td{border:1px solid #dee2e6;padding:6px;text-align:left;font-size:13px;}' +
                'ul{margin:0;padding-left:18px;}' +
                '.row,.g-2{display:none;}' +
                '</style></head><body>' +
                '<h1>' + escapeHtml(modalTitle.textContent) + '</h1>' +
                '<div class="meta">' + escapeHtml(modalMeta.textContent) + '</div>' +
                printBody.innerHTML +
                '</body></html>'
            );
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function () { printWindow.print(); }, 300);
        });
    }

    viewButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            const recordId = String(button.getAttribute('data-record-id') || '').trim();
            const appointmentId = String(button.getAttribute('data-appointment-id') || '').trim();
            const appointmentDate = String(button.getAttribute('data-appointment-date') || '').trim();
            const doctorName = String(button.getAttribute('data-doctor-name') || '').trim();

            if (!appointmentId) return;

            modalTitle.textContent = 'Loading clinic record...';
            modalMeta.textContent = '';
            modalBody.innerHTML = '<div class="text-muted">Loading detailed record...</div>';
            if (modal) modal.show();

            try {
                const url = apiUrl + '?appointment_id=' + encodeURIComponent(appointmentId) + (recordId ? '&record_id=' + encodeURIComponent(recordId) : '');
                const response = await fetch(url, { credentials: 'same-origin' });
                const data = await response.json();
                if (!data.success) {
                    modalTitle.textContent = 'Clinic Record';
                    modalBody.innerHTML = '<div class="text-danger">' + escapeHtml(data.message || 'Unable to load clinic record.') + '</div>';
                    return;
                }
                renderModal(data, {
                    appointmentId: appointmentId,
                    appointmentDate: appointmentDate,
                    doctorName: doctorName
                });
            } catch (err) {
                modalTitle.textContent = 'Clinic Record';
                modalBody.innerHTML = '<div class="text-danger">Unable to load clinic record.</div>';
            }
        });
    });
});
</script>