<?php
/**
 * Quick phrases: the one-click common entries under the fields of the doctor's Summary
 * step (Doctor's Notes, Clinical Summary, Chief Complaint, Case History). Each doctor has
 * their own list per field and can add to or remove from it; a doctor who has never
 * touched theirs starts from the clinic defaults below.
 */

if (!function_exists('quick_phrases_ensure_table')) {
    function quick_phrases_ensure_table(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $conn->query("
            CREATE TABLE IF NOT EXISTS doctor_quick_phrases (
                phrase_id INT AUTO_INCREMENT PRIMARY KEY,
                doctor_id INT NOT NULL,
                field_key VARCHAR(40) NOT NULL,
                phrase VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dqp_doctor_field (doctor_id, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $done = true;
    }
}

if (!function_exists('quick_phrases_defaults')) {
    /** Field key (the textarea's id on the session page) => starting phrases. */
    function quick_phrases_defaults(): array
    {
        return [
            'doctor_notes' => [
                'Follow-up appointment needed.',
                'Return after 1 week.',
                'Return after 6 months for check-up and cleaning.',
                'Patient tolerated the procedure well.',
                'Post-operative instructions given.',
                'Oral hygiene instructions given.',
                'Prescription given.',
                'No complications.',
                'Referred for X-ray.',
            ],
            'doctor_summary' => [
                'Treatment completed as planned.',
                'Oral prophylaxis done.',
                'Tooth restored with composite filling.',
                'Tooth extracted, bleeding controlled.',
                'Treatment ongoing, to be continued next visit.',
                'For observation.',
            ],
            'doctor_chief_complaint' => [
                'Toothache', 'Tooth sensitivity', 'Bleeding gums', 'Swelling', 'Broken or chipped tooth',
                'Loose tooth', 'Routine check-up', 'Cleaning', 'Braces adjustment',
            ],
            'doctor_case_history' => [
                'No relevant medical history', 'No known allergies', 'Hypertensive, on maintenance medication',
                'Diabetic', 'Previous extraction', 'Currently in orthodontic treatment', 'First dental visit',
            ],
        ];
    }
}

if (!defined('QUICK_PHRASES_SEEDED_KEY')) {
    // Marker row: this doctor's lists were already started from the defaults, so a list
    // they have emptied on purpose is not refilled.
    define('QUICK_PHRASES_SEEDED_KEY', '_seeded');
    define('QUICK_PHRASES_MAX_PER_FIELD', 30);
    define('QUICK_PHRASES_MAX_LENGTH', 150);
}

if (!function_exists('quick_phrases_seed_if_needed')) {
    function quick_phrases_seed_if_needed(mysqli $conn, int $doctorId): void
    {
        $seededKey = QUICK_PHRASES_SEEDED_KEY;
        $stmt = $conn->prepare('SELECT 1 FROM doctor_quick_phrases WHERE doctor_id = ? AND field_key = ? LIMIT 1');
        $stmt->bind_param('is', $doctorId, $seededKey);
        $stmt->execute();
        $seeded = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($seeded) return;

        $ins = $conn->prepare('INSERT INTO doctor_quick_phrases (doctor_id, field_key, phrase) VALUES (?, ?, ?)');
        $marker = '';
        $ins->bind_param('iss', $doctorId, $seededKey, $marker);
        $ins->execute();
        foreach (quick_phrases_defaults() as $fieldKey => $phrases) {
            foreach ($phrases as $phrase) {
                $ins->bind_param('iss', $doctorId, $fieldKey, $phrase);
                $ins->execute();
            }
        }
        $ins->close();
    }
}

if (!function_exists('quick_phrases_list')) {
    /** @return array<string, array<array{id:int, phrase:string}>> every field key, even when empty */
    function quick_phrases_list(mysqli $conn, int $doctorId): array
    {
        quick_phrases_ensure_table($conn);
        quick_phrases_seed_if_needed($conn, $doctorId);

        $out = array_fill_keys(array_keys(quick_phrases_defaults()), []);
        $stmt = $conn->prepare('SELECT phrase_id, field_key, phrase FROM doctor_quick_phrases WHERE doctor_id = ? ORDER BY phrase_id ASC');
        $stmt->bind_param('i', $doctorId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (isset($out[$row['field_key']])) {
                $out[$row['field_key']][] = ['id' => (int)$row['phrase_id'], 'phrase' => (string)$row['phrase']];
            }
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('quick_phrases_add')) {
    /** @return array{0: ?array{id:int, phrase:string}, 1: string} the new phrase, or null and why not */
    function quick_phrases_add(mysqli $conn, int $doctorId, string $fieldKey, string $phrase): array
    {
        $phrase = trim(preg_replace('/\s+/u', ' ', $phrase));
        if (!array_key_exists($fieldKey, quick_phrases_defaults())) {
            return [null, 'Unknown field.'];
        }
        if ($phrase === '') {
            return [null, 'Type the phrase to add.'];
        }
        if (mb_strlen($phrase) > QUICK_PHRASES_MAX_LENGTH) {
            return [null, 'Keep the phrase under ' . QUICK_PHRASES_MAX_LENGTH . ' characters.'];
        }

        $existing = quick_phrases_list($conn, $doctorId)[$fieldKey];
        if (count($existing) >= QUICK_PHRASES_MAX_PER_FIELD) {
            return [null, 'This list is full. Remove a phrase before adding another.'];
        }
        foreach ($existing as $item) {
            if (mb_strtolower($item['phrase']) === mb_strtolower($phrase)) {
                return [null, 'That phrase is already in the list.'];
            }
        }

        $ins = $conn->prepare('INSERT INTO doctor_quick_phrases (doctor_id, field_key, phrase) VALUES (?, ?, ?)');
        $ins->bind_param('iss', $doctorId, $fieldKey, $phrase);
        $ins->execute();
        $id = (int)$conn->insert_id;
        $ins->close();
        return [['id' => $id, 'phrase' => $phrase], ''];
    }
}

if (!function_exists('quick_phrases_delete')) {
    function quick_phrases_delete(mysqli $conn, int $doctorId, int $phraseId): bool
    {
        quick_phrases_ensure_table($conn);
        $seededKey = QUICK_PHRASES_SEEDED_KEY;
        $del = $conn->prepare('DELETE FROM doctor_quick_phrases WHERE phrase_id = ? AND doctor_id = ? AND field_key <> ?');
        $del->bind_param('iis', $phraseId, $doctorId, $seededKey);
        $del->execute();
        $deleted = $del->affected_rows > 0;
        $del->close();
        return $deleted;
    }
}
