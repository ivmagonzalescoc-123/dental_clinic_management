/**
 * Rich patient profile card — avatar, medical alert box, contact/emergency info,
 * background and free-text notes. Consumes the shape returned by
 * assets/api/patients/get_profiling.php (patient + profiling raw patient_profiling row).
 */
(function (global) {
    'use strict';

    function esc(v) {
        return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function val(v) {
        return (v === null || v === undefined || String(v).trim() === '') ? '' : String(v).trim();
    }

    function yes(v) {
        return v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'yes';
    }

    // True when a free-text medical field actually indicates something worth flagging —
    // i.e. not empty, and not a "nothing to see here" answer like "N/A" or "Not pregnant".
    function isAlertWorthy(v) {
        const s = val(v).toLowerCase();
        if (!s) return false;
        if (s.indexOf('n/a') !== -1 || s === 'na') return false;
        if (s.indexOf('not') !== -1) return false;
        return true;
    }

    // Age/gender/phone chip row shared by the inline .dpp-header (below) and by pages
    // that show that identity strip in their own persistent header instead (e.g. the
    // EHR session's Patient Details modal) — see computePatientHeaderInfo().
    function computePatientHeaderInfo(patient, fallbackName) {
        patient = patient || {};
        const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ').trim() || fallbackName || 'Unknown Patient';
        const initials = fullName.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0].toUpperCase(); }).join('') || '?';

        let ageText = 'N/A';
        if (patient.date_of_birth) {
            const dob = new Date(patient.date_of_birth);
            if (!isNaN(dob.getTime())) {
                ageText = Math.abs(new Date(Date.now() - dob.getTime()).getUTCFullYear() - 1970);
            }
        }

        const chips = ['<span class="dpp-chip"><i class="bi bi-cake2"></i> Age ' + esc(ageText) + '</span>'];
        if (val(patient.gender)) chips.push('<span class="dpp-chip"><i class="bi bi-gender-ambiguous"></i> ' + esc(patient.gender) + '</span>');
        if (val(patient.phone_number)) chips.push('<span class="dpp-chip"><i class="bi bi-telephone"></i> ' + esc(patient.phone_number) + '</span>');

        return { fullName: fullName, initials: initials, ageText: ageText, chipsHtml: chips.join('') };
    }

    function renderDoctorPatientProfile(patient, profiling, fallbackName, options) {
        patient = patient || {};
        profiling = profiling || {};
        options = options || {};
        const showHeader = options.showHeader !== false;

        const info = computePatientHeaderInfo(patient, fallbackName);
        const fullName = info.fullName;

        // --- Header --- (skippable via {showHeader:false} for callers that show this
        // name/age/gender/phone strip in their own persistent header instead, so it
        // isn't duplicated inside the profile content itself)
        let html = '';
        if (showHeader) {
            html += '<div class="dpp-header">';
            html += '<div class="dpp-avatar">' + esc(info.initials) + '</div>';
            html += '<div>';
            html += '<p class="dpp-name">' + esc(fullName) + '</p>';
            html += '<div class="dpp-chips">' + info.chipsHtml + '</div>';
            html += '</div></div>';
        }

        // --- Medical alerts (the part a doctor needs to see first) ---
        const alerts = [];
        if (isAlertWorthy(profiling.allergies)) alerts.push('Allergies: ' + val(profiling.allergies));
        if (yes(profiling.is_hypertensive)) alerts.push('Hypertensive');
        if (yes(profiling.is_diabetic)) alerts.push('Diabetic');
        if (yes(profiling.is_asthmatic)) alerts.push('Asthmatic');
        if (yes(profiling.has_heart_disease)) alerts.push('Heart Disease');
        if (yes(profiling.is_hiv_positive)) alerts.push('HIV Positive');
        if (yes(profiling.bleeding_disorder)) alerts.push('Bleeding Disorder');
        const isMalePatient = String(patient.gender || '').trim().toLowerCase() === 'male';
        if (!isMalePatient && isAlertWorthy(profiling.pregnancy_status)) {
            alerts.push('Pregnancy: ' + val(profiling.pregnancy_status));
        }

        if (alerts.length > 0) {
            html += '<div class="dpp-alert-box">';
            html += '<div class="dpp-alert-title"><i class="bi bi-exclamation-triangle-fill"></i> Medical Alerts — Review Before Treatment</div>';
            alerts.forEach(function (a) { html += '<span class="dpp-alert-tag">' + esc(a) + '</span>'; });
            html += '</div>';
        } else {
            html += '<div class="dpp-safe-box"><i class="bi bi-check-circle-fill"></i> No known allergies or flagged medical conditions on file.</div>';
        }

        // --- Contact & Emergency Contact ---
        html += '<div class="row g-2 mb-1">';
        html += '<div class="col-md-6"><div class="dpp-section">';
        html += '<div class="dpp-section-title"><i class="bi bi-person-lines-fill"></i> Contact Info</div>';
        html += '<div class="dpp-field"><div class="dpp-field-label">Email</div><div class="dpp-field-value">' + esc(val(patient.email) || 'N/A') + '</div></div>';
        html += '<div class="dpp-field"><div class="dpp-field-label">Phone</div><div class="dpp-field-value">' + esc(val(patient.phone_number) || 'N/A') + '</div></div>';
        html += '<div class="dpp-field"><div class="dpp-field-label">Address</div><div class="dpp-field-value">' + esc(val(patient.address) || 'N/A') + '</div></div>';
        html += '</div></div>';

        html += '<div class="col-md-6"><div class="dpp-section">';
        html += '<div class="dpp-section-title"><i class="bi bi-telephone-forward"></i> Emergency Contact</div>';
        html += '<div class="dpp-field"><div class="dpp-field-label">Name</div><div class="dpp-field-value">' + esc(val(profiling.emergency_contact) || 'Not provided') + '</div></div>';
        html += '<div class="dpp-field"><div class="dpp-field-label">Phone</div><div class="dpp-field-value">' + esc(val(profiling.emergency_phone) || 'Not provided') + '</div></div>';
        html += '</div></div>';
        html += '</div>';

        // --- Background ---
        const bgFields = [
            ['Occupation', val(patient.occupation)],
            ['Race', val(profiling.race)],
            ['Smoking', val(profiling.smoking)],
            ['Alcohol', val(profiling.alcohol)],
            ['Previous Dental Treatment', val(profiling.previous_dental_treatment)],
            ['Dental Anxiety', val(profiling.dental_anxiety)],
        ].filter(function (pair) { return pair[1] !== ''; });

        if (bgFields.length > 0) {
            html += '<div class="dpp-section">';
            html += '<div class="dpp-section-title"><i class="bi bi-clipboard2-pulse"></i> Background</div>';
            html += '<div class="row">';
            bgFields.forEach(function (pair) {
                html += '<div class="col-md-6 dpp-field"><div class="dpp-field-label">' + esc(pair[0]) + '</div><div class="dpp-field-value">' + esc(pair[1]) + '</div></div>';
            });
            html += '</div></div>';
        }

        // --- Free-text notes ---
        const notes = [
            ['Medical History', val(profiling.medical_history)],
            ['Current Medications', val(profiling.medications)],
            ['Additional Notes', val(profiling.background_notes)],
        ].filter(function (pair) { return pair[1] !== ''; });

        if (notes.length > 0) {
            html += '<div class="dpp-section">';
            html += '<div class="dpp-section-title"><i class="bi bi-journal-medical"></i> Notes</div>';
            notes.forEach(function (pair) {
                html += '<div class="dpp-field"><div class="dpp-field-label">' + esc(pair[0]) + '</div><div class="dpp-field-value">' + esc(pair[1]) + '</div></div>';
            });
            html += '</div>';
        }

        return html;
    }

    global.renderDoctorPatientProfile = renderDoctorPatientProfile;
    global.computePatientHeaderInfo = computePatientHeaderInfo;
})(window);
