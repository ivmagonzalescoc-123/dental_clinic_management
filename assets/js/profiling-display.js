(function (global) {
    const VISIT_CLINICAL_LABELS = {
        case_history: 'Case History',
        chief_complaint: 'Chief Complaint',
        gingiva_consistency: 'Consistency Gingiva',
        oral_hygiene: 'Oral Hygiene',
        gingival_color: 'Gingival Color',
        lymph_nodes: 'Lymph Nodes',
        tongue: 'Tongue',
        occlusion: 'Occlusion',
        cl_i_type: 'Cl. I Type',
        cl_ii_division: 'Cl. II Division',
        cl_ii_subdivision: 'Cl. II SubDivision',
        cl_ii_type: 'Cl. II Type',
        cl_iii_division: 'Cl. III Division',
        cl_iii_subdivision: 'Cl. III SubDivision',
        cl_iii_extra: 'Cl. III Extra',
        operation_if_any: 'Operation if any',
        blood_sugar: 'Blood Sugar',
        blood_sugar_normal: 'Blood Sugar: Normal',
        blood_sugar_high_low: 'Blood Sugar: High/Low',
        blood_sugar_low: 'Blood Sugar: Low',
        bleeding_time: 'Bleeding Time',
        clotting_time: 'Clotting Time',
        radiographic_interpretation: 'Radiographic Interpretation',
        allergy_notes: 'Allergy Notes (Visit)',
        blood_diseases: 'Blood Diseases',
        fainting: 'Fainting',
        blood_pressure: 'Blood Pressure'
    };

    // Keys match the raw patient_profiling column names as returned by `SELECT *`
    // (get_records_by_appointment_detailed.php / get_clinical_record.php pass the DB
    // row straight through) — NOT the friendly payload keys used when saving.
    const BACKGROUND_LABELS = {
        race: 'Race',
        occupation: 'Occupation',
        medical_history: 'Medical History',
        medications: 'Medications',
        allergies: 'Allergies',
        smoking: 'Smoking',
        alcohol: 'Alcohol',
        is_hypertensive: 'Hypertensive',
        is_diabetic: 'Diabetic',
        is_asthmatic: 'Asthmatic',
        has_heart_disease: 'Heart Disease',
        is_hiv_positive: 'HIV Positive',
        bleeding_disorder: 'Bleeding Disorder',
        pregnancy_status: 'Pregnancy Status',
        previous_dental_treatment: 'Previous Dental Treatment',
        dental_anxiety: 'Dental Anxiety',
        emergency_contact: 'Emergency Contact',
        emergency_phone: 'Emergency Phone',
        background_notes: 'Additional Notes'
    };

    function escapeHtml(value) {
        if (typeof global.escapeHtml === 'function') {
            return global.escapeHtml(value);
        }
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // patient_profiling tinyint(1) flag columns — always rendered as Yes/No (a "No" is
    // meaningful clinical info, unlike an empty text field) rather than hidden when falsy.
    // fetch_assoc()/json_encode() round-trips these as the strings "0"/"1", not real booleans.
    const BOOLEAN_FLAG_KEYS = new Set(['is_hypertensive', 'is_diabetic', 'is_asthmatic', 'has_heart_disease', 'is_hiv_positive']);

    function isTruthyFlag(value) {
        return value === true || value === 1 || value === '1';
    }

    function formatProfilingValue(value, key) {
        if (key && BOOLEAN_FLAG_KEYS.has(key)) {
            return isTruthyFlag(value) ? 'Yes' : 'No';
        }
        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).trim();
    }

    function hasProfilingValue(value, key) {
        if (key && BOOLEAN_FLAG_KEYS.has(key)) {
            return value !== null && value !== undefined && value !== '';
        }
        if (typeof value === 'boolean') {
            return value;
        }
        return formatProfilingValue(value) !== '';
    }

    function renderProfilingFields(data, labels, emptyMessage, gender) {
        if (!data || typeof data !== 'object') {
            return `<div class="text-muted small">${escapeHtml(emptyMessage)}</div>`;
        }

        const isMale = String(gender || '').trim().toLowerCase() === 'male';
        let html = '<div class="row">';
        let hasData = false;

        Object.keys(labels).forEach((key) => {
            if (key === 'pregnancy_status' && isMale) {
                return;
            }
            if (!hasProfilingValue(data[key], key)) {
                return;
            }
            hasData = true;
            const col = (key === 'case_history' || key === 'chief_complaint' || key === 'medical_history' || key === 'medications' || key === 'previous_dental_treatment' || key === 'background_notes' || key === 'radiographic_interpretation') ? 12 : 6;
            html += `<div class="col-md-${col} mb-2">
                <strong>${escapeHtml(labels[key])}:</strong>
                <div class="small text-muted">${escapeHtml(formatProfilingValue(data[key], key))}</div>
            </div>`;
        });

        html += '</div>';
        if (!hasData) {
            return `<div class="text-muted small">${escapeHtml(emptyMessage)}</div>`;
        }
        return html;
    }

    function renderVisitClinicalFields(data) {
        return renderProfilingFields(data, VISIT_CLINICAL_LABELS, 'No visit clinical exam recorded for this appointment.');
    }

    function renderBackgroundProfilingFields(data, gender) {
        return renderProfilingFields(data, BACKGROUND_LABELS, 'No patient medical history on file.', gender);
    }

    global.renderVisitClinicalFields = renderVisitClinicalFields;
    global.renderBackgroundProfilingFields = renderBackgroundProfilingFields;
    global.VISIT_CLINICAL_LABELS = VISIT_CLINICAL_LABELS;
    global.BACKGROUND_PROFILING_LABELS = BACKGROUND_LABELS;
})(window);
