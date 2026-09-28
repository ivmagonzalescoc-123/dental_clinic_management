/**
 * Doctor "My Patients" page: a searchable patient list and a tabbed Patient Chart modal.
 *
 *   DoctorPatients.init(config)   — config is built by doctor/doctor_modules/patients.php
 *
 * The list and each patient's appointment timeline come from the page itself. Chart tabs
 * load their own data only when opened (profiling, treatment plan, per-visit records),
 * using the same APIs as the EHR and Records screens, so no access rules change.
 */
(function (global) {
    'use strict';

    var API = '../assets/api/';
    var DONE = ['completed', 'records_complete', 'completed_by_doctor', 'awaiting_secretary_records'];
    var IN_CLINIC = ['arrived', 'checked_in', 'in_progress'];
    var CLOSED = ['cancelled', 'no_show'];
    var STATUS_CLASS = {
        completed: 'success', records_complete: 'success', completed_by_doctor: 'success', awaiting_secretary_records: 'info',
        scheduled: 'warning', confirmed: 'primary', rescheduled: 'secondary',
        arrived: 'info', checked_in: 'info', in_progress: 'info',
        cancelled: 'danger', no_show: 'danger'
    };
    var SURFACE_NAMES = { O: 'Occlusal', M: 'Mesial', D: 'Distal', B: 'Buccal', L: 'Lingual', F: 'Facial', I: 'Incisal', P: 'Palatal', W: 'Whole tooth' };
    var FINDING_LABELS = {
        chief_complaint: 'Chief complaint', case_history: 'Case history', oral_hygiene: 'Oral hygiene',
        gingival_color: 'Gingival color', gingiva_consistency: 'Gingiva consistency', lymph_nodes: 'Lymph nodes',
        tongue: 'Tongue', occlusion: 'Occlusion', operation_if_any: 'Operation', blood_pressure: 'Blood pressure',
        blood_sugar: 'Blood sugar', bleeding_time: 'Bleeding time', clotting_time: 'Clotting time',
        radiographic_interpretation: 'Radiographic interpretation', allergy_notes: 'Allergy notes',
        blood_diseases: 'Blood diseases', fainting: 'Fainting'
    };

    var state = null;

    // ---------- small helpers ----------
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function meaningful(v) {
        var s = String(v == null ? '' : v).trim().toLowerCase();
        // "Non-smoker", "Not pregnant", "No" etc. are answers, not findings worth surfacing.
        return !!s && ['n/a', 'na', 'none', 'no', 'nil', '-'].indexOf(s) === -1 && !/^(non[- ]|not |no )/.test(s);
    }
    function parseDay(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function fmtDate(value, withYear) {
        var d = parseDay(value);
        if (!d) return '—';
        var opts = { month: 'short', day: 'numeric' };
        if (withYear !== false) opts.year = 'numeric';
        return d.toLocaleDateString(undefined, opts);
    }
    function fmtTime(value) {
        var m = /(\d{2}):(\d{2})/.exec(String(value || '').slice(11));
        if (!m) return '';
        var h = +m[1];
        return ((h % 12) || 12) + ':' + m[2] + ' ' + (h < 12 ? 'AM' : 'PM');
    }
    function timeRange(appt) {
        var a = fmtTime(appt.start), b = fmtTime(appt.end);
        return a ? a + (b ? ' – ' + b : '') : '';
    }
    function relative(value) {
        var d = parseDay(value), t = parseDay(state.cfg.today);
        if (!d || !t) return '';
        var days = Math.round((d - t) / 86400000);
        if (days === 0) return 'Today';
        if (days === 1) return 'Tomorrow';
        if (days === -1) return 'Yesterday';
        if (days > 1 && days < 14) return 'In ' + days + ' days';
        if (days < -1 && days > -14) return Math.abs(days) + ' days ago';
        return '';
    }
    function humanStatus(s) {
        s = String(s || 'scheduled');
        if (s === 'records_complete') return 'Completed';
        if (s === 'awaiting_secretary_records') return 'Awaiting records';
        if (s === 'completed_by_doctor') return 'Done by doctor';
        return s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ');
    }
    function statusBadge(s) {
        return '<span class="badge bg-' + (STATUS_CLASS[s] || 'secondary') + ' dp-status">' + esc(humanStatus(s)) + '</span>';
    }
    function fullName(p) {
        var mid = String(p.middle_name || '').trim();
        var mi = mid && mid.toUpperCase() !== 'N/A' ? ' ' + mid.charAt(0).toUpperCase() + '.' : '';
        return (String(p.first_name || '') + mi + ' ' + String(p.last_name || '')).trim() || 'Unknown patient';
    }
    function initials(p) {
        return ((String(p.first_name || '').charAt(0) + String(p.last_name || '').charAt(0)) || '?').toUpperCase();
    }
    function photoUrl(p) {
        var src = String(p.photo || '');
        if (!src) return '';
        return /^https?:\/\//i.test(src) ? src : '../' + src.replace(/^\/+/, '');
    }
    function avatar(p, cls) {
        var url = photoUrl(p);
        var inner = url
            ? '<img src="' + esc(url) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">'
            : '';
        return '<span class="' + cls + '" aria-hidden="true"><span>' + esc(initials(p)) + '</span>' + inner + '</span>';
    }
    function skeleton(lines) {
        var h = '<div class="dp-skeleton" aria-busy="true">';
        for (var i = 0; i < (lines || 3); i++) h += '<span style="width:' + (92 - i * 14) + '%"></span>';
        return h + '</div>';
    }
    function emptyNote(icon, text) {
        return '<div class="dp-note"><i class="bi ' + icon + '"></i><span>' + esc(text) + '</span></div>';
    }
    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok || !j || j.success === false) throw new Error((j && j.message) || 'Request failed');
                return j;
            });
        });
    }

    // ---------- per-patient derived facts ----------
    function summarize(p) {
        var today = state.cfg.today;
        var done = p.appointments.filter(function (a) { return DONE.indexOf(a.status) !== -1; });
        var upcoming = p.appointments.filter(function (a) {
            return a.date >= today && DONE.indexOf(a.status) === -1 && CLOSED.indexOf(a.status) === -1;
        }).sort(function (a, b) { return (a.date + (a.start || '')).localeCompare(b.date + (b.start || '')); });
        var last = done.slice().sort(function (a, b) { return b.date.localeCompare(a.date); })[0] || null;
        return {
            visits: done.length,
            last: last,
            next: upcoming[0] || null,
            inClinic: p.appointments.some(function (a) { return a.date === today && IN_CLINIC.indexOf(a.status) !== -1; })
        };
    }

    // ---------- list ----------
    function matches(p, q) {
        if (!q) return true;
        var hay = [p.first_name, p.middle_name, p.last_name, p.first_name + ' ' + p.last_name, p.phone_number, p.email].join(' ').toLowerCase();
        return q.split(/\s+/).every(function (term) { return hay.indexOf(term) !== -1; });
    }
    function inFilter(p, filter) {
        var s = p._sum;
        if (filter === 'upcoming') return !!s.next;
        if (filter === 'month') return !!s.last && s.last.date.slice(0, 7) === state.cfg.today.slice(0, 7);
        if (filter === 'alerts') return p.alerts.length > 0;
        return true;
    }
    function sortList(list) {
        var sort = state.sort;
        return list.sort(function (a, b) {
            if (sort === 'name_asc' || sort === 'name_desc') {
                var cmp = (a.last_name + ' ' + a.first_name).localeCompare(b.last_name + ' ' + b.first_name, undefined, { sensitivity: 'base' });
                return sort === 'name_asc' ? cmp : -cmp;
            }
            if (sort === 'next') {
                var an = a._sum.next ? a._sum.next.date : '9999', bn = b._sum.next ? b._sum.next.date : '9999';
                return an.localeCompare(bn);
            }
            if (sort === 'visits') return b._sum.visits - a._sum.visits;
            var al = a._sum.last ? a._sum.last.date : '', bl = b._sum.last ? b._sum.last.date : '';
            return bl.localeCompare(al);
        });
    }
    function rowHtml(p) {
        var s = p._sum;
        var sub = [];
        if (p.age != null) sub.push(p.age + ' yrs');
        if (p.gender) sub.push(esc(p.gender));
        if (p.phone_number) sub.push(esc(p.phone_number));
        var chips = p.alerts.slice(0, 2).map(function (a) {
            return '<span class="dp-flag dp-flag--alert"><i class="bi bi-exclamation-triangle-fill"></i>' + esc(a) + '</span>';
        });
        if (p.alerts.length > 2) chips.push('<span class="dp-flag dp-flag--alert">+' + (p.alerts.length - 2) + ' more</span>');
        if (s.inClinic) chips.unshift('<span class="dp-flag dp-flag--live"><i class="bi bi-dot"></i>In clinic today</span>');

        var nextHtml = s.next
            ? '<strong>' + esc(relative(s.next.date) || fmtDate(s.next.date, false)) + '</strong><small>' + esc(timeRange(s.next) || fmtDate(s.next.date)) + '</small>'
            : '<strong class="text-muted">—</strong><small>None booked</small>';
        var lastHtml = s.last
            ? '<strong>' + esc(fmtDate(s.last.date)) + '</strong><small>' + esc(relative(s.last.date) || (s.last.services[0] || 'Visit')) + '</small>'
            : '<strong class="text-muted">—</strong><small>No visits yet</small>';

        return '<button type="button" class="dp-row" data-patient-id="' + p.patient_id + '">' +
            avatar(p, 'dp-avatar') +
            '<span class="dp-row__main">' +
                '<span class="dp-row__name">' + esc(fullName(p)) + '</span>' +
                '<span class="dp-row__sub">' + (sub.join(' · ') || '&nbsp;') + '</span>' +
                (chips.length ? '<span class="dp-row__flags">' + chips.join('') + '</span>' : '') +
            '</span>' +
            '<span class="dp-row__stat"><small class="dp-row__label">Last visit</small>' + lastHtml + '</span>' +
            '<span class="dp-row__stat"><small class="dp-row__label">Next appointment</small>' + nextHtml + '</span>' +
            '<span class="dp-row__stat dp-row__stat--count"><small class="dp-row__label">Visits</small><strong>' + s.visits + '</strong></span>' +
            '<i class="bi bi-chevron-right dp-row__chev" aria-hidden="true"></i>' +
        '</button>';
    }
    function renderList() {
        var listEl = document.getElementById('dpList');
        var emptyEl = document.getElementById('dpEmpty');
        if (!listEl) return;
        var q = state.query.trim().toLowerCase();
        var searched = state.patients.filter(function (p) { return matches(p, q); });

        ['all', 'upcoming', 'month', 'alerts'].forEach(function (f) {
            var el = document.querySelector('[data-dp-count="' + f + '"]');
            if (el) el.textContent = searched.filter(function (p) { return inFilter(p, f); }).length;
        });

        var shown = sortList(searched.filter(function (p) { return inFilter(p, state.filter); }));
        listEl.innerHTML = shown.map(rowHtml).join('');
        if (emptyEl) {
            emptyEl.hidden = shown.length > 0;
            emptyEl.innerHTML = state.patients.length === 0
                ? emptyNote('bi-people', 'No patients yet. Patients appear here once they have an appointment with you.')
                : emptyNote('bi-search', 'No patients match your search or filter.');
        }
    }

    // ---------- chart modal ----------
    function patientById(id) {
        for (var i = 0; i < state.patients.length; i++) if (state.patients[i].patient_id === id) return state.patients[i];
        return null;
    }
    function openChart(patientId, tab) {
        var p = patientById(patientId);
        var modalEl = document.getElementById('dpChartModal');
        if (!p || !modalEl) return;
        state.current = { patient: p, loaded: {}, profiling: null, plan: null };
        renderIdentity(p);
        modalEl.querySelectorAll('.dp-pane').forEach(function (pane) {
            if (pane.getAttribute('data-dp-pane') !== 'plan') pane.innerHTML = '';
        });
        var planHost = document.getElementById('dpTreatmentPlan');
        if (planHost) planHost.innerHTML = '';
        showTab(tab || 'overview');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    function renderIdentity(p) {
        var s = p._sum;
        var meta = [];
        if (p.age != null) meta.push(p.age + ' yrs');
        if (p.gender) meta.push(esc(p.gender));
        if (p.date_of_birth) meta.push('Born ' + esc(fmtDate(p.date_of_birth)));
        var contact = [];
        if (p.phone_number) contact.push('<a href="tel:' + esc(String(p.phone_number).replace(/[^\d+]/g, '')) + '"><i class="bi bi-telephone"></i>' + esc(p.phone_number) + '</a>');
        if (p.email) contact.push('<a href="mailto:' + esc(p.email) + '"><i class="bi bi-envelope"></i>' + esc(p.email) + '</a>');

        var flags = p.alerts.map(function (a) { return '<span class="dp-flag dp-flag--alert"><i class="bi bi-exclamation-triangle-fill"></i>' + esc(a) + '</span>'; })
            .concat(p.notices.map(function (n) { return '<span class="dp-flag dp-flag--notice"><i class="bi bi-info-circle"></i>' + esc(n) + '</span>'; }));

        var stats = [
            ['Visits', String(s.visits)],
            ['Last visit', s.last ? fmtDate(s.last.date) : '—'],
            ['Next', s.next ? fmtDate(s.next.date) + (timeRange(s.next) ? ' · ' + fmtTime(s.next.start) : '') : '—']
        ];

        document.getElementById('dpChartIdentity').innerHTML =
            avatar(p, 'dp-avatar dp-avatar--lg') +
            '<div class="dp-chart__who">' +
                '<h5 class="modal-title" id="dpChartName">' + esc(fullName(p)) + '</h5>' +
                '<div class="dp-chart__meta">' + meta.join(' · ') + (contact.length ? '<span class="dp-chart__contact">' + contact.join('') + '</span>' : '') + '</div>' +
                (flags.length ? '<div class="dp-chart__flags">' + flags.join('') + '</div>' : '<div class="dp-chart__flags"><span class="dp-flag dp-flag--ok"><i class="bi bi-check-circle-fill"></i>No medical alerts on file</span></div>') +
            '</div>' +
            '<dl class="dp-chart__stats">' + stats.map(function (st) {
                return '<div><dt>' + esc(st[0]) + '</dt><dd>' + esc(st[1]) + '</dd></div>';
            }).join('') + '</dl>';
    }
    function showTab(name) {
        var modalEl = document.getElementById('dpChartModal');
        modalEl.querySelectorAll('.dp-tab').forEach(function (t) {
            var on = t.getAttribute('data-dp-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        modalEl.querySelectorAll('.dp-pane').forEach(function (pane) {
            pane.classList.toggle('is-active', pane.getAttribute('data-dp-pane') === name);
        });
        var body = modalEl.querySelector('.dp-chart__body');
        if (body) body.scrollTop = 0;
        loadTab(name);
    }
    function loadTab(name) {
        var cur = state.current;
        if (!cur || cur.loaded[name]) return;
        cur.loaded[name] = true;
        if (name === 'overview') renderOverview(cur);
        else if (name === 'visits') renderVisits(cur);
        else if (name === 'plan') mountPlan(cur);
        else if (name === 'medical') renderMedical(cur);
        else if (name === 'photos') renderPhotos(cur);
    }
    function pane(name) {
        return document.querySelector('#dpChartModal [data-dp-pane="' + name + '"]');
    }
    function stillCurrent(cur) {
        return state.current === cur;
    }

    // Data loaders, cached per chart opening.
    function loadProfiling(cur) {
        if (!cur.profilingPromise) {
            cur.profilingPromise = getJson(API + 'patients/get_profiling.php?patient_id=' + encodeURIComponent(cur.patient.patient_id));
        }
        return cur.profilingPromise;
    }
    function loadPlan(cur) {
        if (!cur.planPromise) {
            cur.planPromise = getJson(API + 'treatment_plan/list.php?patient_id=' + encodeURIComponent(cur.patient.patient_id));
        }
        return cur.planPromise;
    }
    function loadVisit(appointmentId) {
        if (!state.visitCache[appointmentId]) {
            state.visitCache[appointmentId] = getJson(API + 'ehr/get_records_by_appointment_detailed.php?appointment_id=' + encodeURIComponent(appointmentId))
                .catch(function (err) { delete state.visitCache[appointmentId]; throw err; });
        }
        return state.visitCache[appointmentId];
    }

    // ---- Overview ----
    function renderOverview(cur) {
        var el = pane('overview');
        var p = cur.patient, s = p._sum;
        var nextCard = s.next
            ? '<div class="dp-kv"><strong>' + esc(fmtDate(s.next.date)) + '</strong>' + (relative(s.next.date) ? ' <span class="dp-pill">' + esc(relative(s.next.date)) + '</span>' : '') + '</div>' +
              '<div class="dp-muted">' + esc(timeRange(s.next) || 'Time to be assigned') + ' · ' + (s.next.is_online ? 'Online' : 'In-person') + '</div>' +
              (s.next.services.length ? '<div class="dp-tags">' + s.next.services.map(function (n) { return '<span class="dp-tag">' + esc(n) + '</span>'; }).join('') + '</div>' : '') +
              '<div class="mt-2">' + statusBadge(s.next.status) + '</div>'
            : emptyNote('bi-calendar-x', 'No upcoming appointment with you.');
        var lastCard = s.last
            ? '<div class="dp-kv"><strong>' + esc(fmtDate(s.last.date)) + '</strong>' + (relative(s.last.date) ? ' <span class="dp-pill">' + esc(relative(s.last.date)) + '</span>' : '') + '</div>' +
              (s.last.services.length ? '<div class="dp-tags">' + s.last.services.map(function (n) { return '<span class="dp-tag">' + esc(n) + '</span>'; }).join('') + '</div>' : '') +
              '<div class="dp-last-detail" id="dpLastVisitDetail">' + (s.last.record_id ? skeleton(2) : '<div class="dp-muted">No clinical record for this visit.</div>') + '</div>' +
              (s.last.record_id ? '<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-dp-open-visit="' + s.last.appointment_id + '"><i class="bi bi-journal-text"></i> Open full record</button>' : '')
            : emptyNote('bi-clock-history', 'No completed visits yet.');

        el.innerHTML =
            '<div class="dp-grid">' +
                '<article class="dp-card"><h6 class="dp-card__title"><i class="bi bi-calendar-event"></i> Next appointment</h6>' + nextCard + '</article>' +
                '<article class="dp-card"><h6 class="dp-card__title"><i class="bi bi-clock-history"></i> Last visit</h6>' + lastCard + '</article>' +
                '<article class="dp-card"><h6 class="dp-card__title"><i class="bi bi-list-check"></i> Treatment plan</h6><div id="dpOverviewPlan">' + skeleton(2) + '</div></article>' +
                '<article class="dp-card"><h6 class="dp-card__title"><i class="bi bi-heart-pulse"></i> Medical summary</h6><div id="dpOverviewMedical">' + skeleton(3) + '</div></article>' +
            '</div>';

        if (s.last && s.last.record_id) {
            loadVisit(s.last.appointment_id).then(function (j) {
                if (!stillCurrent(cur)) return;
                var box = document.getElementById('dpLastVisitDetail');
                if (!box) return;
                var rec = j.record || {};
                var findings = toothFindingList(rec.odontogram);
                var bits = [];
                if (findings.length) bits.push('<div><span class="dp-muted">Teeth:</span> ' + findings.slice(0, 4).map(function (f) { return '<span class="dp-tag dp-tag--tooth">#' + esc(f.tooth) + ' ' + esc(f.text) + '</span>'; }).join('') + (findings.length > 4 ? ' <span class="dp-muted">+' + (findings.length - 4) + ' more</span>' : '') + '</div>');
                if (meaningful(rec.doctor_notes)) bits.push('<div class="dp-quote">' + esc(rec.doctor_notes) + '</div>');
                if ((j.prescriptions || []).length) bits.push('<div><span class="dp-muted">Prescribed:</span> ' + j.prescriptions.map(function (rx) { return esc(rx.medicine_name) + (rx.dosage ? ' ' + esc(rx.dosage) : ''); }).join(', ') + '</div>');
                box.innerHTML = bits.join('') || '<div class="dp-muted">No findings or notes recorded.</div>';
            }).catch(function () {
                var box = document.getElementById('dpLastVisitDetail');
                if (box && stillCurrent(cur)) box.innerHTML = '<div class="dp-muted">Could not load the visit record.</div>';
            });
        }

        loadPlan(cur).then(function (j) {
            if (!stillCurrent(cur)) return;
            var box = document.getElementById('dpOverviewPlan');
            if (!box) return;
            var items = j.items || [];
            var open = items.filter(function (i) { return i.status === 'proposed' || i.status === 'accepted'; });
            var doneCount = items.filter(function (i) { return i.status === 'completed'; }).length;
            if (!items.length) { box.innerHTML = '<div class="dp-muted">No treatment plan yet.</div><button type="button" class="btn btn-sm btn-outline-primary mt-2" data-dp-goto="plan"><i class="bi bi-plus-lg"></i> Start a plan</button>'; return; }
            var total = open.reduce(function (sum, i) { return sum + Number(i.estimated_cost || 0); }, 0);
            box.innerHTML =
                '<div class="dp-kv"><strong>' + open.length + ' pending</strong> · ' + doneCount + ' done</div>' +
                (open.length ? '<ul class="dp-mini-list">' + open.slice(0, 4).map(function (i) {
                    return '<li><span>' + esc(i.service_name || 'Service') + (i.tooth_number ? ' <span class="dp-muted">#' + esc(i.tooth_number) + '</span>' : '') + '</span><span class="dp-pill dp-pill--' + esc(i.status) + '">' + esc(i.status) + '</span></li>';
                }).join('') + '</ul>' : '') +
                (open.length ? '<div class="dp-muted">Remaining estimate: ₱' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' : '') +
                '<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-dp-goto="plan">Open treatment plan</button>';
        }).catch(function () {
            var box = document.getElementById('dpOverviewPlan');
            if (box && stillCurrent(cur)) box.innerHTML = '<div class="dp-muted">Could not load the treatment plan.</div>';
        });

        loadProfiling(cur).then(function (j) {
            if (!stillCurrent(cur)) return;
            var box = document.getElementById('dpOverviewMedical');
            if (!box) return;
            var prof = j.profiling || {};
            var rows = [
                ['Allergies', prof.allergies], ['Medications', prof.medications], ['Medical history', prof.medical_history],
                ['Dental anxiety', prof.dental_anxiety], ['Smoking', prof.smoking]
            ].filter(function (r) { return meaningful(r[1]); });
            var alerts = p.alerts.length
                ? '<div class="dp-flags-block">' + p.alerts.map(function (a) { return '<span class="dp-flag dp-flag--alert"><i class="bi bi-exclamation-triangle-fill"></i>' + esc(a) + '</span>'; }).join('') + '</div>'
                : '<div class="dp-flag dp-flag--ok mb-2"><i class="bi bi-check-circle-fill"></i>No flagged conditions</div>';
            box.innerHTML = alerts +
                (rows.length ? '<dl class="dp-dl">' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' : '<div class="dp-muted">No medical history details on file.</div>') +
                '<button type="button" class="btn btn-sm btn-outline-primary mt-2" data-dp-goto="medical">Full medical profile</button>';
        }).catch(function () {
            var box = document.getElementById('dpOverviewMedical');
            if (box && stillCurrent(cur)) box.innerHTML = '<div class="dp-muted">Could not load the medical profile.</div>';
        });
    }

    // ---- Visits & Records ----
    function renderVisits(cur) {
        var el = pane('visits');
        var appts = cur.patient.appointments.slice().sort(function (a, b) {
            return (b.date + (b.start || '')).localeCompare(a.date + (a.start || ''));
        });
        if (!appts.length) { el.innerHTML = emptyNote('bi-journal-x', 'No appointments with this patient yet.'); return; }
        el.innerHTML = '<ol class="dp-timeline">' + appts.map(function (a) {
            var future = a.date >= state.cfg.today && DONE.indexOf(a.status) === -1;
            return '<li class="dp-visit' + (future ? ' is-future' : '') + '" data-appointment-id="' + a.appointment_id + '">' +
                '<button type="button" class="dp-visit__head" aria-expanded="false" data-dp-visit="' + a.appointment_id + '">' +
                    '<span class="dp-visit__date"><strong>' + esc(fmtDate(a.date, false)) + '</strong><small>' + esc(a.date.slice(0, 4)) + '</small></span>' +
                    '<span class="dp-visit__main">' +
                        '<span class="dp-visit__title">' + esc(a.services.length ? a.services.join(', ') : (meaningful(a.reason) ? a.reason : 'Appointment')) + '</span>' +
                        '<span class="dp-visit__sub">' + esc([timeRange(a), a.is_online ? 'Online' : 'In-person', a.record_id ? 'Clinical record' : ''].filter(Boolean).join(' · ')) + '</span>' +
                    '</span>' +
                    statusBadge(a.status) +
                    '<i class="bi bi-chevron-down dp-visit__chev" aria-hidden="true"></i>' +
                '</button>' +
                '<div class="dp-visit__body" hidden></div>' +
            '</li>';
        }).join('') + '</ol>';
    }
    function toggleVisit(appointmentId, forceOpen) {
        var item = document.querySelector('#dpChartModal .dp-visit[data-appointment-id="' + appointmentId + '"]');
        if (!item) return;
        var head = item.querySelector('.dp-visit__head');
        var body = item.querySelector('.dp-visit__body');
        var open = forceOpen === true ? true : body.hidden;
        body.hidden = !open;
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
        item.classList.toggle('is-open', open);
        if (!open || body.getAttribute('data-loaded')) return;
        body.setAttribute('data-loaded', '1');

        var appt = null;
        state.current.patient.appointments.forEach(function (a) { if (a.appointment_id === appointmentId) appt = a; });
        if (!appt || !appt.record_id) {
            body.innerHTML = visitBasics(appt) + emptyNote('bi-journal', appt && appt.date >= state.cfg.today ? 'This visit has not happened yet.' : 'No clinical record was saved for this visit.');
            return;
        }
        body.innerHTML = skeleton(4);
        var cur = state.current;
        loadVisit(appointmentId).then(function (j) {
            if (stillCurrent(cur)) body.innerHTML = visitBasics(appt) + visitDetail(j);
        }).catch(function () {
            if (!stillCurrent(cur)) return;
            body.removeAttribute('data-loaded');
            body.innerHTML = emptyNote('bi-wifi-off', 'Could not load this record. Close and reopen the visit to retry.');
        });
    }
    function visitBasics(appt) {
        if (!appt) return '';
        var bits = [];
        if (meaningful(appt.reason)) bits.push('<div><span class="dp-muted">Reason:</span> ' + esc(appt.reason) + '</div>');
        if (meaningful(appt.notes)) bits.push('<div><span class="dp-muted">Booking notes:</span> ' + esc(appt.notes) + '</div>');
        return bits.length ? '<div class="dp-block">' + bits.join('') + '</div>' : '';
    }
    function toothFindingList(odontogram) {
        var out = [];
        var labels = state.cfg.toothLabels || {};
        Object.keys(odontogram || {}).sort(function (a, b) { return Number(a) - Number(b); }).forEach(function (tooth) {
            var t = odontogram[tooth] || {};
            var parts = [];
            Object.keys(t.surfaces || {}).forEach(function (surface) {
                var codes = (t.surfaces[surface] || []).map(function (c) { return labels[c] || c; });
                if (codes.length) parts.push(codes.join(', ') + (SURFACE_NAMES[surface] ? ' (' + SURFACE_NAMES[surface] + ')' : ' (' + surface + ')'));
            });
            if (parts.length || meaningful(t.comment)) out.push({ tooth: tooth, text: parts.join('; '), comment: t.comment || '' });
        });
        return out;
    }
    function section(title, icon, html) {
        return '<div class="dp-block"><h6 class="dp-block__title"><i class="bi ' + icon + '"></i> ' + esc(title) + '</h6>' + html + '</div>';
    }
    function visitDetail(j) {
        var rec = j.record || {};
        var html = '';

        var meta = [];
        if (j.record_status) meta.push('Record: <strong>' + esc(humanStatus(j.record_status)) + '</strong>');
        if (rec.submitted_at) meta.push('Saved ' + esc(fmtDate(rec.submitted_at)));
        if (rec.follow_up_date) meta.push('Follow-up: <strong>' + esc(fmtDate(rec.follow_up_date)) + '</strong>');
        if (meta.length) html += '<div class="dp-meta-line">' + meta.join(' · ') + '</div>';

        var services = (j.services_availed || []).map(function (s) { return s.description; }).filter(Boolean);
        if (services.length) html += section('Services done', 'bi-clipboard2-check', '<div class="dp-tags">' + services.map(function (n) { return '<span class="dp-tag">' + esc(n) + '</span>'; }).join('') + '</div>');

        var teeth = toothFindingList(rec.odontogram);
        if (teeth.length) {
            html += section('Tooth findings', 'bi-grid-3x3-gap', '<div class="table-responsive"><table class="table table-sm dp-table mb-0"><thead><tr><th>Tooth</th><th>Findings</th><th>Note</th></tr></thead><tbody>' +
                teeth.map(function (t) { return '<tr><td><strong>#' + esc(t.tooth) + '</strong></td><td>' + esc(t.text || '—') + '</td><td>' + esc(t.comment || '') + '</td></tr>'; }).join('') +
                '</tbody></table></div>');
        }

        var cf = rec.clinical_findings || {};
        var cfRows = Object.keys(FINDING_LABELS).filter(function (k) { return meaningful(cf[k]); });
        if (cfRows.length) html += section('Clinical findings', 'bi-clipboard2-pulse', '<dl class="dp-dl dp-dl--grid">' + cfRows.map(function (k) { return '<div><dt>' + esc(FINDING_LABELS[k]) + '</dt><dd>' + esc(cf[k]) + '</dd></div>'; }).join('') + '</dl>');

        var notes = [];
        if (meaningful(rec.summary)) notes.push('<div class="dp-quote">' + esc(rec.summary) + '</div>');
        if (meaningful(rec.doctor_notes)) notes.push('<div class="dp-quote">' + esc(rec.doctor_notes) + '</div>');
        if (notes.length) html += section('Doctor notes', 'bi-pencil-square', notes.join(''));

        var rx = j.prescriptions || [];
        if (rx.length) {
            html += section('Prescriptions', 'bi-capsule', '<div class="table-responsive"><table class="table table-sm dp-table mb-0"><thead><tr><th>Medicine</th><th>Dosage</th><th>Duration</th><th>Instructions</th></tr></thead><tbody>' +
                rx.map(function (r) { return '<tr><td><strong>' + esc(r.medicine_name) + '</strong>' + (meaningful(r.medicine_brand) ? '<div class="dp-muted">' + esc(r.medicine_brand) + '</div>' : '') + '</td><td>' + esc(r.dosage || '') + '</td><td>' + esc(r.duration || '') + '</td><td>' + esc(r.notes || '') + '</td></tr>'; }).join('') +
                '</tbody></table></div>');
        }

        var mats = rec.materials_used || [];
        if (mats.length) html += section('Materials used', 'bi-box-seam', '<ul class="dp-mini-list">' + mats.map(function (m) {
            return '<li><span>' + esc(m.material_name || 'Material') + '</span><span class="dp-muted">' + esc([m.quantity, m.unit].filter(function (v) { return v != null && v !== ''; }).join(' ')) + '</span></li>';
        }).join('') + '</ul>');

        var imgs = (rec.images || []).filter(Boolean);
        if (imgs.length) html += section('Photos', 'bi-images', photoGrid(imgs.map(function (src) { return { src: src, caption: fmtDate(j.record && j.record._appointment_date || '') }; })));

        return html || emptyNote('bi-journal', 'The record has no findings, notes, or prescriptions.');
    }

    // ---- Treatment plan ----
    function mountPlan(cur) {
        var host = document.getElementById('dpTreatmentPlan');
        if (!host) return;
        if (!global.TreatmentPlan) { host.innerHTML = emptyNote('bi-exclamation-circle', 'The treatment plan tool did not load. Refresh the page.'); return; }
        global.TreatmentPlan.mount(host, {
            patientId: cur.patient.patient_id,
            appointmentId: 0,
            role: 'doctor',
            csrfToken: state.cfg.csrfToken || global.doctorCsrfToken || '',
            services: state.cfg.services || []
        });
    }

    // ---- Medical ----
    function renderMedical(cur) {
        var el = pane('medical');
        el.innerHTML = skeleton(5);
        loadProfiling(cur).then(function (j) {
            if (!stillCurrent(cur)) return;
            el.innerHTML = typeof global.renderDoctorPatientProfile === 'function'
                ? global.renderDoctorPatientProfile(j.patient || {}, j.profiling || {}, fullName(cur.patient), { showHeader: false })
                : emptyNote('bi-exclamation-circle', 'Unable to display the medical profile.');
        }).catch(function () {
            if (stillCurrent(cur)) el.innerHTML = emptyNote('bi-wifi-off', 'Could not load the medical profile.');
        });
    }

    // ---- Photos ----
    function photoGrid(items) {
        return '<div class="dp-photos">' + items.map(function (it) {
            var url = /^https?:\/\//i.test(it.src) ? it.src : '../' + String(it.src).replace(/^\/+/, '');
            return '<button type="button" class="dp-photo" data-dp-photo="' + esc(url) + '" title="' + esc(it.caption || '') + '">' +
                '<img src="' + esc(url) + '" alt="' + esc(it.caption ? 'Photo from ' + it.caption : 'Clinical photo') + '" loading="lazy">' +
                (it.caption ? '<span>' + esc(it.caption) + '</span>' : '') +
            '</button>';
        }).join('') + '</div>';
    }
    function renderPhotos(cur) {
        var el = pane('photos');
        var withRecords = cur.patient.appointments.filter(function (a) { return a.record_id; })
            .sort(function (a, b) { return b.date.localeCompare(a.date); });
        if (!withRecords.length) { el.innerHTML = emptyNote('bi-image', 'No clinical photos yet.'); return; }
        el.innerHTML = skeleton(3);
        Promise.all(withRecords.map(function (a) {
            return loadVisit(a.appointment_id).then(function (j) { return { appt: a, images: ((j.record || {}).images || []).filter(Boolean) }; })
                .catch(function () { return { appt: a, images: [], failed: true }; });
        })).then(function (groups) {
            if (!stillCurrent(cur)) return;
            var html = '';
            var failed = 0;
            groups.forEach(function (g) {
                if (g.failed) failed++;
                if (!g.images.length) return;
                html += '<div class="dp-block"><h6 class="dp-block__title"><i class="bi bi-calendar3"></i> ' + esc(fmtDate(g.appt.date)) +
                    (g.appt.services.length ? ' <span class="dp-muted">· ' + esc(g.appt.services.join(', ')) + '</span>' : '') + '</h6>' +
                    photoGrid(g.images.map(function (src) { return { src: src, caption: fmtDate(g.appt.date) }; })) + '</div>';
            });
            el.innerHTML = (html || emptyNote('bi-image', 'No clinical photos yet.')) +
                (failed ? '<div class="dp-muted mt-2">' + failed + ' visit(s) could not be loaded.</div>' : '');
        });
    }

    // ---------- wiring ----------
    function bind() {
        var search = document.getElementById('dpSearch');
        if (search) {
            search.value = state.query;
            search.addEventListener('input', function () { state.query = search.value; renderList(); });
        }
        var sort = document.getElementById('dpSort');
        if (sort) sort.addEventListener('change', function () { state.sort = sort.value; renderList(); });

        var chips = document.getElementById('dpChips');
        if (chips) chips.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-dp-filter]');
            if (!btn) return;
            state.filter = btn.getAttribute('data-dp-filter');
            chips.querySelectorAll('[data-dp-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            renderList();
        });

        var list = document.getElementById('dpList');
        if (list) list.addEventListener('click', function (e) {
            var row = e.target.closest('.dp-row');
            if (row) openChart(Number(row.getAttribute('data-patient-id')));
        });

        var modalEl = document.getElementById('dpChartModal');
        if (modalEl) {
            modalEl.addEventListener('click', function (e) {
                var tab = e.target.closest('[data-dp-tab]');
                if (tab) { showTab(tab.getAttribute('data-dp-tab')); return; }
                var go = e.target.closest('[data-dp-goto]');
                if (go) { showTab(go.getAttribute('data-dp-goto')); return; }
                var openVisit = e.target.closest('[data-dp-open-visit]');
                if (openVisit) {
                    var id = Number(openVisit.getAttribute('data-dp-open-visit'));
                    showTab('visits');
                    toggleVisit(id, true);
                    var item = modalEl.querySelector('.dp-visit[data-appointment-id="' + id + '"]');
                    if (item) item.scrollIntoView({ block: 'nearest' });
                    return;
                }
                var visit = e.target.closest('[data-dp-visit]');
                if (visit) { toggleVisit(Number(visit.getAttribute('data-dp-visit'))); return; }
                var photo = e.target.closest('[data-dp-photo]');
                if (photo && typeof global.openMediaLightbox === 'function') {
                    global.openMediaLightbox(photo.getAttribute('data-dp-photo'), 'clinical-photo');
                }
            });
            // Arrow keys move between tabs, as expected for a tablist.
            modalEl.addEventListener('keydown', function (e) {
                var tab = e.target.closest('.dp-tab');
                if (!tab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
                var tabs = Array.prototype.slice.call(modalEl.querySelectorAll('.dp-tab'));
                var i = tabs.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : -1);
                var next = tabs[(i + tabs.length) % tabs.length];
                next.focus();
                showTab(next.getAttribute('data-dp-tab'));
            });
            modalEl.addEventListener('hidden.bs.modal', function () { state.current = null; });
        }
    }

    function init(cfg) {
        var patients = (cfg && cfg.patients) || [];
        state = {
            cfg: cfg || {},
            patients: patients,
            filter: 'all',
            sort: 'recent',
            query: String((cfg && cfg.query) || ''),
            current: null,
            visitCache: {}
        };
        patients.forEach(function (p) { p._sum = summarize(p); });
        bind();
        renderList();
        if (cfg.openPatientId && patientById(Number(cfg.openPatientId))) openChart(Number(cfg.openPatientId));
    }

    global.DoctorPatients = { init: init, open: function (id, tab) { if (state) openChart(Number(id), tab); } };
})(window);
