/**
 * Add past visit (includes/patients_module.php): encodes one visit from the clinic's old
 * paper records onto the open patient chart.
 *
 *   [data-pv-add="id"]  opens the form  -> api/appointments/create_past_visit.php
 *
 * The button and the form only exist for the admin, and for the secretary while the admin
 * has switched past visit entry on. "Save and add another" keeps the form open for the next
 * visit of the same patient; the chart is redrawn once the form closes.
 *
 *   [data-pv-access]    (admin) opens Patients settings, where that switch lives
 *                       -> api/admin/set_past_visit_access.php
 *
 * Listeners are delegated on document and bound once, because the portals swap pages in
 * place and re-render the modal.
 */
(function (global) {
    'use strict';

    if (global.PastVisit) return;

    var API = '../assets/api/appointments/create_past_visit.php';
    var patientId = 0;
    var savedCount = 0;
    var toothState = {};
    var requestKey = null; // kept until the save succeeds, so a retry is not saved twice
    var sending = false;

    // ---------- helpers ----------
    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function notify(message, type) {
        if (typeof global.portalNotify === 'function') global.portalNotify(String(message || ''), type || 'info');
        else if (typeof global.showToast === 'function') global.showToast(String(message || ''), type || 'info');
    }
    function modal() {
        var el = $('pvModal');
        return el && global.bootstrap && global.bootstrap.Modal ? global.bootstrap.Modal.getOrCreateInstance(el) : null;
    }
    function showAlert(message) {
        var el = $('pvAlert');
        if (!el) return;
        el.textContent = message || '';
        el.hidden = !message;
        if (message) el.scrollIntoView({ block: 'nearest' });
    }
    function busy(on) {
        ['pvSubmit', 'pvSubmitAnother'].forEach(function (id) {
            var btn = $(id);
            if (btn) btn.disabled = on;
        });
    }
    function config() {
        return global.DoctorPatients ? global.DoctorPatients.config() : {};
    }
    function newKey() {
        if (global.Idempotency) return global.Idempotency.newKey('past-visit');
        return 'past-visit-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }

    // ---------- procedure rows ----------
    function rowHtml() {
        var options = (config().services || []).map(function (s) {
            return '<option value="' + Number(s.service_id) + '">' + esc(s.name) + '</option>';
        }).join('');
        return '<div class="pv-row">' +
            '<select class="form-select" data-pv-service aria-label="Procedure"><option value="">Select a procedure…</option>' + options + '</select>' +
            '<input type="text" class="form-control" data-pv-teeth maxlength="100" placeholder="Teeth, e.g. 36, 37" aria-label="Teeth">' +
            '<button type="button" class="pv-row__remove" data-pv-remove aria-label="Remove procedure" title="Remove"><i class="bi bi-x-lg"></i></button>' +
        '</div>';
    }
    function addRow() {
        var rows = $('pvRows');
        if (!rows) return;
        rows.insertAdjacentHTML('beforeend', rowHtml());
        var selects = rows.querySelectorAll('[data-pv-service]');
        return selects[selects.length - 1];
    }
    function readRows() {
        var out = [];
        document.querySelectorAll('#pvRows .pv-row').forEach(function (row) {
            var serviceId = Number(row.querySelector('[data-pv-service]').value || 0);
            if (serviceId > 0) out.push({ service_id: serviceId, teeth: row.querySelector('[data-pv-teeth]').value.trim() });
        });
        return out;
    }

    // ---------- tooth chart (same embed as the EHR, loaded only when opened) ----------
    function chartFrame() { return $('pvChartFrame'); }
    function postChart(data) {
        var frame = chartFrame();
        if (!frame || !frame.dataset.pvLoaded || !frame.contentWindow) return;
        frame.contentWindow.postMessage({ type: 'load_odontogram', toothState: data || {}, readonly: false }, '*');
        frame.contentWindow.postMessage({ type: 'odontogram_resize' }, '*');
    }
    function openChart() {
        var frame = chartFrame();
        if (!frame) return;
        if (frame.dataset.pvLoaded) { postChart(toothState); return; }
        if (frame.getAttribute('src')) return; // still loading
        frame.addEventListener('load', function () {
            frame.dataset.pvLoaded = '1';
            postChart(toothState);
        }, { once: true });
        frame.src = frame.getAttribute('data-src');
    }

    // ---------- installment section ----------
    function syncInstallment() {
        var form = $('pvForm');
        var box = $('pvInstallment');
        if (!form || !box) return;
        var on = form.elements.has_installment.checked;
        box.hidden = !on;
        ['inst_balance', 'inst_terms', 'inst_next_due'].forEach(function (name) {
            form.elements[name].required = on;
        });
        var balance = parseFloat(form.elements.inst_balance.value);
        var terms = parseInt(form.elements.inst_terms.value, 10);
        var each = $('pvInstEach');
        if (each) {
            each.value = balance > 0 && terms > 0
                ? '₱' + (balance / terms).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '—';
        }
    }

    // ---------- open / reset ----------
    // keepContext: clearing for the next visit of the same patient, so the dentist stays.
    function resetForm(keepContext) {
        var form = $('pvForm');
        if (!form) return;
        var doctor = form.elements.doctor_id.value;
        form.reset();
        form.classList.remove('was-validated');
        form.elements.patient_id.value = String(patientId);
        if (keepContext) form.elements.doctor_id.value = doctor;
        $('pvRows').innerHTML = '';
        addRow();
        toothState = {};
        postChart({});
        var chart = $('pvChart');
        if (chart) chart.open = false;
        requestKey = null;
        syncInstallment();
        showAlert('');
    }
    function showSaved() {
        var el = $('pvSaved');
        if (!el) return;
        el.hidden = savedCount === 0;
        el.textContent = savedCount === 1 ? '1 visit saved' : savedCount + ' visits saved';
    }
    function open(id) {
        var p = global.DoctorPatients ? global.DoctorPatients.getPatient(id) : null;
        var form = $('pvForm');
        if (!p || !form) return;
        patientId = Number(id);
        savedCount = 0;
        resetForm(false);
        showSaved();
        var name = [p.first_name, p.last_name].filter(Boolean).join(' ');
        $('pvTitle').textContent = 'Add past visit: ' + name;
        var m = modal();
        if (m) m.show();
    }

    // ---------- save ----------
    function submit(keepOpen) {
        var form = $('pvForm');
        if (!form || sending) return;
        showAlert('');
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            form.reportValidity();
            return;
        }
        var procedures = readRows();
        if (!procedures.length && !form.elements.summary.value.trim()) {
            showAlert('Add at least one procedure, or describe the visit in the notes.');
            return;
        }

        var body = new FormData(form);
        body.append('procedures', JSON.stringify(procedures));
        body.append('odontogram', JSON.stringify(toothState || {}));
        if (!requestKey) requestKey = newKey();
        body.append('idempotency_key', requestKey);

        sending = true;
        busy(true);
        fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected server response.' }; }); })
            .then(function (res) {
                sending = false;
                busy(false);
                if (!res.success) {
                    // A refused request was not saved, so the next try is a new request.
                    if (res.code !== 'duplicate_in_flight') requestKey = null;
                    showAlert(res.message || 'Unable to save the visit.');
                    return;
                }
                savedCount++;
                if (global.DoctorPatients && res.appointment) global.DoctorPatients.addAppointment(patientId, res.appointment);
                if (keepOpen) {
                    resetForm(true);
                    showSaved();
                    form.elements.visit_date.focus();
                } else {
                    notify(savedCount === 1 ? 'Past visit saved.' : savedCount + ' past visits saved.', 'success');
                    var m = modal();
                    if (m) m.hide();
                }
            })
            .catch(function () {
                sending = false;
                busy(false);
                showAlert('Unable to reach the server. Please try again.');
            });
    }

    // ---------- Patients settings (admin): open or close past visit entry for the secretary ----------
    // Turning it on asks for the admin's password; the server checks it. Turning it off does not.
    var ACCESS_API = '../assets/api/admin/set_past_visit_access.php';
    function accessModalEl() { return $('pvAccessModal'); }
    function accessEnabled() { var el = accessModalEl(); return !!el && el.dataset.enabled === '1'; }
    function accessAlert(message) {
        var el = $('pvAccessAlert');
        if (!el) return;
        el.textContent = message || '';
        el.hidden = !message;
    }
    // Back to the saved state, with the password step closed.
    function accessReset() {
        var sw = $('pvAccessSwitch');
        if (sw) { sw.checked = accessEnabled(); sw.disabled = false; }
        if ($('pvAccessConfirm')) $('pvAccessConfirm').hidden = true;
        if ($('pvAccessPassword')) $('pvAccessPassword').value = '';
        accessAlert('');
    }
    function openAccess() {
        var el = accessModalEl();
        if (!el || !global.bootstrap) return;
        accessReset();
        if (typeof global.initPasswordToggles === 'function') global.initPasswordToggles($('pvAccessForm'));
        global.bootstrap.Modal.getOrCreateInstance(el).show();
    }
    function saveAccess(enabled, password) {
        return fetch(ACCESS_API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: enabled, password: password || '', csrf_token: config().csrfToken || '' })
        }).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected server response.' }; }); });
    }
    function accessSwitched(sw) {
        accessAlert('');
        if (sw.checked) {
            // Stays pending until the password is accepted.
            $('pvAccessConfirm').hidden = false;
            $('pvAccessPassword').focus();
            return;
        }
        $('pvAccessConfirm').hidden = true;
        if (!accessEnabled()) return; // only the pending "on" was cancelled
        sw.disabled = true;
        saveAccess(false).then(function (res) {
            sw.disabled = false;
            if (!res.success) { sw.checked = true; accessAlert(res.message || 'Could not save the setting.'); return; }
            accessModalEl().dataset.enabled = '0';
            notify('Past visit entry is now off for the secretary.', 'success');
        }).catch(function () {
            sw.disabled = false;
            sw.checked = true;
            accessAlert('Unable to reach the server. Please try again.');
        });
    }
    function accessConfirm() {
        var input = $('pvAccessPassword');
        var btn = $('pvAccessSubmit');
        accessAlert('');
        if (!input.value) { accessAlert('Enter your password to confirm.'); input.focus(); return; }
        btn.disabled = true;
        saveAccess(true, input.value).then(function (res) {
            btn.disabled = false;
            if (!res.success) { accessAlert(res.message || 'Could not save the setting.'); input.select(); return; }
            accessModalEl().dataset.enabled = '1';
            accessReset();
            notify('The secretary can now add past visits.', 'success');
        }).catch(function () {
            btn.disabled = false;
            accessAlert('Unable to reach the server. Please try again.');
        });
    }

    // ---------- wiring ----------
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-pv-access]')) { openAccess(); return; }
        if (e.target.closest('#pvAccessCancel')) { accessReset(); return; }
        var add = e.target.closest('[data-pv-add]');
        if (add) { open(Number(add.getAttribute('data-pv-add'))); return; }
        if (e.target.closest('#pvAddRow')) { var sel = addRow(); if (sel) sel.focus(); return; }
        var remove = e.target.closest('[data-pv-remove]');
        if (remove) {
            remove.closest('.pv-row').remove();
            if (!document.querySelector('#pvRows .pv-row')) addRow();
            return;
        }
        if (e.target.closest('#pvSubmitAnother')) submit(true);
    });
    document.addEventListener('submit', function (e) {
        if (!e.target) return;
        if (e.target.id === 'pvAccessForm') { e.preventDefault(); accessConfirm(); return; }
        if (e.target.id !== 'pvForm') return;
        e.preventDefault();
        submit(false);
    });
    document.addEventListener('input', function (e) {
        if (e.target.closest && e.target.closest('#pvForm')) syncInstallment();
    });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'pvAccessSwitch') { accessSwitched(e.target); return; }
        if (e.target.closest && e.target.closest('#pvForm')) syncInstallment();
    });
    // "toggle" does not bubble, so it is caught on the way down.
    document.addEventListener('toggle', function (e) {
        if (e.target && e.target.id === 'pvChart' && e.target.open) openChart();
    }, true);
    global.addEventListener('message', function (ev) {
        var frame = chartFrame();
        if (!frame || ev.source !== frame.contentWindow) return;
        if (ev.data && ev.data.type === 'odontogram_update' && ev.data.toothState) toothState = ev.data.toothState;
    });
    document.addEventListener('hidden.bs.modal', function (e) {
        if (!e.target || e.target.id !== 'pvModal') return;
        if (savedCount > 0 && global.DoctorPatients) global.DoctorPatients.refreshChart();
        savedCount = 0;
    });

    global.PastVisit = { open: open };
})(window);
