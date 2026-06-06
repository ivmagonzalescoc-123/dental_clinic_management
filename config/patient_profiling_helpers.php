<?php
/**
 * Patient background profiling vs per-visit clinical exam data.
 *
 * - patients.profiling          = static patient background (demographics, chronic conditions)
 * - clinical_records.profiling  = visit clinical exam snapshot for that appointment
 */

if (!function_exists('visit_clinical_field_keys')) {
    function visit_clinical_field_keys(): array
    {
        return [
            'occlusion', 'cl_i_type', 'cl_ii_division', 'cl_ii_subdivision', 'cl_ii_type',
            'cl_iii_division', 'cl_iii_subdivision', 'cl_iii_extra', 'operation_if_any',
            'blood_sugar', 'blood_sugar_normal', 'blood_sugar_high_low', 'blood_sugar_low',
            'bleeding_time', 'clotting_time', 'radiographic_interpretation', 'allergy_notes',
            'blood_diseases', 'fainting', 'blood_pressure',
        ];
    }
}

if (!function_exists('background_profiling_field_keys')) {
    function background_profiling_field_keys(): array
    {
        return [
            'race', 'occupation', 'medical_history', 'medications', 'allergies',
            'smoking', 'alcohol', 'hypertensive', 'diabetic', 'asthmatic',
            'heart_disease', 'hiv_positive', 'bleeding_disorder', 'asa', 'pregnancy_status',
            'previous_dental_treatment', 'dental_anxiety', 'emergency_contact',
            'emergency_phone', 'background_notes',
        ];
    }
}

if (!function_exists('visit_clinical_field_labels')) {
    function visit_clinical_field_labels(): array
    {
        return [
            'occlusion' => 'Occlusion',
            'cl_i_type' => 'Cl. I Type',
            'cl_ii_division' => 'Cl. II Division',
            'cl_ii_subdivision' => 'Cl. II SubDivision',
            'cl_ii_type' => 'Cl. II Type',
            'cl_iii_division' => 'Cl. III Division',
            'cl_iii_subdivision' => 'Cl. III SubDivision',
            'cl_iii_extra' => 'Cl. III Extra',
            'operation_if_any' => 'Operation if any',
            'blood_sugar' => 'Blood Sugar',
            'blood_sugar_normal' => 'Blood Sugar: Normal',
            'blood_sugar_high_low' => 'Blood Sugar: High/Low',
            'blood_sugar_low' => 'Blood Sugar: Low',
            'bleeding_time' => 'Bleeding Time',
            'clotting_time' => 'Clotting Time',
            'radiographic_interpretation' => 'Radiographic Interpretation',
            'allergy_notes' => 'Allergy Notes (Visit)',
            'blood_diseases' => 'Blood Diseases',
            'fainting' => 'Fainting',
            'blood_pressure' => 'Blood Pressure',
        ];
    }
}

if (!function_exists('background_profiling_field_labels')) {
    function background_profiling_field_labels(): array
    {
        return [
            'race' => 'Race',
            'occupation' => 'Occupation',
            'medical_history' => 'Medical History',
            'medications' => 'Medications',
            'allergies' => 'Allergies',
            'smoking' => 'Smoking',
            'alcohol' => 'Alcohol',
            'hypertensive' => 'Hypertensive',
            'diabetic' => 'Diabetic',
            'asthmatic' => 'Asthmatic',
            'heart_disease' => 'Heart Disease',
            'hiv_positive' => 'HIV Positive',
            'bleeding_disorder' => 'Bleeding Disorder',
            'asa' => 'ASA Classification',
            'pregnancy_status' => 'Pregnancy Status',
            'previous_dental_treatment' => 'Previous Dental Treatment',
            'dental_anxiety' => 'Dental Anxiety',
            'emergency_contact' => 'Emergency Contact',
            'emergency_phone' => 'Emergency Phone',
            'background_notes' => 'Additional Notes',
        ];
    }
}

if (!function_exists('filter_profiling_data')) {
    function filter_profiling_data(?array $data, array $allowedKeys): array
    {
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value) || is_numeric($value) || (is_string($value) && trim($value) !== '')) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}

if (!function_exists('filter_visit_clinical_data')) {
    function filter_visit_clinical_data(?array $data): array
    {
        return filter_profiling_data($data, visit_clinical_field_keys());
    }
}

if (!function_exists('filter_background_profiling_data')) {
    function filter_background_profiling_data(?array $data): array
    {
        return filter_profiling_data($data, background_profiling_field_keys());
    }
}

if (!function_exists('ensure_clinical_records_profiling_column')) {
    function ensure_clinical_records_profiling_column($conn): void
    {
        $colRes = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'profiling'");
        if (!$colRes || $colRes->num_rows === 0) {
            $conn->query('ALTER TABLE clinical_records ADD COLUMN profiling JSON DEFAULT NULL');
        }
    }
}

if (!function_exists('format_profiling_value_display')) {
    function format_profiling_value_display($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null) {
            return '';
        }
        return trim((string)$value);
    }
}
