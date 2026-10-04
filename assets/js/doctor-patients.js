/**
 * Patients page (doctor "My Patients", secretary and admin "Patients"): a searchable patient
 * list and a tabbed Patient Chart that replaces the list in place (Back returns to it), so
 * the chart gets the full page.
 *
 *   DoctorPatients.init(config)   — config is built by includes/patients_module.php
 *
 * config.role is 'doctor', 'secretary' or 'admin'. Doctors see their own patients; staff see
 * every patient, with the doctor shown on each appointment. When config.canManage is set, a
 * settings button next to the patient's name opens assets/js/patient-account.js.
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
    var documentBound = false;

    function isDoctor() { return state.cfg.role === 'doctor' || !state.cfg.role; }

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
    // Staff see which doctor each appointment is with; a doctor only sees their own.
    function doctorLabel(a) {
        if (isDoctor() || !a || !String(a.doctor || '').trim()) return '';
        var name = String(a.doctor).trim();
        return /^dr\.?\s/i.test(name) ? name : 'Dr. ' + name;
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
            if (sort === 'newest') return String(b.created_at || '').localeCompare(String(a.created_at || ''));
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
        // Medical alerts (diabetic, allergies, ...) stay out of the list and show only
        // once the doctor opens the patient's chart.
        var chips = [];
        if (s.inClinic) chips.push('<span class="dp-flag dp-flag--live"><i class="bi bi-dot"></i>In clinic today</span>');

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
                ? emptyNote('bi-people', isDoctor() ? 'No patients yet. Patients appear here once they have an appointment with you.' : 'No patients registered yet. Use Add Patient to create one.')
                : emptyNote('bi-search', 'No patients match your search or filter.');
        }
    }

    // ---------- chart view ----------
    function patientById(id) {
        for (var i = 0; i < state.patients.length; i++) if (state.patients[i].patient_id === id) return state.patients[i];
        return null;
    }
    function chartEl() {
        return document.getElementById('dpChartView');
    }
    function scrollToTop() {
        try { window.scrollTo({ top: 0, behavior: 'auto' }); } catch (e) { window.scrollTo(0, 0); }
    }
    function openChart(patientId, tab) {
        var p = patientById(patientId);
        var view = chartEl();
        var listView = document.getElementById('dpListView');
        if (!p || !view) return;
        state.current = { patient: p, loaded: {}, profiling: null, plan: null };
        // Remember where the doctor was in the list so Back returns to the same spot.
        if (listView && !listView.hidden) state.listScroll = window.scrollY || 0;
        renderIdentity(p);
        view.querySelectorAll('.dp-pane').forEach(function (pane) {
            // Treatments and Charting hold static hosts (the plan widget, the chart iframe).
            var name = pane.getAttribute('data-dp-pane');
            if (name !== 'plan' && name !== 'charting') pane.innerHTML = '';
        });
        var planHost = document.getElementById('dpTreatmentPlan');
        if (planHost) planHost.innerHTML = '';
        if (listView) listView.hidden = true;
        view.hidden = false;
        showTab(tab || 'overview');
        scrollToTop();
        var back = view.querySelector('[data-dp-back]');
        if (back) back.focus({ preventScroll: true });
    }
    function closeChart() {
        var view = chartEl();
        var listView = document.getElementById('dpListView');
        if (!view || view.hidden) return;
        var id = state.current ? state.current.patient.patient_id : null;
        toggleChartFullscreen(true);
        state.current = null;
        view.hidden = true;
        if (listView) listView.hidden = false;
        window.scrollTo(0, state.listScroll || 0);
        var row = id && document.querySelector('#dpList .dp-row[data-patient-id="' + id + '"]');
        if (row) row.focus({ preventScroll: true });
    }
    function renderIdentity(p) {
        var meta = [];
        if (p.age != null) meta.push(p.age + ' yrs');
        if (p.gender) meta.push(esc(p.gender));
        if (p.date_of_birth) meta.push('Born ' + esc(fmtDate(p.date_of_birth)));
        var contact = [];
        if (p.phone_number) contact.push('<a href="tel:' + esc(String(p.phone_number).replace(/[^\d+]/g, '')) + '"><i class="bi bi-telephone"></i>' + esc(p.phone_number) + '</a>');
        if (p.email) contact.push('<a href="mailto:' + esc(p.email) + '"><i class="bi bi-envelope"></i>' + esc(p.email) + '</a>');

        var flags = p.alerts.map(function (a) { return '<span class="dp-flag dp-flag--alert"><i class="bi bi-exclamation-triangle-fill"></i>' + esc(a) + '</span>'; })
            .concat(p.notices.map(function (n) { return '<span class="dp-flag dp-flag--notice"><i class="bi bi-info-circle"></i>' + esc(n) + '</span>'; }));

        document.getElementById('dpChartIdentity').innerHTML =
            avatar(p, 'dp-avatar dp-avatar--lg') +
            '<div class="dp-chart__who">' +
                '<div class="dp-chart__title-row"><h3 class="dp-chart__name" id="dpChartName">' + esc(fullName(p)) + '</h3>' +
                    (state.cfg.canManage ? '<button type="button" class="dp-settings-btn" data-pa-settings="' + p.patient_id + '" aria-label="Patient settings" title="Edit patient details and account"><i class="bi bi-gear"></i></button>' : '') +
                '</div>' +
                '<div class="dp-chart__meta">' + meta.join(' · ') + (contact.length ? '<span class="dp-chart__contact">' + contact.join('') + '</span>' : '') + '</div>' +
                (flags.length ? '<div class="dp-chart__flags">' + flags.join('') + '</div>' : '<div class="dp-chart__flags"><span class="dp-flag dp-flag--ok"><i class="bi bi-check-circle-fill"></i>No medical alerts on file</span></div>') +
            '</div>';
    }
    function showTab(name) {
        var view = chartEl();
        view.querySelectorAll('.dp-tab').forEach(function (t) {
            var on = t.getAttribute('data-dp-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        view.querySelectorAll('.dp-pane').forEach(function (pane) {
            pane.classList.toggle('is-active', pane.getAttribute('data-dp-pane') === name);
        });
        loadTab(name);
        if (name === 'charting') postToChartFrame({ type: 'odontogram_resize' });
    }
    function loadTab(name) {
        var cur = state.current;
        if (!cur || cur.loaded[name]) return;
        cur.loaded[name] = true;
        if (name === 'overview') renderOverview(cur);
        else if (name === 'appointments') renderAppointments(cur);
        else if (name === 'records') renderRecords(cur);
        else if (name === 'charting') renderCharting(cur);
        else if (name === 'plan') mountPlan(cur);
        else if (name === 'profiling') renderProfiling(cur);
        else if (name === 'photos') renderPhotos(cur);
    }
    function pane(name) {
        return document.querySelector('#dpChartView [data-dp-pane="' + name + '"]');
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
    // Records newest first (only visits that have a clinical record).
    function recordsOf(p) {
        return p.appointments.filter(function (a) { return a.record_id; }).sort(function (a, b) {
            return (b.date + (b.start || '')).localeCompare(a.date + (a.start || ''));
        });
    }
    function peso(v) {
        return '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function statTile(goto, icon, label, valueHtml, subHtml, extraCls) {
        return '<button type="button" class="dp-stat' + (extraCls ? ' ' + extraCls : '') + '" data-dp-goto="' + goto + '">' +
            '<span class="dp-stat__icon"><i class="bi ' + icon + '"></i></span>' +
            '<span class="dp-stat__body"><span class="dp-stat__label">' + esc(label) + '</span>' +
            '<strong class="dp-stat__value">' + valueHtml + '</strong>' +
            '<span class="dp-stat__sub">' + subHtml + '</span></span>' +
        '</button>';
    }
    function cardHead(icon, title, actionHtml) {
        return '<div class="dp-card__head"><h6 class="dp-card__title"><i class="bi ' + icon + '"></i> ' + esc(title) + '</h6>' + (actionHtml || '') + '</div>';
    }
    function linkBtn(attr, label) {
        return '<button type="button" class="dp-link" ' + attr + '>' + esc(label) + ' <i class="bi bi-arrow-right"></i></button>';
    }
    function renderOverview(cur) {
        var el = pane('overview');
        var p = cur.patient, s = p._sum;
        var latest = recordsOf(p)[0] || null;

        var stats =
            statTile('appointments', 'bi-check2-circle', 'Completed visits', esc(String(s.visits)),
                esc(s.visits ? recordsOf(p).length + ' with clinical records' : 'No visits yet')) +
            statTile('records', 'bi-clock-history', 'Last visit', esc(s.last ? fmtDate(s.last.date) : '—'),
                esc(s.last ? (relative(s.last.date) || (s.last.services[0] || 'Visit')) : 'No visits yet')) +
            statTile('appointments', 'bi-calendar-event', 'Next appointment', esc(s.next ? fmtDate(s.next.date) : '—'),
                esc(s.next ? [relative(s.next.date), fmtTime(s.next.start)].filter(Boolean).join(' · ') || 'Time to be assigned' : 'None booked'),
                s.next ? 'is-accent' : '') +
            statTile('plan', 'bi-list-check', 'Open treatments', '<span id="dpStatPlan">…</span>', '<span id="dpStatPlanSub">Loading</span>');

        var next = s.next
            ? '<div class="dp-next">' +
                '<div class="dp-next__date"><strong>' + esc(fmtDate(s.next.date, false)) + '</strong><small>' + esc(s.next.date.slice(0, 4)) + '</small></div>' +
                '<div class="dp-next__main">' +
                    '<div class="dp-next__title">' + esc(s.next.services.length ? s.next.services.join(', ') : (meaningful(s.next.reason) ? s.next.reason : 'Appointment')) + '</div>' +
                    '<div class="dp-muted">' + esc([timeRange(s.next) || 'Time to be assigned', s.next.is_online ? 'Online' : 'In-person', doctorLabel(s.next)].filter(Boolean).join(' · ')) + '</div>' +
                    '<div class="mt-1">' + statusBadge(s.next.status) + '</div>' +
                '</div>' +
              '</div>'
            : emptyNote('bi-calendar-x', isDoctor() ? 'No upcoming appointment with you.' : 'No upcoming appointment.');

        el.innerHTML =
            '<div class="dp-stats">' + stats + '</div>' +
            '<div class="dp-ov">' +
                '<article class="dp-card dp-ov__main">' +
                    cardHead('bi-journal-medical', 'Latest clinical record', latest ? linkBtn('data-dp-open-record="' + latest.appointment_id + '"', 'Open record') : '') +
                    '<div id="dpOvRecord">' + (latest ? skeleton(4) : emptyNote('bi-journal-x', 'No clinical records yet.')) + '</div>' +
                '</article>' +
                '<div class="dp-ov__side">' +
                    '<article class="dp-card">' + cardHead('bi-calendar-event', 'Up next', linkBtn('data-dp-goto="appointments"', 'All appointments')) + next + '</article>' +
                    '<article class="dp-card">' + cardHead('bi-list-check', 'Pending treatments', linkBtn('data-dp-goto="plan"', 'Manage')) + '<div id="dpOvPlan">' + skeleton(3) + '</div></article>' +
                    '<article class="dp-card">' + cardHead('bi-heart-pulse', 'Health at a glance', linkBtn('data-dp-goto="profiling"', 'Profiling')) + '<div id="dpOvHealth">' + skeleton(3) + '</div></article>' +
                '</div>' +
            '</div>';

        if (latest) {
            loadVisit(latest.appointment_id).then(function (j) {
                if (!stillCurrent(cur)) return;
                var box = document.getElementById('dpOvRecord');
                if (box) box.innerHTML = recordSummary(latest, j);
            }).catch(function () {
                var box = document.getElementById('dpOvRecord');
                if (box && stillCurrent(cur)) box.innerHTML = emptyNote('bi-wifi-off', 'Could not load the latest record.');
            });
        }

        loadPlan(cur).then(function (j) {
            if (!stillCurrent(cur)) return;
            var items = j.items || [];
            var open = items.filter(function (i) { return i.status === 'proposed' || i.status === 'accepted'; });
            var remaining = open.reduce(function (sum, i) { return sum + Number(i.estimated_cost || 0); }, 0);
            var statEl = document.getElementById('dpStatPlan');
            var statSub = document.getElementById('dpStatPlanSub');
            if (statEl) statEl.textContent = String(open.length);
            if (statSub) statSub.textContent = open.length ? peso(remaining) + ' remaining' : (items.length ? 'All done' : 'No plan yet');
            var box = document.getElementById('dpOvPlan');
            if (!box) return;
            if (!open.length) {
                box.innerHTML = emptyNote('bi-clipboard-check', items.length ? 'Nothing pending. All planned treatments are done or declined.' : 'No treatment plan yet.');
                return;
            }
            box.innerHTML = '<ul class="dp-plan-list">' + open.slice(0, 5).map(function (i) {
                return '<li>' +
                    '<span class="dp-plan-list__tooth" title="' + (i.tooth_number ? 'Tooth ' + esc(i.tooth_number) : 'Whole mouth') + '">' + (i.tooth_number ? esc(i.tooth_number) : '<i class="bi bi-circle"></i>') + '</span>' +
                    '<span class="dp-plan-list__name">' + esc(i.service_name || 'Treatment') + '</span>' +
                    '<span class="dp-pill dp-pill--' + esc(i.status) + '">' + esc(i.status) + '</span>' +
                '</li>';
            }).join('') + '</ul>' +
            (open.length > 5 ? '<div class="dp-muted mt-1">+' + (open.length - 5) + ' more</div>' : '');
        }).catch(function () {
            var statEl = document.getElementById('dpStatPlan');
            if (statEl && stillCurrent(cur)) { statEl.textContent = '—'; document.getElementById('dpStatPlanSub').textContent = 'Unavailable'; }
            var box = document.getElementById('dpOvPlan');
            if (box && stillCurrent(cur)) box.innerHTML = emptyNote('bi-wifi-off', 'Could not load the treatment plan.');
        });

        loadProfiling(cur).then(function (j) {
            if (!stillCurrent(cur)) return;
            var box = document.getElementById('dpOvHealth');
            if (!box) return;
            var prof = j.profiling || {};
            var rows = [
                ['Allergies', prof.allergies], ['Medications', prof.medications], ['Medical history', prof.medical_history]
            ].filter(function (r) { return meaningful(r[1]); });
            box.innerHTML = (p.alerts.length
                    ? '<div class="dp-flags-block">' + p.alerts.map(function (a) { return '<span class="dp-flag dp-flag--alert"><i class="bi bi-exclamation-triangle-fill"></i>' + esc(a) + '</span>'; }).join('') + '</div>'
                    : '<div class="dp-flag dp-flag--ok mb-2"><i class="bi bi-check-circle-fill"></i>No flagged conditions</div>') +
                (rows.length ? '<dl class="dp-dl">' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' : '');
        }).catch(function () {
            var box = document.getElementById('dpOvHealth');
            if (box && stillCurrent(cur)) box.innerHTML = emptyNote('bi-wifi-off', 'Could not load the profiling.');
        });
    }
    // Compact digest of one record for the Overview card.
    function recordSummary(appt, j) {
        var rec = j.record || {};
        var rows = [];
        var head = '<div class="dp-rec-sum__date"><strong>' + esc(fmtDate(appt.date)) + '</strong>' +
            (relative(appt.date) ? ' <span class="dp-pill">' + esc(relative(appt.date)) + '</span>' : '') + '</div>';
        var services = (j.services_availed || []).map(function (s) { return s.description; }).filter(Boolean);
        if (!services.length) services = appt.services;
        if (services.length) rows.push(['Services', '<div class="dp-tags">' + services.map(function (n) { return '<span class="dp-tag">' + esc(n) + '</span>'; }).join('') + '</div>']);
        var done = ((j.treatments || {}).done || []);
        if (done.length) rows.push(['Treatments done', '<div class="dp-tags">' + done.map(function (t) { return '<span class="dp-tag dp-tag--done"><i class="bi bi-check2"></i> ' + esc(t.service_name || 'Treatment') + (t.tooth_number ? ' #' + esc(t.tooth_number) : '') + '</span>'; }).join('') + '</div>']);
        var findings = toothFindingList(rec.odontogram);
        if (findings.length) rows.push(['Tooth findings', '<div class="dp-tags">' + findings.slice(0, 6).map(function (f) { return '<span class="dp-tag dp-tag--tooth">#' + esc(f.tooth) + (f.text ? ' ' + esc(f.text) : '') + '</span>'; }).join('') + (findings.length > 6 ? '<span class="dp-muted">+' + (findings.length - 6) + ' more</span>' : '') + '</div>']);
        if (meaningful(rec.doctor_notes)) rows.push(['Doctor notes', '<div class="dp-quote">' + esc(rec.doctor_notes) + '</div>']);
        var rx = j.prescriptions || [];
        if (rx.length) rows.push(['Prescribed', esc(rx.map(function (r) { return r.medicine_name + (r.dosage ? ' ' + r.dosage : ''); }).join(', '))]);
        if (rec.follow_up_date) rows.push(['Follow-up', esc(fmtDate(rec.follow_up_date))]);
        return '<div class="dp-rec-sum">' + head +
            (rows.length
                ? '<dl class="dp-rec-sum__rows">' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + r[1] + '</dd>'; }).join('') + '</dl>'
                : '<div class="dp-muted mt-2">No findings, notes or prescriptions recorded.</div>') +
        '</div>';
    }

    // ---- Appointments ----
    function apptRow(a) {
        var future = a.date >= state.cfg.today && DONE.indexOf(a.status) === -1 && CLOSED.indexOf(a.status) === -1;
        var title = a.services.length ? a.services.join(', ') : (meaningful(a.reason) ? a.reason : 'Appointment');
        var sub = [timeRange(a), a.is_online ? 'Online' : 'In-person', doctorLabel(a)];
        if (a.services.length && meaningful(a.reason)) sub.push(a.reason);
        var action = a.record_id
            ? '<button type="button" class="dp-link" data-dp-open-record="' + a.appointment_id + '"><i class="bi bi-journal-text"></i> View record</button>'
            : '<span class="dp-muted dp-appt__none">' + (future ? '' : 'No record') + '</span>';
        return '<li class="dp-appt' + (future ? ' is-future' : '') + '">' +
            '<span class="dp-appt__date"><strong>' + esc(fmtDate(a.date, false)) + '</strong><small>' + esc(a.date.slice(0, 4)) + '</small></span>' +
            '<span class="dp-appt__main"><span class="dp-appt__title">' + esc(title) + '</span>' +
                '<span class="dp-appt__sub">' + esc(sub.filter(Boolean).join(' · ')) + (future && relative(a.date) ? ' <span class="dp-pill">' + esc(relative(a.date)) + '</span>' : '') + '</span></span>' +
            statusBadge(a.status) +
            '<span class="dp-appt__action">' + action + '</span>' +
        '</li>';
    }
    function renderAppointments(cur) {
        var el = pane('appointments');
        var appts = cur.patient.appointments;
        if (!appts.length) { el.innerHTML = emptyNote('bi-calendar-x', isDoctor() ? 'No appointments with this patient yet.' : 'This patient has no appointments yet.'); return; }
        var today = state.cfg.today;
        var upcoming = appts.filter(function (a) { return a.date >= today && DONE.indexOf(a.status) === -1 && CLOSED.indexOf(a.status) === -1; })
            .sort(function (a, b) { return (a.date + (a.start || '')).localeCompare(b.date + (b.start || '')); });
        var past = appts.filter(function (a) { return upcoming.indexOf(a) === -1; })
            .sort(function (a, b) { return (b.date + (b.start || '')).localeCompare(a.date + (a.start || '')); });
        el.innerHTML =
            '<div class="dp-group"><h6 class="dp-group__title">Upcoming <span class="dp-group__count">' + upcoming.length + '</span></h6>' +
                (upcoming.length ? '<ul class="dp-appts">' + upcoming.map(apptRow).join('') + '</ul>' : emptyNote('bi-calendar-x', 'No upcoming appointments.')) + '</div>' +
            '<div class="dp-group"><h6 class="dp-group__title">Past <span class="dp-group__count">' + past.length + '</span></h6>' +
                (past.length ? '<ul class="dp-appts">' + past.map(apptRow).join('') + '</ul>' : emptyNote('bi-clock-history', 'No past appointments.')) + '</div>';
    }

    // ---- Medical Records (list + full record side by side) ----
    function renderRecords(cur) {
        var el = pane('records');
        var recs = recordsOf(cur.patient);
        if (!recs.length) { el.innerHTML = emptyNote('bi-journal-x', 'No clinical records for this patient yet.'); return; }
        el.innerHTML = '<div class="dp-records">' +
            '<nav class="dp-records__list" aria-label="Clinical records">' + recs.map(function (a) {
                return '<button type="button" class="dp-rec-item" data-dp-record="' + a.appointment_id + '">' +
                    '<strong>' + esc(fmtDate(a.date)) + '</strong>' +
                    '<span>' + esc(a.services.length ? a.services.join(', ') : (meaningful(a.reason) ? a.reason : 'Visit')) + '</span>' +
                    (a.record_status ? '<small>' + esc(humanStatus(a.record_status)) + '</small>' : '') +
                '</button>';
            }).join('') + '</nav>' +
            '<div class="dp-records__detail" id="dpRecordDetail" aria-live="polite"></div>' +
        '</div>';
        selectRecord(cur.pendingRecord || recs[0].appointment_id);
        cur.pendingRecord = null;
    }
    function selectRecord(appointmentId) {
        var cur = state.current;
        var detail = document.getElementById('dpRecordDetail');
        if (!cur || !detail) return;
        var appt = null;
        cur.patient.appointments.forEach(function (a) { if (a.appointment_id === appointmentId) appt = a; });
        if (!appt) return;
        document.querySelectorAll('#dpChartView .dp-rec-item').forEach(function (b) {
            var on = Number(b.getAttribute('data-dp-record')) === appointmentId;
            b.classList.toggle('is-active', on);
            if (on) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
        });
        detail.innerHTML = skeleton(5);
        loadVisit(appointmentId).then(function (j) {
            if (!stillCurrent(cur)) return;
            detail.innerHTML =
                '<div class="dp-rec-head"><div><h5 class="dp-rec-head__title">Visit on ' + esc(fmtDate(appt.date)) + '</h5>' +
                '<div class="dp-muted">' + esc([timeRange(appt), appt.is_online ? 'Online' : 'In-person', doctorLabel(appt)].filter(Boolean).join(' · ')) + '</div></div>' +
                '<div class="dp-rec-head__side">' + statusBadge(appt.status) +
                '<button type="button" class="dp-link" data-dp-open-chart="' + appt.appointment_id + '"><i class="bi bi-grid-3x3-gap"></i> View chart</button></div></div>' +
                visitBasics(appt) + visitDetail(j);
        }).catch(function () {
            if (stillCurrent(cur)) detail.innerHTML = emptyNote('bi-wifi-off', 'Could not load this record. Select it again to retry.');
        });
    }
    // Jump to a record from anywhere in the chart.
    function openRecord(appointmentId) {
        var cur = state.current;
        if (!cur) return;
        if (cur.loaded.records) { showTab('records'); selectRecord(appointmentId); }
        else { cur.pendingRecord = appointmentId; showTab('records'); }
        var detail = document.getElementById('dpRecordDetail');
        if (detail && window.innerWidth < 992) detail.scrollIntoView({ block: 'start' });
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
        var tr = j.treatments || {};
        if (global.TreatmentPlan && ((tr.done || []).length || (tr.planned || []).length)) html += section('Treatments', 'bi-list-check', global.TreatmentPlan.renderVisit(tr));
        if (services.length) html += section('Services availed', 'bi-receipt', '<div class="dp-tags">' + services.map(function (n) { return '<span class="dp-tag">' + esc(n) + '</span>'; }).join('') + '</div>');

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

    // ---- Charting (read-only odontogram, 2D + 3D) ----
    var chartFrameReady = null;
    function chartFrame() {
        return document.getElementById('dpChartingFrame');
    }
    // The iframe loads on first use only, then stays loaded for every patient.
    function ensureChartFrame() {
        if (chartFrameReady) return chartFrameReady;
        var frame = chartFrame();
        if (!frame) return Promise.reject(new Error('missing frame'));
        chartFrameReady = new Promise(function (resolve) {
            frame.addEventListener('load', function () { resolve(frame); }, { once: true });
            frame.src = frame.getAttribute('data-src');
        });
        return chartFrameReady;
    }
    function postToChartFrame(msg) {
        if (!chartFrameReady) return;
        chartFrameReady.then(function (frame) {
            if (frame.contentWindow) frame.contentWindow.postMessage(msg, '*');
        });
    }
    function chartedTeeth(odontogram) {
        return Object.keys(odontogram || {}).filter(function (t) {
            var e = odontogram[t] || {};
            return Object.keys(e.surfaces || {}).some(function (k) { return (e.surfaces[k] || []).length; }) || meaningful(e.comment);
        }).length;
    }
    function renderCharting(cur) {
        var dates = document.getElementById('dpChartingDates');
        var note = document.getElementById('dpChartingNote');
        var viewport = document.getElementById('dpChartingViewport');
        var fsBtn = document.getElementById('dpChartingFullscreen');
        var recs = recordsOf(cur.patient);
        if (!dates) return;
        if (!recs.length) {
            dates.innerHTML = '';
            note.innerHTML = emptyNote('bi-grid-3x3-gap', 'No charted visits yet. The chart appears here once a clinical record is saved.');
            viewport.hidden = true;
            fsBtn.hidden = true;
            return;
        }
        viewport.hidden = false;
        fsBtn.hidden = false;
        dates.innerHTML = recs.map(function (a, i) {
            return '<button type="button" class="dp-date-pill" data-dp-chart="' + a.appointment_id + '">' +
                esc(fmtDate(a.date)) + (i === 0 ? ' <span>Latest</span>' : '') + '</button>';
        }).join('');
        selectCharting(cur.pendingChart || recs[0].appointment_id);
        cur.pendingChart = null;
    }
    function selectCharting(appointmentId) {
        var cur = state.current;
        var note = document.getElementById('dpChartingNote');
        if (!cur || !note) return;
        var appt = null;
        cur.patient.appointments.forEach(function (a) { if (a.appointment_id === appointmentId) appt = a; });
        if (!appt) return;
        document.querySelectorAll('#dpChartingDates .dp-date-pill').forEach(function (b) {
            var on = Number(b.getAttribute('data-dp-chart')) === appointmentId;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        note.innerHTML = '<span class="dp-muted"><i class="bi bi-hourglass-split"></i> Loading chart…</span>';
        Promise.all([loadVisit(appointmentId), ensureChartFrame()]).then(function (res) {
            if (!stillCurrent(cur)) return;
            var odontogram = (res[0].record || {}).odontogram || {};
            var n = chartedTeeth(odontogram);
            note.innerHTML = '<span><strong>' + esc(fmtDate(appt.date)) + '</strong> · ' +
                esc(appt.services.length ? appt.services.join(', ') : 'Visit') + '</span>' +
                '<span class="dp-muted">' + (n ? n + ' charted ' + (n === 1 ? 'tooth' : 'teeth') : 'No teeth charted on this visit') + ' · read-only</span>';
            res[1].contentWindow.postMessage({ type: 'load_odontogram', toothState: odontogram, readonly: true }, '*');
        }).catch(function () {
            if (stillCurrent(cur)) note.innerHTML = emptyNote('bi-wifi-off', 'Could not load this chart. Select the date again to retry.');
        });
    }
    function openChartFor(appointmentId) {
        var cur = state.current;
        if (!cur) return;
        if (cur.loaded.charting) { showTab('charting'); selectCharting(appointmentId); }
        else { cur.pendingChart = appointmentId; showTab('charting'); }
    }
    function chartViewport() {
        return document.getElementById('dpChartingViewport');
    }
    function syncChartFullscreenUI() {
        var vp = chartViewport();
        if (!vp || !global.OdontogramFullscreen) return;
        var active = global.OdontogramFullscreen.isActive(vp);
        var close = document.getElementById('dpChartingFsClose');
        var btn = document.getElementById('dpChartingFullscreen');
        if (close) close.hidden = !active;
        if (btn) btn.querySelector('span').textContent = active ? 'Exit full screen' : 'Full screen';
        postToChartFrame({ type: 'odontogram_resize' });
    }
    function toggleChartFullscreen(forceExit) {
        var vp = chartViewport();
        if (!vp || !global.OdontogramFullscreen) return;
        if (forceExit || global.OdontogramFullscreen.isActive(vp)) global.OdontogramFullscreen.exit(vp);
        else global.OdontogramFullscreen.request(vp);
    }

    // ---- Treatment plan ----
    function mountPlan(cur) {
        var host = document.getElementById('dpTreatmentPlan');
        if (!host) return;
        if (!global.TreatmentPlan) { host.innerHTML = emptyNote('bi-exclamation-circle', 'The treatment plan tool did not load. Refresh the page.'); return; }
        // Admin can review the plan; only the doctor and secretary change it (treatment_plan/save.php).
        var role = state.cfg.role || 'doctor';
        global.TreatmentPlan.mount(host, {
            patientId: cur.patient.patient_id,
            appointmentId: 0,
            role: role,
            readOnly: role !== 'doctor' && role !== 'secretary',
            csrfToken: state.cfg.csrfToken || global.doctorCsrfToken || '',
            services: state.cfg.services || []
        });
    }

    // ---- Profiling ----
    function renderProfiling(cur) {
        var el = pane('profiling');
        el.innerHTML = skeleton(5);
        loadProfiling(cur).then(function (j) {
            if (!stillCurrent(cur)) return;
            el.innerHTML = typeof global.renderDoctorPatientProfile === 'function'
                ? global.renderDoctorPatientProfile(j.patient || {}, j.profiling || {}, fullName(cur.patient), { showHeader: false })
                : emptyNote('bi-exclamation-circle', 'Unable to display the profiling.');
        }).catch(function () {
            if (stillCurrent(cur)) el.innerHTML = emptyNote('bi-wifi-off', 'Could not load the profiling.');
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

        var viewEl = chartEl();
        if (viewEl) {
            viewEl.addEventListener('click', function (e) {
                if (e.target.closest('[data-dp-back]')) { closeChart(); return; }
                var tab = e.target.closest('[data-dp-tab]');
                if (tab) { showTab(tab.getAttribute('data-dp-tab')); return; }
                var go = e.target.closest('[data-dp-goto]');
                if (go) { showTab(go.getAttribute('data-dp-goto')); return; }
                var openRec = e.target.closest('[data-dp-open-record]');
                if (openRec) { openRecord(Number(openRec.getAttribute('data-dp-open-record'))); return; }
                var recItem = e.target.closest('[data-dp-record]');
                if (recItem) { selectRecord(Number(recItem.getAttribute('data-dp-record'))); return; }
                var openChartBtn = e.target.closest('[data-dp-open-chart]');
                if (openChartBtn) { openChartFor(Number(openChartBtn.getAttribute('data-dp-open-chart'))); return; }
                var chartPill = e.target.closest('[data-dp-chart]');
                if (chartPill) { selectCharting(Number(chartPill.getAttribute('data-dp-chart'))); return; }
                if (e.target.closest('#dpChartingFullscreen')) { toggleChartFullscreen(); return; }
                if (e.target.closest('#dpChartingFsClose')) { toggleChartFullscreen(true); return; }
                var photo = e.target.closest('[data-dp-photo]');
                if (photo && typeof global.openMediaLightbox === 'function') {
                    global.openMediaLightbox(photo.getAttribute('data-dp-photo'), 'clinical-photo');
                }
            });
            // Arrow keys move between tabs, as expected for a tablist.
            viewEl.addEventListener('keydown', function (e) {
                var tab = e.target.closest('.dp-tab');
                if (!tab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
                var tabs = Array.prototype.slice.call(viewEl.querySelectorAll('.dp-tab'));
                var i = tabs.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : -1);
                var next = tabs[(i + tabs.length) % tabs.length];
                next.focus();
                showTab(next.getAttribute('data-dp-tab'));
            });
        }
        // The portals swap pages without reloading and re-run init, so document-level
        // listeners are bound once; they read the live state.
        if (documentBound) return;
        documentBound = true;
        document.addEventListener('fullscreenchange', syncChartFullscreenUI);
        document.addEventListener('webkitfullscreenchange', syncChartFullscreenUI);
        document.addEventListener('odontogram:pseudofullscreenchange', function (e) {
            if (e.target && e.target.id === 'dpChartingViewport') syncChartFullscreenUI();
        });
        // Escape also returns to the list, unless a dialog (e.g. the photo lightbox) is open
        // or the chart is full screen (Escape leaves full screen first).
        document.addEventListener('keydown', function (e) {
            // Bootstrap drops .show before this runs, so also check where Escape came from
            // and body.modal-open (removed only once the modal finishes hiding).
            if (e.key !== 'Escape' || !state || !state.current || !chartEl()) return;
            if (document.querySelector('.modal.show') || document.body.classList.contains('modal-open') ||
                (e.target && e.target.closest && e.target.closest('.modal'))) return;
            var vp = chartViewport();
            if (vp && global.OdontogramFullscreen && global.OdontogramFullscreen.isActive(vp)) { toggleChartFullscreen(true); return; }
            closeChart();
        });
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
            listScroll: 0,
            visitCache: {}
        };
        patients.forEach(function (p) { p._sum = summarize(p); });
        bind();
        renderList();
        if (cfg.openPatientId && patientById(Number(cfg.openPatientId))) openChart(Number(cfg.openPatientId));
    }

    // patient-account.js reads patients and hands back saved changes through these.
    function getPatient(id) {
        return state ? patientById(Number(id)) : null;
    }
    function updatePatient(id, fields) {
        var p = getPatient(id);
        if (!p) return;
        Object.keys(fields || {}).forEach(function (k) { p[k] = fields[k]; });
        if (p.date_of_birth && parseDay(p.date_of_birth)) {
            var dob = parseDay(p.date_of_birth), today = parseDay(state.cfg.today);
            var age = today.getFullYear() - dob.getFullYear();
            if (today < new Date(today.getFullYear(), dob.getMonth(), dob.getDate())) age--;
            p.age = age;
        }
        renderList();
        if (state.current && state.current.patient === p) renderIdentity(p);
    }

    global.DoctorPatients = {
        init: init,
        open: function (id, tab) { if (state) openChart(Number(id), tab); },
        getPatient: getPatient,
        updatePatient: updatePatient,
        role: function () { return state ? (state.cfg.role || 'doctor') : null; }
    };
})(window);
