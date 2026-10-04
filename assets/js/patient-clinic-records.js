/**
 * Patient portal "Clinic Records" (patient/patient_modules/clinic_records.php): a list of
 * the patient's completed visits; selecting one replaces the list with that visit's full
 * record in place (Back returns to the list), like the staff Patients page.
 *
 *   PatientClinicRecords.init(config)   config: { records, odontogramSrc, odontogramHeight }
 *
 * The record comes from assets/api/ehr/get_records_by_appointment_detailed.php, which only
 * returns the logged-in patient's own visits.
 */
(function (global) {
    'use strict';

    var API = '../assets/api/ehr/get_records_by_appointment_detailed.php';
    var STATUS_CLASS = { finalized: 'success', completed: 'success', records_complete: 'success', submitted: 'info' };
    var LEGEND = {
        C: 'Dental Caries', C1: 'Caries — Vital Pulp Expose', C2: 'Caries — Non-vital Pulp Expose',
        X: 'Indicated for Extraction', RF: 'Retained Root Fragment', Am: 'Amalgam Filling',
        S: 'Silicate Filling', GC: 'Gold Crown', Ab1: 'Bridge Abutment', P: 'Pontic',
        GCI: 'Gold Clasp', GI: 'Gold Inlays', M: 'Missing (Extraction)', Un: 'Unerupted'
    };
    var SURFACES = { O: 'Occlusal', M: 'Mesial', D: 'Distal', B: 'Buccal', L: 'Lingual', root: 'Root', whole: 'Whole Tooth' };

    var state = null;
    var documentBound = false;

    // ---------- helpers ----------
    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function day(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || ''));
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function fmtDate(v) {
        var d = day(v);
        return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    }
    function label(s) {
        return String(s || '').split('_').map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(' ');
    }
    function badge(s) {
        return '<span class="badge bg-' + (STATUS_CLASS[s] || 'secondary') + ' dp-status">' + esc(label(s || 'completed')) + '</span>';
    }
    function dateBlock(v, cls) {
        var d = day(v);
        if (!d) return '<span class="' + cls + '"><strong>—</strong></span>';
        return '<span class="' + cls + '"><strong>' + d.getDate() + '</strong><small>' +
            esc(d.toLocaleDateString(undefined, { month: 'short' })) + ' ' + d.getFullYear() + '</small></span>';
    }
    function note(icon, text) {
        return '<div class="dp-note"><i class="bi ' + icon + '"></i><span>' + esc(text) + '</span></div>';
    }
    function card(icon, title, body) {
        return '<article class="dp-card"><div class="dp-card__head"><h6 class="dp-card__title"><i class="bi ' + icon + '"></i> ' + esc(title) + '</h6></div>' + body + '</article>';
    }
    function field(name, value, empty) {
        var t = String(value || '').trim();
        if (t.toUpperCase() === 'N/A') t = ''; // the database's placeholder for "blank"
        return '<div class="cr-field"><div class="cr-field__label">' + esc(name) + '</div>' +
            '<div class="cr-field__value' + (t ? '' : ' is-empty') + '">' + esc(t || empty) + '</div></div>';
    }
    function skeleton() {
        return '<div class="dp-skeleton" aria-busy="true"><span style="width:92%"></span><span style="width:78%"></span><span style="width:64%"></span></div>';
    }
    function titleOf(r) {
        return r.services || (r.reason && r.reason.toUpperCase() !== 'N/A' ? r.reason : 'Clinic visit');
    }

    // ---------- list ----------
    function rowHtml(r) {
        var sub = ['Dr. ' + r.doctor.replace(/^dr\.?\s*/i, '')];
        if (r.reference) sub.push('Ref ' + r.reference);
        return '<button type="button" class="dp-row cr-row" data-cr-open="' + r.appointment_id + '">' +
            dateBlock(r.date, 'cr-row__date') +
            '<span class="dp-row__main"><span class="dp-row__name">' + esc(titleOf(r)) + '</span>' +
                '<span class="dp-row__sub">' + esc(sub.join(' · ')) + '</span></span>' +
            '<span class="dp-row__stat"><small class="dp-row__label">Follow-up</small>' +
                (r.follow_up ? '<strong>' + esc(fmtDate(r.follow_up)) + '</strong>' : '<strong class="text-muted">—</strong><small>None scheduled</small>') + '</span>' +
            '<span class="dp-row__stat"><small class="dp-row__label">Record</small>' + badge(r.status) + '</span>' +
            '<i class="bi bi-chevron-right dp-row__chev" aria-hidden="true"></i>' +
        '</button>';
    }
    function renderList() {
        var list = $('crList'), empty = $('crEmpty');
        if (!list) return;
        var q = state.query.trim().toLowerCase();
        var shown = state.records.filter(function (r) {
            if (!q) return true;
            var hay = [fmtDate(r.date), r.date, r.services, r.reason, r.doctor, r.reference, label(r.status)].join(' ').toLowerCase();
            return q.split(/\s+/).every(function (t) { return hay.indexOf(t) !== -1; });
        });
        list.innerHTML = shown.map(rowHtml).join('');
        if (empty) {
            empty.hidden = shown.length > 0;
            empty.innerHTML = state.records.length
                ? note('bi-search', 'No visits match your search.')
                : note('bi-journal-medical', 'No completed clinic records yet. Your records appear here after each visit.');
        }
    }

    // ---------- record view ----------
    function byId(id) {
        for (var i = 0; i < state.records.length; i++) if (state.records[i].appointment_id === id) return state.records[i];
        return null;
    }
    function open(id) {
        var r = byId(id);
        if (!r) return;
        state.listScroll = global.scrollY || 0;
        state.current = { record: r, data: null, loaded: {} };
        $('crListView').hidden = true;
        $('crRecordView').hidden = false;
        $('crRecordDate').outerHTML = dateBlock(r.date, 'cr-date-badge').replace('<span class="cr-date-badge"', '<span class="cr-date-badge" id="crRecordDate" aria-hidden="true"');
        $('crRecordTitle').textContent = titleOf(r);
        var meta = [fmtDate(r.date), 'Dr. ' + r.doctor.replace(/^dr\.?\s*/i, '')];
        if (r.reference) meta.push('Ref ' + r.reference);
        $('crRecordMeta').textContent = meta.join(' · ');
        $('crRecordChips').innerHTML = badge(r.status) +
            (r.follow_up ? ' <span class="dp-flag dp-flag--live"><i class="bi bi-arrow-repeat"></i>Follow-up ' + esc(fmtDate(r.follow_up)) + '</span>' : '');
        document.querySelector('[data-cr-pane="summary"]').innerHTML = skeleton();
        $('crHealth').innerHTML = skeleton();
        $('crPhotos').innerHTML = '';
        $('crToothNotes').innerHTML = '';
        $('crChartNote').innerHTML = '';
        showTab('summary');
        try { global.scrollTo({ top: 0, behavior: 'auto' }); } catch (e) { global.scrollTo(0, 0); }

        var cur = state.current;
        var url = API + '?appointment_id=' + encodeURIComponent(r.appointment_id) + (r.record_id ? '&record_id=' + encodeURIComponent(r.record_id) : '');
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (state.current !== cur) return;
                if (!data || !data.success) throw new Error((data && data.message) || 'failed');
                cur.data = data;
                renderSummary(data);
                renderHealth(data);
                renderPhotos(data);
                if (cur.loaded.chart) renderChart(data);
            })
            .catch(function () {
                if (state.current !== cur) return;
                document.querySelector('[data-cr-pane="summary"]').innerHTML = note('bi-wifi-off', 'Could not load this record. Go back and select it again.');
                $('crHealth').innerHTML = '';
            });
    }
    function close() {
        if (!state || !state.current) return;
        state.current = null;
        $('crRecordView').hidden = true;
        $('crListView').hidden = false;
        global.scrollTo(0, state.listScroll || 0);
    }
    function showTab(name) {
        document.querySelectorAll('#crRecordView [data-cr-tab]').forEach(function (t) {
            var on = t.getAttribute('data-cr-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('#crRecordView [data-cr-pane]').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-cr-pane') === name);
        });
        var cur = state.current;
        if (name === 'chart' && cur && !cur.loaded.chart) {
            cur.loaded.chart = true;
            if (cur.data) renderChart(cur.data); else $('crChartNote').innerHTML = '<span class="dp-muted">Loading chart…</span>';
        }
    }

    function renderSummary(data) {
        var rec = data.record || {};
        var services = (Array.isArray(data.services_availed) && data.services_availed.length) ? data.services_availed
            : (Array.isArray(rec.recommended_services) ? rec.recommended_services : []);
        var rx = Array.isArray(data.prescriptions) ? data.prescriptions : [];
        var followUps = Array.isArray(data.follow_up_appointments) ? data.follow_up_appointments : [];

        var servicesHtml = services.length
            ? '<ul class="cr-services">' + services.map(function (it) {
                if (typeof it !== 'object') return '<li><span>Service #' + esc(it) + '</span></li>';
                var amt = it.amount != null ? Number(it.amount) : NaN;
                return '<li><span>' + esc(it.description || it.service_name || 'Service') + '</span>' +
                    (amt > 0 ? '<span class="cr-services__price">₱' + amt.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>' : '') + '</li>';
            }).join('') + '</ul>'
            : note('bi-receipt', 'No services recorded.');

        var rxHtml = rx.length
            ? '<div class="table-responsive"><table class="table table-sm dp-table mb-0"><thead><tr><th>Medicine</th><th>Dosage</th><th>Duration</th><th>Instructions</th></tr></thead><tbody>' +
                rx.map(function (r) {
                    return '<tr><td><strong>' + esc(r.medicine_name || '') + '</strong>' + (r.medicine_brand ? '<div class="dp-muted">' + esc(r.medicine_brand) + '</div>' : '') +
                        '</td><td>' + esc(r.dosage || '') + '</td><td>' + esc(r.duration || '') + '</td><td>' + esc(r.notes || '') + '</td></tr>';
                }).join('') + '</tbody></table></div>'
            : note('bi-capsule', 'No prescriptions for this visit.');

        var treatments = global.TreatmentPlan && data.treatments ? global.TreatmentPlan.renderVisit(data.treatments) : '';

        var side = card('bi-receipt', 'Services availed', servicesHtml);
        if (followUps.length) {
            side += card('bi-calendar-check', 'Follow-up appointments', '<ul class="cr-services">' + followUps.map(function (f) {
                return '<li><span>' + esc(fmtDate(f.appointment_date)) + (f.reason ? ' — ' + esc(f.reason) : '') + '</span>' + badge(f.status) + '</li>';
            }).join('') + '</ul>');
        }

        document.querySelector('[data-cr-pane="summary"]').innerHTML =
            '<div class="cr-summary">' +
                '<div class="cr-summary__side">' +
                    card('bi-clipboard2-pulse', 'Assessment',
                        field('Reason for visit', rec.reason, 'Not recorded') +
                        field('Clinical summary', rec.summary, 'No summary recorded.') +
                        field('Doctor’s notes', rec.doctor_notes, 'No notes recorded.')) +
                    (treatments ? card('bi-list-check', 'Treatments', treatments) : '') +
                    card('bi-capsule', 'Prescriptions', rxHtml) +
                '</div>' +
                '<div class="cr-summary__side">' + side + '</div>' +
            '</div>' +
            '<div class="cr-footnote">' + (rec.submitted_at ? 'Recorded ' + esc(fmtDate(rec.submitted_at)) : '') + '</div>';
    }

    function renderHealth(data) {
        var el = $('crHealth');
        var rec = data.record || {}, info = data.patient_info || {};
        el.innerHTML = typeof global.renderBackgroundProfilingFields === 'function'
            ? global.renderBackgroundProfilingFields(rec.patient_profiling || null, info.gender)
            : note('bi-heart-pulse', 'No health background recorded.');
    }

    function photoList(data) {
        var rec = data.record || {}, media = [];
        if (rec.primary_image) media.push(rec.primary_image);
        (Array.isArray(rec.images) ? rec.images : []).forEach(function (src) { if (src && media.indexOf(src) === -1) media.push(src); });
        return media;
    }
    function renderPhotos(data) {
        var el = $('crPhotos'), media = photoList(data);
        if (!media.length) { el.innerHTML = '<div class="col-12">' + note('bi-image', 'No clinical photos for this visit.') + '</div>'; return; }
        if (typeof global.renderMediaGallery === 'function') {
            global.renderMediaGallery(el, media.map(function (src, i) { return { file_path: src, file_name: 'clinical-photo-' + (i + 1) + '.jpg' }; }),
                function (item) { return typeof global.resolveMediaPath === 'function' ? global.resolveMediaPath(item.file_path, '../') : item.file_path; });
        }
    }

    function findings(odontogram) {
        var out = [];
        Object.keys(odontogram || {}).sort(function (a, b) { return Number(a) - Number(b); }).forEach(function (fdi) {
            var t = odontogram[fdi] || {}, parts = [];
            Object.keys(t.surfaces || {}).forEach(function (s) {
                var codes = t.surfaces[s];
                if (Array.isArray(codes) && codes.length) parts.push((SURFACES[s] || s) + ': ' + codes.map(function (c) { return LEGEND[c] || c; }).join(', '));
            });
            var comment = String(t.comment || '').trim();
            if (parts.length || comment) out.push({ tooth: fdi, text: parts.join('; '), comment: comment });
        });
        return out;
    }
    function findingsTable(list) {
        if (!list.length) return note('bi-grid-3x3-gap', 'No findings recorded on the dental chart.');
        return '<div class="table-responsive"><table class="table table-sm dp-table mb-0"><thead><tr><th>Tooth</th><th>Findings</th><th>Notes</th></tr></thead><tbody>' +
            list.map(function (f) { return '<tr><td><strong>#' + esc(f.tooth) + '</strong></td><td>' + esc(f.text || '—') + '</td><td>' + esc(f.comment) + '</td></tr>'; }).join('') +
            '</tbody></table></div>';
    }
    function renderChart(data) {
        var rec = data.record || {}, od = rec.odontogram || {};
        var list = findings(od);
        $('crChartNote').innerHTML = '<span><strong>' + esc(fmtDate(state.current.record.date)) + '</strong></span>' +
            '<span class="dp-muted">' + (list.length ? list.length + ' charted ' + (list.length === 1 ? 'tooth' : 'teeth') : 'No teeth charted on this visit') + ' · read-only</span>';
        $('crToothNotes').innerHTML = card('bi-list-ul', 'Tooth findings', findingsTable(list));
        var frame = $('crChartFrame');
        var send = function () { if (frame.contentWindow) frame.contentWindow.postMessage({ type: 'load_odontogram', toothState: od, readonly: true }, '*'); };
        if (!frame.getAttribute('src')) {
            frame.addEventListener('load', send, { once: true });
            frame.src = frame.getAttribute('data-src');
        } else {
            send();
            frame.contentWindow && frame.contentWindow.postMessage({ type: 'odontogram_resize' }, '*');
        }
    }

    // ---------- print ----------
    // A purpose-built document, not a copy of the screen: the screen layout relies on
    // Bootstrap's grid, which the print window does not load.
    var FLAG_KEYS = ['is_hypertensive', 'is_diabetic', 'is_asthmatic', 'has_heart_disease', 'is_hiv_positive'];
    function blank(v) {
        var t = String(v == null ? '' : v).trim();
        return !t || t.toUpperCase() === 'N/A' || t === '-';
    }
    function money(v) {
        return '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function printTable(head, rows, widths) {
        return '<table><thead><tr>' + head.map(function (h, i) {
            return '<th' + (widths && widths[i] ? ' style="width:' + widths[i] + '"' : '') + '>' + esc(h) + '</th>';
        }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
    }
    function printHealth(prof, gender) {
        if (!prof || typeof prof !== 'object') return '<p class="none">No medical history on file.</p>';
        var labels = global.BACKGROUND_PROFILING_LABELS || {};
        var isMale = String(gender || '').toLowerCase() === 'male';
        // Conditions first, on one line, so they are impossible to miss.
        var yes = FLAG_KEYS.filter(function (k) { return prof[k] === 1 || prof[k] === '1' || prof[k] === true; })
            .map(function (k) { return labels[k] || k; });
        var cells = [];
        Object.keys(labels).forEach(function (k) {
            if (FLAG_KEYS.indexOf(k) !== -1) return;
            if (k === 'pregnancy_status' && isMale) return;
            if (blank(prof[k])) return;
            cells.push('<div class="kv"><span>' + esc(labels[k]) + '</span><b>' + esc(String(prof[k]).trim()) + '</b></div>');
        });
        return '<p class="conditions"><b>Medical conditions:</b> ' + (yes.length ? esc(yes.join(', ')) : 'None reported') + '</p>' +
            (cells.length ? '<div class="grid">' + cells.join('') + '</div>' : '<p class="none">No other health information recorded.</p>');
    }
    function print() {
        var cur = state && state.current;
        if (!cur || !cur.data) return;
        var r = cur.record, data = cur.data, rec = data.record || {}, info = data.patient_info || {};
        var w = global.open('', '_blank', 'width=900,height=700');
        if (!w) return;

        var services = (Array.isArray(data.services_availed) && data.services_availed.length) ? data.services_availed : [];
        var total = 0;
        var serviceRows = services.map(function (it) {
            var amt = Number(it.amount || 0); total += amt;
            return '<tr><td>' + esc(it.description || it.service_name || 'Service') + '</td><td class="num">' + (amt > 0 ? money(amt) : '') + '</td></tr>';
        });
        if (services.length && total > 0) serviceRows.push('<tr class="total"><td>Total</td><td class="num">' + money(total) + '</td></tr>');

        var tr = data.treatments || {};
        var tooth = function (i) { return i.tooth_number ? '#' + i.tooth_number : 'Whole mouth'; };
        var treatmentRows = [].concat(
            (tr.done || []).map(function (i) { return '<tr><td>' + esc(i.service_name || 'Treatment') + '</td><td>' + esc(tooth(i)) + '</td><td>Done this visit</td></tr>'; }),
            (tr.planned || []).map(function (i) { return '<tr><td>' + esc(i.service_name || 'Treatment') + '</td><td>' + esc(tooth(i)) + '</td><td>Planned</td></tr>'; })
        );

        var rx = Array.isArray(data.prescriptions) ? data.prescriptions : [];
        var rxRows = rx.map(function (m) {
            return '<tr><td><b>' + esc(m.medicine_name || '') + '</b>' + (m.medicine_brand ? ' (' + esc(m.medicine_brand) + ')' : '') + '</td><td>' + esc(m.dosage || '') + '</td><td>' + esc(m.duration || '') + '</td><td>' + esc(m.notes || '') + '</td></tr>';
        });

        var teeth = findings(rec.odontogram || {});
        var toothRows = teeth.map(function (f) { return '<tr><td><b>#' + esc(f.tooth) + '</b></td><td>' + esc(f.text || '—') + '</td><td>' + esc(f.comment) + '</td></tr>'; });

        var followUps = Array.isArray(data.follow_up_appointments) ? data.follow_up_appointments : [];
        var photos = photoList(data).map(function (src) {
            var url = typeof global.resolveMediaPath === 'function' ? global.resolveMediaPath(src, '../') : src;
            return '<img src="' + esc(new URL(url, global.location.href).href) + '" alt="">';
        }).join('');

        var assessment = [['Reason for visit', rec.reason], ['Clinical summary', rec.summary], ['Doctor’s notes', rec.doctor_notes]]
            .filter(function (x) { return !blank(x[1]); });

        var section = function (title, body) { return '<section><h2>' + esc(title) + '</h2>' + body + '</section>'; };
        var none = function (t) { return '<p class="none">' + esc(t) + '</p>'; };
        var kv = function (k, v) { return '<div class="kv"><span>' + esc(k) + '</span><b>' + esc(v) + '</b></div>'; };
        var doctor = 'Dr. ' + r.doctor.replace(/^dr\.?\s*/i, '');

        var html =
            '<header><div><div class="clinic">' + esc(state.clinicName) + '</div><div class="addr">' + esc(state.clinicAddress) + '</div></div>' +
                '<div class="doc"><div class="doctitle">CLINIC RECORD</div>' + (r.reference ? '<div>Ref <b>' + esc(r.reference) + '</b></div>' : '') + '</div></header>' +
            '<div class="info">' +
                kv('Patient', info.name || '') + kv('Visit date', fmtDate(r.date)) + kv('Dentist', doctor) +
                kv('Record status', label(r.status || 'completed')) +
                (info.phone ? kv('Contact', info.phone) : '') +
                (r.follow_up ? kv('Follow-up', fmtDate(r.follow_up)) : '') +
            '</div>' +
            section('Assessment', assessment.length
                ? assessment.map(function (x) { return '<p><b>' + esc(x[0]) + ':</b> ' + esc(String(x[1]).trim()) + '</p>'; }).join('')
                : none('No assessment notes recorded.')) +
            section('Services availed', services.length ? printTable(['Service', 'Amount'], serviceRows, ['', '120px']) : none('No services recorded.')) +
            (treatmentRows.length ? section('Treatments', printTable(['Procedure', 'Tooth', 'Status'], treatmentRows, ['', '110px', '130px'])) : '') +
            section('Prescriptions', rx.length ? printTable(['Medicine', 'Dosage', 'Duration', 'Instructions'], rxRows) : none('No prescriptions for this visit.')) +
            section('Dental chart findings', teeth.length ? printTable(['Tooth', 'Findings', 'Notes'], toothRows, ['60px', '', '']) : none('No findings recorded on the dental chart.')) +
            (followUps.length ? section('Follow-up appointments', '<ul>' + followUps.map(function (f) {
                return '<li>' + esc(fmtDate(f.appointment_date)) + ' — ' + esc(label(f.status)) + (f.reason && !blank(f.reason) ? ' (' + esc(f.reason) + ')' : '') + '</li>';
            }).join('') + '</ul>') : '') +
            section('Health background', printHealth(rec.patient_profiling, info.gender)) +
            (photos ? section('Clinical photos', '<div class="photos">' + photos + '</div>') : '') +
            '<footer>Printed ' + esc(new Date().toLocaleString()) + ' · ' + esc(state.clinicName) + ' · A copy of your clinic record for personal reference.</footer>';

        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Clinic Record' + (r.reference ? ' — Ref ' + esc(r.reference) : '') + '</title><style>' +
            '@page{size:auto;margin:14mm}*{box-sizing:border-box}' +
            'body{font-family:Arial,Helvetica,sans-serif;color:#0f172a;font-size:11.5px;line-height:1.4;margin:0}' +
            'header{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #1b7aa3;padding-bottom:8px;margin-bottom:10px}' +
            '.clinic{font-size:17px;font-weight:700;color:#1b7aa3}.addr{color:#64748b;font-size:10.5px}.doc{text-align:right}.doctitle{font-size:13px;font-weight:700;letter-spacing:.08em}' +
            '.info{display:grid;grid-template-columns:repeat(3,1fr);gap:4px 16px;background:#f5f8fb;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;margin-bottom:6px}' +
            '.kv span{display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}.kv b{font-weight:600}' +
            'section{margin-top:12px;break-inside:avoid}h2{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#1b7aa3;border-bottom:1px solid #cfe3ec;padding-bottom:3px;margin:0 0 6px}' +
            'p{margin:0 0 4px}.none{color:#94a3b8;font-style:italic}.conditions{margin-bottom:6px}' +
            'table{width:100%;border-collapse:collapse}th{background:#f1f5f9;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#475569}' +
            'th,td{border:1px solid #e2e8f0;padding:4px 6px;vertical-align:top}.num{text-align:right;white-space:nowrap}.total td{font-weight:700;background:#f8fafc}' +
            '.grid{display:grid;grid-template-columns:1fr 1fr;gap:2px 16px}.grid .kv{display:flex;gap:8px;border-bottom:1px dotted #e2e8f0;padding:2px 0}.grid .kv span{flex:0 0 44%;font-size:10px}' +
            'ul{margin:0;padding-left:16px}.photos{display:flex;flex-wrap:wrap;gap:6px}.photos img{width:23%;aspect-ratio:4/3;object-fit:cover;border:1px solid #e2e8f0;border-radius:4px}' +
            'footer{margin-top:16px;padding-top:6px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:9.5px;text-align:center}' +
            '</style></head><body>' + html + '</body></html>');
        w.document.close();
        w.focus();
        var imgs = Array.prototype.filter.call(w.document.images, function (i) { return !i.complete; });
        var done = false, left = imgs.length;
        var go = function () { if (!done) { done = true; w.print(); } };
        if (!left) setTimeout(go, 150);
        imgs.forEach(function (i) { var f = function () { if (--left <= 0) setTimeout(go, 150); }; i.addEventListener('load', f, { once: true }); i.addEventListener('error', f, { once: true }); });
        setTimeout(go, 4000);
    }

    // ---------- wiring ----------
    function init(cfg) {
        state = { records: (cfg && cfg.records) || [], query: '', current: null, listScroll: 0,
            clinicName: (cfg && cfg.clinicName) || 'Azucena\u2019s Dental Clinic', clinicAddress: (cfg && cfg.clinicAddress) || '' };
        var search = $('crSearch');
        if (search) search.addEventListener('input', function () { state.query = search.value; renderList(); });
        var list = $('crList');
        if (list) list.addEventListener('click', function (e) {
            var row = e.target.closest('[data-cr-open]');
            if (row) open(Number(row.getAttribute('data-cr-open')));
        });
        var view = $('crRecordView');
        if (view) view.addEventListener('click', function (e) {
            if (e.target.closest('[data-cr-back]')) { close(); return; }
            if (e.target.closest('#crPrint')) { print(); return; }
            var tab = e.target.closest('[data-cr-tab]');
            if (tab) showTab(tab.getAttribute('data-cr-tab'));
        });
        renderList();

        // The portal swaps pages without reloading, so document listeners are bound once.
        if (documentBound) return;
        documentBound = true;
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !state || !state.current || !$('crRecordView')) return;
            if (document.querySelector('.modal.show') || document.body.classList.contains('modal-open')) return;
            close();
        });
    }

    global.PatientClinicRecords = { init: init };
})(window);
