<?php
/**
 * Patient background profiling vs per-visit clinical exam data.
 *
 * - patient_profiling table = static patient background (demographics, chronic conditions)
 * - visit_clinical data is stored in patient_profiling per record
 */

if (!function_exists('visit_clinical_field_keys')) {
    function visit_clinical_field_keys(): array
    {
        return [
            'case_history', 'chief_complaint',
            'gingiva_consistency', 'oral_hygiene', 'gingival_color', 'lymph_nodes', 'tongue',
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
            'race', 'medical_history', 'medications', 'allergies',
            'smoking', 'alcohol', 'hypertensive', 'diabetic', 'asthmatic',
            'heart_disease', 'hiv_positive', 'bleeding_disorder', 'pregnancy_status',
            'previous_dental_treatment', 'dental_anxiety', 'background_notes',
        ];
    }
}

if (!function_exists('visit_clinical_field_labels')) {
    function visit_clinical_field_labels(): array
    {
        return [
            'case_history' => 'Case History',
            'chief_complaint' => 'Chief Complaint',
            'gingiva_consistency' => 'Consistency Gingiva',
            'oral_hygiene' => 'Oral Hygiene',
            'gingival_color' => 'Gingival Color',
            'lymph_nodes' => 'Lymph Nodes',
            'tongue' => 'Tongue',
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
            'pregnancy_status' => 'Pregnancy Status',
            'previous_dental_treatment' => 'Previous Dental Treatment',
            'dental_anxiety' => 'Dental Anxiety',
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
            // Keep all values including empty strings (converted to N/A by caller)
            if (is_bool($value) || is_numeric($value)) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                // Strip markup before it ever reaches the database (medical history,
                // background notes, etc. are free-text and could contain <script> tags).
                $out[$key] = function_exists('security_sanitize_html_input')
                    ? security_sanitize_html_input($value)
                    : $value;
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
    }
}

if (!function_exists('ensure_clinical_findings_table')) {
    function ensure_clinical_findings_table($conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS clinical_findings (
            finding_id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            patient_id INT NOT NULL,
            findings_json TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cf_record (record_id),
            KEY idx_cf_patient (patient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('save_clinical_findings')) {
    function save_clinical_findings($conn, int $recordId, int $patientId, array $visitClinicalData): void
    {
        ensure_clinical_findings_table($conn);
        $json = json_encode($visitClinicalData);
        $stmt = $conn->prepare('INSERT INTO clinical_findings (record_id, patient_id, findings_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id), findings_json = VALUES(findings_json)');
        if ($stmt) {
            $stmt->bind_param('iis', $recordId, $patientId, $json);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('get_clinical_findings_by_record')) {
    function get_clinical_findings_by_record($conn, int $recordId): array
    {
        ensure_clinical_findings_table($conn);
        $stmt = $conn->prepare('SELECT findings_json FROM clinical_findings WHERE record_id = ? LIMIT 1');
        if (!$stmt) return [];
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['findings_json'])) return [];
        $decoded = json_decode($row['findings_json'], true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('clinical_findings_carry_over_field_keys')) {
    /**
     * The only findings carried from one visit to the next: facts about the patient that
     * stay the same between visits — the occlusal classification (changes only with
     * orthodontic treatment or surgery) and standing medical history. Everything else is
     * what the doctor sees or measures on the day (gingiva, oral hygiene, tongue, lymph
     * nodes, vitals and lab readings, radiographic interpretation, any operation done) and
     * must be recorded fresh for every visit.
     */
    function clinical_findings_carry_over_field_keys(): array
    {
        return [
            'occlusion', 'cl_i_type', 'cl_ii_division', 'cl_ii_subdivision', 'cl_ii_type',
            'cl_iii_division', 'cl_iii_subdivision',
            'allergy_notes', 'blood_diseases', 'fainting',
        ];
    }
}

if (!function_exists('get_previous_clinical_findings')) {
    /**
     * The patient's most recent carry-over findings from another visit (see
     * clinical_findings_carry_over_field_keys()), used as the starting values of a new
     * session. Null when there is nothing to carry over.
     *
     * @return array{findings: array, visit_date: ?string}|null
     */
    function get_previous_clinical_findings($conn, int $patientId, int $excludeAppointmentId): ?array
    {
        if ($patientId <= 0) return null;
        ensure_clinical_findings_table($conn);
        $stmt = $conn->prepare('
            SELECT cf.findings_json, a.appointment_date
            FROM clinical_findings cf
            INNER JOIN clinical_records cr ON cr.record_id = cf.record_id
            LEFT JOIN appointments a ON a.appointment_id = cr.appointment_id
            WHERE cr.patient_id = ? AND cr.appointment_id <> ?
            ORDER BY a.appointment_date DESC, cf.record_id DESC
            LIMIT 10
        ');
        if (!$stmt) return null;
        $stmt->bind_param('ii', $patientId, $excludeAppointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = null;
        $carryOver = array_flip(clinical_findings_carry_over_field_keys());
        // A visit with none of these fields filled has nothing to offer — look further back.
        while ($found === null && ($row = $res->fetch_assoc())) {
            $decoded = json_decode((string)$row['findings_json'], true);
            if (!is_array($decoded)) continue;
            $carried = array_filter(
                array_intersect_key(filter_visit_clinical_data($decoded), $carryOver),
                function ($v) { return $v !== '' && $v !== null && $v !== false && $v !== 0 && $v !== '0'; }
            );
            if ($carried) {
                $found = [
                    'findings' => $carried,
                    'visit_date' => $row['appointment_date'] ? substr((string)$row['appointment_date'], 0, 10) : null,
                ];
            }
        }
        $stmt->close();
        return $found;
    }
}

// Fields the doctor actually relies on day-to-day; shown first / promoted in the UI.
// The rest (Clinical Assessment, Vitals & Lab Findings) are optional and render as N/A when blank.
if (!function_exists('occlusal_classification_field_keys')) {
    function occlusal_classification_field_keys(): array
    {
        return [
            'occlusion', 'cl_i_type', 'cl_ii_division', 'cl_ii_subdivision', 'cl_ii_type',
            'cl_iii_division', 'cl_iii_subdivision', 'operation_if_any',
        ];
    }
}

if (!function_exists('format_visit_clinical_value_for_display')) {
    function format_visit_clinical_value_for_display($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'N/A';
        }
        $value = trim((string)($value ?? ''));
        return $value === '' ? 'N/A' : $value;
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
