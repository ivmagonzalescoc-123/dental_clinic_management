/**
 * Patients page account tools (includes/patients_module.php):
 *
 *   [data-pa-add]            opens Add Patient           -> api/patients/create_walkin.php
 *   [data-pa-settings="id"]  opens the patient settings  -> Details: api/patients/update_patient.php
 *                                                           Account: set_patient_password.php,
 *                                                                    reset_patient_password.php
 *
 * Patients come from, and saved changes go back to, window.DoctorPatients so the list and
 * the open chart update without a reload. Listeners are delegated on document and bound
 * once, because the portals swap pages in place and re-render the modals.
 */
(function (global) {
    'use strict';

    if (global.PatientAccount) return;

    var API = '../assets/api/patients/';
    var current = null; // patient open in the settings modal

    // ---------- helpers ----------
    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function notify(message, type) {
        if (typeof global.portalNotify === 'function') global.portalNotify(String(message || ''), type || 'info');
        else if (typeof global.showToast === 'function') global.showToast(String(message || ''), type || 'info');
    }
    function modal(id) {
        var el = $(id);
        return el && global.bootstrap && global.bootstrap.Modal ? global.bootstrap.Modal.getOrCreateInstance(el) : null;
    }
    function showAlert(id, message, tone) {
        var el = $(id);
        if (!el) return;
        if (!message) { el.hidden = true; el.textContent = ''; return; }
        el.className = 'pa-alert pa-alert--' + (tone || 'danger');
        el.textContent = message;
        el.hidden = false;
    }
    function busy(btn, on, label) {
        if (!btn) return;
        if (on) {
            btn.dataset.paLabel = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + esc(label || 'Saving…');
        } else {
            btn.disabled = false;
            if (btn.dataset.paLabel) btn.innerHTML = btn.dataset.paLabel;
        }
    }
    function postForm(url, body) {
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(readJson);
    }
    function postJson(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(readJson);
    }
    function readJson(r) {
        return r.json().catch(function () { return { success: false, message: 'Unexpected server response.' }; });
    }
    function fmtDateTime(value) {
        if (!value) return '—';
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(value);
        return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    function displayName(p) {
        return [p.first_name, p.middle_name, p.last_name].filter(function (v) { return v && String(v).trim(); }).join(' ');
    }
    function patientsPageUrl(patientId) {
        var params = new URLSearchParams(global.location.search);
        params.set('page', 'patients');
        params.delete('q');
        if (patientId) params.set('patient_id', String(patientId)); else params.delete('patient_id');
        return global.location.pathname + '?' + params.toString();
    }
    function goTo(url) {
        if (typeof global.ajaxNavigate === 'function') global.ajaxNavigate(url);
        else global.location.href = url;
    }
    function setField(form, name, value) {
        var el = form.elements[name];
        if (el) el.value = value == null ? '' : String(value);
    }

    // ---------- Add Patient ----------
    function openAdd() {
        var form = $('paAddForm');
        if (!form) return;
        form.reset();
        form.classList.remove('was-validated');
        showAlert('paAddAlert', '');
        var m = modal('paAddModal');
        if (m) m.show();
    }
    function submitAdd(form) {
        showAlert('paAddAlert', '');
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            form.reportValidity();
            return;
        }
        var btn = $('paAddSubmit');
        busy(btn, true, 'Creating…');
        postForm(API + 'create_walkin.php', new FormData(form)).then(function (res) {
            busy(btn, false);
            if (!res.success) {
                showAlert('paAddAlert', res.message || 'Unable to create the patient.');
                return;
            }
            var m = modal('paAddModal');
            if (m) m.hide();
            var id = Number(res.patient_id || 0);
            var known = id && global.DoctorPatients && global.DoctorPatients.getPatient(id);
            if (res.already_exists) {
                notify('A patient with this email or phone already exists. Opening their chart.', 'info');
                if (known) global.DoctorPatients.open(id);
                else notify('That patient is not in this list yet.', 'info');
                return;
            }
            notify(res.message || 'Patient account created.', res.email_sent === false ? 'warning' : 'success');
            // A doctor's list only holds patients with appointments with them.
            if (global.DoctorPatients && global.DoctorPatients.role() === 'doctor') {
                notify('They will appear in My Patients once they have an appointment with you.', 'info');
                return;
            }
            // Reload the page data so the new patient shows up, then open their chart.
            goTo(patientsPageUrl(id));
        }).catch(function () {
            busy(btn, false);
            showAlert('paAddAlert', 'Unable to reach the server. Please try again.');
        });
    }

    // ---------- Patient settings ----------
    function showTab(name) {
        var root = $('paSettingsModal');
        if (!root) return;
        root.querySelectorAll('[data-pa-tab]').forEach(function (t) {
            var on = t.getAttribute('data-pa-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        root.querySelectorAll('[data-pa-pane]').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-pa-pane') === name);
        });
    }
    function renderHead(p) {
        var title = $('paSettingsTitle'), sub = $('paSettingsSub'), av = $('paSettingsAvatar');
        if (title) title.textContent = displayName(p) || 'Patient settings';
        if (sub) sub.textContent = 'Patient #' + p.patient_id + (p.email ? ' · ' + p.email : '');
        if (av) {
            var initials = ((String(p.first_name || '').charAt(0) + String(p.last_name || '').charAt(0)) || '?').toUpperCase();
            var src = String(p.photo || '');
            var url = src ? (/^https?:\/\//i.test(src) ? src : '../' + src.replace(/^\/+/, '')) : '';
            av.innerHTML = '<span>' + esc(initials) + '</span>' +
                (url ? '<img src="' + esc(url) + '" alt="" referrerpolicy="no-referrer" onerror="this.remove()">' : '');
        }
    }
    function renderFacts(p) {
        if ($('paFactEmail')) $('paFactEmail').textContent = p.email || '—';
        if ($('paFactCreated')) $('paFactCreated').textContent = fmtDateTime(p.created_at);
        if ($('paFactLogin')) $('paFactLogin').textContent = p.last_login_at ? fmtDateTime(p.last_login_at) : 'Never';
    }
    function openSettings(patientId, tab) {
        var p = global.DoctorPatients && global.DoctorPatients.getPatient(patientId);
        var form = $('paEditForm');
        if (!p || !form) return;
        current = p;

        form.reset();
        form.classList.remove('was-validated');
        ['patient_id', 'first_name', 'middle_name', 'last_name', 'email', 'phone_number', 'date_of_birth', 'address', 'occupation']
            .forEach(function (k) { setField(form, k, p[k]); });
        var gender = String(p.gender || '');
        setField(form, 'gender', ['Male', 'Female', 'Other'].indexOf(gender) !== -1 ? gender : '');

        var pw = $('paPasswordForm');
        if (pw) pw.reset();
        ['paEditAlert', 'paPasswordAlert', 'paTempAlert'].forEach(function (id) { showAlert(id, ''); });
        resetTemp();

        renderHead(p);
        renderFacts(p);
        showTab(tab || 'details');
        var m = modal('paSettingsModal');
        if (m) m.show();
    }
    function submitEdit(form) {
        showAlert('paEditAlert', '');
        if (!current) return;
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            form.reportValidity();
            return;
        }
        var data = new FormData(form);
        var btn = $('paEditSubmit');
        busy(btn, true);
        postForm(API + 'update_patient.php', data).then(function (res) {
            busy(btn, false);
            if (!res.success) {
                showAlert('paEditAlert', res.message || 'Unable to update the patient.');
                return;
            }
            var fields = {};
            ['first_name', 'middle_name', 'last_name', 'email', 'phone_number', 'date_of_birth', 'gender', 'address', 'occupation']
                .forEach(function (k) { fields[k] = String(data.get(k) || '').trim(); });
            global.DoctorPatients.updatePatient(current.patient_id, fields);
            renderHead(current);
            renderFacts(current);
            notify(res.message || 'Patient updated.', 'success');
        }).catch(function () {
            busy(btn, false);
            showAlert('paEditAlert', 'Unable to reach the server. Please try again.');
        });
    }

    var PASSWORD_RULE = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/;
    function submitPassword(form) {
        showAlert('paPasswordAlert', '');
        if (!current) return;
        var pass = $('paNewPassword').value;
        var confirm = $('paConfirmPassword').value;
        if (!PASSWORD_RULE.test(pass)) {
            showAlert('paPasswordAlert', 'Use at least 8 characters with an uppercase letter, a lowercase letter, a number and a symbol.');
            return;
        }
        if (pass !== confirm) {
            showAlert('paPasswordAlert', 'The passwords do not match.');
            return;
        }
        var btn = $('paPasswordSubmit');
        busy(btn, true, 'Updating…');
        postJson(API + 'set_patient_password.php', {
            csrf_token: form.closest('.modal').querySelector('input[name="csrf_token"]').value,
            patient_id: current.patient_id,
            new_password: pass,
            confirm_password: confirm
        }).then(function (res) {
            busy(btn, false);
            if (!res.success) {
                showAlert('paPasswordAlert', res.message || 'Unable to update the password.');
                return;
            }
            form.reset();
            showAlert('paPasswordAlert', res.message || 'Password updated.', 'success');
            notify(res.message || 'Password updated.', 'success');
        }).catch(function () {
            busy(btn, false);
            showAlert('paPasswordAlert', 'Unable to reach the server. Please try again.');
        });
    }

    // Generating needs a second click, so a stray click can't replace the patient's password.
    function resetTemp() {
        var btn = $('paTempGenerate');
        if (btn) {
            btn.classList.remove('is-confirming', 'btn-danger');
            btn.classList.add('btn-outline-primary');
            btn.innerHTML = '<i class="bi bi-key"></i> Generate';
            btn.disabled = false;
        }
        var result = $('paTempResult');
        if (result) result.hidden = true;
        if ($('paTempValue')) $('paTempValue').value = '';
    }
    function generateTemp(btn) {
        showAlert('paTempAlert', '');
        if (!current) return;
        if (!btn.classList.contains('is-confirming')) {
            btn.classList.add('is-confirming', 'btn-danger');
            btn.classList.remove('btn-outline-primary');
            btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Replace current password?';
            return;
        }
        var body = new FormData();
        body.append('csrf_token', btn.closest('.modal').querySelector('input[name="csrf_token"]').value);
        body.append('patient_id', String(current.patient_id));
        body.append('format', 'json');
        busy(btn, true, 'Generating…');
        postForm(API + 'reset_patient_password.php', body).then(function (res) {
            busy(btn, false);
            resetTemp();
            if (!res.success) {
                showAlert('paTempAlert', res.message || 'Unable to generate a password.', /email/i.test(res.message || '') ? 'warning' : 'danger');
                return;
            }
            $('paTempValue').value = res.password || '';
            $('paTempResult').hidden = false;
            showAlert('paTempAlert', 'Share this password with the patient securely. It is shown only once.', 'info');
        }).catch(function () {
            busy(btn, false);
            resetTemp();
            showAlert('paTempAlert', 'Unable to reach the server. Please try again.');
        });
    }
    function copyTemp() {
        var input = $('paTempValue');
        if (!input || !input.value) return;
        var done = function () { notify('Password copied to clipboard.', 'success'); };
        var fallback = function () {
            input.focus();
            input.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* user can copy manually */ }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(done).catch(fallback);
        else fallback();
    }

    // ---------- wiring (once) ----------
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-pa-add]')) { openAdd(); return; }
        var gear = e.target.closest('[data-pa-settings]');
        if (gear) { openSettings(Number(gear.getAttribute('data-pa-settings'))); return; }
        var tab = e.target.closest('#paSettingsModal [data-pa-tab]');
        if (tab) { showTab(tab.getAttribute('data-pa-tab')); return; }
        var gen = e.target.closest('#paTempGenerate');
        if (gen) { generateTemp(gen); return; }
        if (e.target.closest('#paTempCopy')) { copyTemp(); return; }
        if (e.target.closest('#paTempValue')) e.target.select();
    });
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.id || ['paAddForm', 'paEditForm', 'paPasswordForm'].indexOf(form.id) === -1) return;
        e.preventDefault();
        if (form.id === 'paAddForm') submitAdd(form);
        else if (form.id === 'paEditForm') submitEdit(form);
        else submitPassword(form);
    });
    document.addEventListener('hidden.bs.modal', function (e) {
        if (e.target && e.target.id === 'paSettingsModal') { current = null; resetTemp(); }
    });

    global.PatientAccount = { openAdd: openAdd, openSettings: openSettings };
})(window);
