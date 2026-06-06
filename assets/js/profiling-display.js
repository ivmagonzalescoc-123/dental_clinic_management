(function (global) {
    const VISIT_CLINICAL_LABELS = {
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

    const BACKGROUND_LABELS = {
        race: 'Race',
        occupation: 'Occupation',
        medical_history: 'Medical History',
        medications: 'Medications',
        allergies: 'Allergies',
        smoking: 'Smoking',
        alcohol: 'Alcohol',
        hypertensive: 'Hypertensive',
        diabetic: 'Diabetic',
        asthmatic: 'Asthmatic',
        heart_disease: 'Heart Disease',
        hiv_positive: 'HIV Positive',
        bleeding_disorder: 'Bleeding Disorder',
        asa: 'ASA Classification',
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

    function formatProfilingValue(value) {
        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).trim();
    }

    function hasProfilingValue(value) {
        if (typeof value === 'boolean') {
            return value;
        }
        return formatProfilingValue(value) !== '';
    }

    function renderProfilingFields(data, labels, emptyMessage) {
        if (!data || typeof data !== 'object') {
            return `<div class="text-muted small">${escapeHtml(emptyMessage)}</div>`;
        }

        let html = '<div class="row">';
        let hasData = false;

        Object.keys(labels).forEach((key) => {
            if (!hasProfilingValue(data[key])) {
                return;
            }
            hasData = true;
            const col = (key === 'medical_history' || key === 'medications' || key === 'previous_dental_treatment' || key === 'background_notes' || key === 'radiographic_interpretation') ? 12 : 6;
            html += `<div class="col-md-${col} mb-2">
                <strong>${escapeHtml(labels[key])}:</strong>
                <div class="small text-muted">${escapeHtml(formatProfilingValue(data[key]))}</div>
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

    function renderBackgroundProfilingFields(data) {
        return renderProfilingFields(data, BACKGROUND_LABELS, 'No patient background profiling on file.');
    }

    global.renderVisitClinicalFields = renderVisitClinicalFields;
    global.renderBackgroundProfilingFields = renderBackgroundProfilingFields;
    global.VISIT_CLINICAL_LABELS = VISIT_CLINICAL_LABELS;
    global.BACKGROUND_PROFILING_LABELS = BACKGROUND_LABELS;
})(window);
