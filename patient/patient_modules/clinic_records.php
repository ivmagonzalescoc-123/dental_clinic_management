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

<style>
/* Clinic record "View details" modal */
#clinicRecordModal .modal-body { background: #f6f9fc; }
.cr-hero {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 18px;
    border-radius: 16px;
    background: #fff;
    border: 1px solid #e3eaf3;
}
.clinic-record-patient-avatar {
    width: 64px;
    height: 64px;
    flex: 0 0 64px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #dcecf5;
    background: #eef4f9;
}
.clinic-record-patient-avatar-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #1b7aa3;
    font-size: 28px;
}
.cr-hero__main { min-width: 0; }
.cr-hero__name { font-size: 1.1rem; font-weight: 700; color: #0f1e35; }
.cr-hero__contact { font-size: 0.82rem; color: #64748b; overflow-wrap: anywhere; }
.cr-hero__chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.cr-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 500;
    color: #334155;
    background: #eef4f9;
}
.cr-chip--status { color: #047857; background: #e7f6ef; }
.cr-chip--followup { color: #1b7aa3; background: #e3f2f9; }
.cr-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
@media (min-width: 992px) { .cr-grid { grid-template-columns: 1.3fr 1fr; } }
.cr-section {
    padding: 16px 18px;
    border-radius: 16px;
    background: #fff;
    border: 1px solid #e3eaf3;
}
.cr-section__title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 12px;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #1b7aa3;
}
.cr-field + .cr-field { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e3eaf3; }
.cr-field__label { font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 2px; }
.cr-field__value { color: #0f1e35; white-space: pre-line; overflow-wrap: anywhere; }
.cr-field__value.is-empty, .cr-empty { color: #94a3b8; font-style: italic; font-size: 0.9rem; }
.cr-service-list { list-style: none; margin: 0; padding: 0; }
.cr-service-list li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px dashed #e3eaf3;
}
.cr-service-list li:last-child { border-bottom: 0; }
.cr-service-list__price { font-weight: 600; color: #0f1e35; white-space: nowrap; }
.cr-table th { font-size: 0.75rem; color: #64748b; font-weight: 600; background: #f8fafc; }
.cr-odontogram {
    border: 1px solid #e3eaf3;
    border-radius: 12px;
    overflow: hidden;
    max-height: calc(100vh - 140px);
}
.cr-footnote { font-size: 0.75rem; color: #94a3b8; text-align: right; }
#clinicRecordImages .media-gallery-thumb-img { height: 130px; }
@media (max-width: 575.98px) {
    .cr-hero { align-items: flex-start; padding: 14px; }
    .clinic-record-patient-avatar { width: 52px; height: 52px; flex-basis: 52px; }
    .cr-section { padding: 14px; }
}
[data-theme="dark"] #clinicRecordModal .modal-body { background: #0f172a; }
[data-theme="dark"] .cr-hero, [data-theme="dark"] .cr-section { background: #1e293b; border-color: #334155; }
[data-theme="dark"] .cr-hero__name, [data-theme="dark"] .cr-field__value, [data-theme="dark"] .cr-service-list__price { color: #e2e8f0; }
[data-theme="dark"] .cr-chip { background: #334155; color: #cbd5e1; }
[data-theme="dark"] .cr-field + .cr-field, [data-theme="dark"] .cr-service-list li { border-color: #334155; }
[data-theme="dark"] .cr-table th { background: #0f172a; }
</style>

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
        const profileImage = String(patientInfo.profile_image || '').trim();
        const profileImageUrl = profileImage
            ? (/^https?:\/\//i.test(profileImage) ? profileImage : '../' + profileImage.replace(/^\/+/, ''))
            : '';
        const avatarPlaceholder = '<div class="clinic-record-patient-avatar clinic-record-patient-avatar-placeholder"><i class="bi bi-person-fill"></i></div>';
        const fmtDate = function (d) {
            return d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
        };
        const section = function (icon, title, body, extraClass) {
            return '<section class="cr-section' + (extraClass ? ' ' + extraClass : '') + '">' +
                '<h6 class="cr-section__title"><i class="bi ' + icon + '" aria-hidden="true"></i> ' + escapeHtml(title) + '</h6>' +
                body + '</section>';
        };
        const field = function (label, value, emptyText) {
            const text = String(value || '').trim();
            return '<div class="cr-field"><div class="cr-field__label">' + escapeHtml(label) + '</div>' +
                '<div class="cr-field__value' + (text ? '' : ' is-empty') + '">' + escapeHtml(text || emptyText) + '</div></div>';
        };
        const contact = [patientInfo.email, patientInfo.phone, patientInfo.address].filter(Boolean);
        const chips = [];
        if (appointmentDate) chips.push('<span class="cr-chip"><i class="bi bi-calendar-event"></i> ' + escapeHtml(fmtDate(appointmentDate)) + '</span>');
        chips.push('<span class="cr-chip"><i class="bi bi-person-badge"></i> ' + escapeHtml(doctorName) + '</span>');
        if (appointment.status) chips.push('<span class="cr-chip cr-chip--status"><i class="bi bi-check2-circle"></i> ' + escapeHtml(statusLabel(appointment.status)) + '</span>');
        if (record.follow_up_date) chips.push('<span class="cr-chip cr-chip--followup"><i class="bi bi-arrow-repeat"></i> Follow-up ' + escapeHtml(fmtDate(record.follow_up_date)) + '</span>');

        sections.push('<div class="clinic-record-patient-header cr-hero">' +
            (profileImageUrl
                ? '<img src="' + escapeHtml(profileImageUrl) + '" alt="" class="clinic-record-patient-avatar" data-cr-avatar>'
                : avatarPlaceholder) +
            '<div class="cr-hero__main">' +
                '<div class="cr-hero__name">' + escapeHtml(patientInfo.name || 'Patient') + '</div>' +
                (contact.length ? '<div class="cr-hero__contact">' + escapeHtml(contact.join(' · ')) + '</div>' : '') +
                '<div class="cr-hero__chips">' + chips.join('') + '</div>' +
            '</div></div>');

        const serviceItems = servicesAvailed.length ? servicesAvailed : services;
        const servicesHtml = serviceItems.length
            ? '<ul class="cr-service-list">' + serviceItems.map(function (item) {
                if (typeof item !== 'object') return '<li><span>Service #' + escapeHtml(item) + '</span></li>';
                const amount = item.amount !== undefined && item.amount !== null ? Number(item.amount) : null;
                const price = (amount !== null && !Number.isNaN(amount) && amount > 0)
                    ? '<span class="cr-service-list__price">₱' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>'
                    : '';
                return '<li><span>' + escapeHtml(item.description || item.service_name || 'Service') + '</span>' + price + '</li>';
            }).join('') + '</ul>'
            : '<div class="cr-empty">No services recorded.</div>';

        sections.push('<div class="cr-grid">' +
            section('bi-clipboard2-pulse', 'Assessment',
                field('Reason for visit', record.reason, 'Not recorded') +
                field('Clinical summary', record.summary, 'No summary recorded.') +
                field('Doctor notes', record.doctor_notes, 'No doctor notes recorded.')) +
            section('bi-receipt', 'Services availed', servicesHtml) +
            '</div>');

        // Treatment plan items done on this visit, and the ones planned for later visits.
        if (window.TreatmentPlan) {
            sections.push(section('bi-list-check', 'Treatments', window.TreatmentPlan.renderVisit(data.treatments)));
        }

        sections.push(section('bi-capsule', 'Prescriptions',
            prescriptions.length
                ? '<div class="table-responsive"><table class="table table-sm align-middle mb-0 cr-table"><thead><tr><th>Medicine</th><th>Brand</th><th>Dosage</th><th>Duration</th><th>Notes</th></tr></thead><tbody>' +
                    prescriptions.map(function (item) { return '<tr><td class="fw-semibold">' + escapeHtml(item.medicine_name || '') + '</td><td>' + escapeHtml(item.medicine_brand || '') + '</td><td>' + escapeHtml(item.dosage || '') + '</td><td>' + escapeHtml(item.duration || '') + '</td><td>' + escapeHtml(item.notes || '') + '</td></tr>'; }).join('') +
                    '</tbody></table></div>'
                : '<div class="cr-empty">No prescriptions recorded.</div>'));

        sections.push(section('bi-images', 'Clinical photos', '<div id="clinicRecordImages" class="row g-2"></div>'));

        sections.push(section('bi-person-lines-fill', 'Patient background', '<div id="clinicRecordBackground"></div>'));

        const toothComments = [];
        if (record.odontogram && typeof record.odontogram === 'object') {
            Object.keys(record.odontogram).forEach(function (toothNum) {
                const entry = record.odontogram[toothNum];
                if (entry && entry.comment) {
                    toothComments.push({ tooth: toothNum, comment: entry.comment });
                }
            });
        }

        sections.push(section('bi-grid-3x3-gap', 'Odontogram',
            '<div class="cr-odontogram" style="height:' + CLINIC_ODONTOGRAM_HEIGHT + 'px;">' +
            '<iframe id="clinicOdontogramFrame" src="' + escapeHtml(CLINIC_ODONTOGRAM_SRC) + '" title="Odontogram (read-only)" style="width:100%;height:100%;border:0;"></iframe>' +
            '</div>' +
            (toothComments.length ? '<div class="mt-3"><div class="cr-field__label mb-1">Tooth notes</div><ul class="mb-0 ps-3 small">' + toothComments.map(function (t) { return '<li><strong>Tooth #' + escapeHtml(t.tooth) + ':</strong> ' + escapeHtml(t.comment) + '</li>'; }).join('') + '</ul></div>' : '')));

        if (followUps.length) {
            sections.push(section('bi-calendar-check', 'Follow-up appointments',
                renderList(followUps, 'No follow-up appointments scheduled.', function (item) {
                    const d = item.appointment_date ? fmtDate(item.appointment_date) : 'N/A';
                    return escapeHtml(d) + ' — ' + escapeHtml(statusLabel(item.status)) + (item.reason ? ' (' + escapeHtml(item.reason) + ')' : '');
                })));
        }

        sections.push('<div class="cr-footnote">Record #' + escapeHtml(record.record_id || '') +
            (record.submitted_at ? ' · Submitted ' + escapeHtml(new Date(record.submitted_at.replace(' ', 'T')).toLocaleString()) : '') + '</div>');

        modalBody.innerHTML = sections.join('');

        // A broken or missing profile photo falls back to the icon instead of a broken image.
        const avatarImg = modalBody.querySelector('[data-cr-avatar]');
        if (avatarImg) {
            avatarImg.addEventListener('error', function () { avatarImg.outerHTML = avatarPlaceholder; }, { once: true });
        }

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
            printBody.querySelectorAll('img').forEach(function (img) {
                const src = img.getAttribute('src') || '';
                if (src && !/^https?:\/\//i.test(src) && !src.startsWith('data:')) {
                    img.setAttribute('src', new URL(src, window.location.href).href);
                }
            });

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
                '.clinic-record-patient-header{display:flex;align-items:center;gap:12px;border:1px solid #dee2e6;border-radius:8px;padding:10px;margin-bottom:12px;}' +
                '.clinic-record-patient-avatar{width:52px;height:52px;border-radius:50%;object-fit:cover;border:1px solid #dee2e6;}' +
                '.clinic-record-patient-avatar-placeholder{display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#64748b;font-size:22px;}' +
                '.row,.g-2{display:flex;flex-wrap:wrap;gap:8px;} .row>[class*="col-"],.g-2>[class*="col-"]{display:block;box-sizing:border-box;}' +
                '.col-6{width:calc(50% - 4px);} .col-md-3{width:calc(25% - 6px);} .col-md-4{width:calc(33.333% - 6px);} .col-md-12{width:100%;}' +
                '.cr-hero{display:flex;align-items:center;gap:12px;border:1px solid #dee2e6;border-radius:8px;padding:10px;margin-bottom:12px;} .cr-hero__name{font-weight:700;} .cr-hero__contact{font-size:12px;color:#6c757d;} .cr-hero__chips{margin-top:4px;} .cr-chip{display:inline-block;font-size:12px;margin-right:10px;color:#475569;} .cr-chip i{display:none;}' +
                '.cr-section{border:1px solid #dee2e6;border-radius:8px;padding:12px;margin-bottom:12px;break-inside:avoid;} .cr-section__title{font-size:13px;margin:0 0 8px;text-transform:uppercase;color:#1b7aa3;} .cr-section__title i{display:none;}' +
                '.cr-field{margin-bottom:6px;} .cr-field__label{font-size:12px;font-weight:600;color:#6c757d;} .cr-empty,.is-empty{color:#94a3b8;font-style:italic;}' +
                '.tp-phase-title{font-size:12px;font-weight:600;color:#6c757d;margin:6px 0 4px;} .tp-list{list-style:none;padding:0;margin:0;} .tp-item{display:flex;gap:10px;align-items:center;padding:4px 0;border-bottom:1px dashed #dee2e6;font-size:13px;} .tp-main{flex:1;} .tp-name{font-weight:600;} .tp-notes{font-size:11px;color:#6c757d;} .tp-tooth{min-width:24px;font-weight:700;} .tp-tooth i{display:none;} .tp-badge{font-size:11px;color:#475569;} .tp-empty{color:#94a3b8;font-style:italic;}' +
                '.cr-service-list{list-style:none;padding:0;margin:0;} .cr-service-list li{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #dee2e6;} .cr-footnote{font-size:11px;color:#94a3b8;}' +
                '.media-gallery-card{break-inside:avoid;} .media-gallery-thumb-btn{display:block;width:100%;border:0;background:none;padding:0;} .media-gallery-thumb-overlay,.media-gallery-card a{display:none !important;} .media-gallery-thumb-img{display:block;width:100%;max-width:180px;height:130px;object-fit:cover;border:1px solid #dee2e6;padding:2px;border-radius:4px;}' +
                '</style></head><body>' +
                '<h1>' + escapeHtml(modalTitle.textContent) + '</h1>' +
                '<div class="meta">' + escapeHtml(modalMeta.textContent) + '</div>' +
                printBody.innerHTML +
                '</body></html>'
            );
            printWindow.document.close();
            printWindow.focus();
            // Print once the photos have loaded (or failed), capped at 4s; printing right
            // away sent the dialog out with blank images on slower connections.
            const pending = Array.prototype.filter.call(printWindow.document.images, function (img) { return !img.complete; });
            let printed = false;
            const doPrint = function () {
                if (printed) return;
                printed = true;
                printWindow.print();
            };
            let remaining = pending.length;
            if (!remaining) {
                setTimeout(doPrint, 150);
            } else {
                pending.forEach(function (img) {
                    const done = function () { if (--remaining <= 0) setTimeout(doPrint, 150); };
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                });
                setTimeout(doPrint, 4000);
            }
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