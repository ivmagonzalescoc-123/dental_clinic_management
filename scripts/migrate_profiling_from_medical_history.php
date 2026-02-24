<?php
// CLI migration: consolidate medical_history table into patients.profiling JSON
require __DIR__ . '/../config/database.php';

echo "Starting migration: medical_history -> patients.profiling\n";

// ensure profiling column exists
$colRes = $conn->query("SHOW COLUMNS FROM patients LIKE 'profiling'");
if (!$colRes || $colRes->num_rows === 0) {
    echo "Adding profiling column to patients...\n";
    $conn->query("ALTER TABLE patients ADD COLUMN profiling JSON DEFAULT NULL");
}

// fetch all patients with medical_history entries
$res = $conn->query('SELECT DISTINCT patients_id FROM medical_history');
$patientIds = [];
while ($r = $res->fetch_assoc()) $patientIds[] = (int)$r['patients_id'];

echo "Found " . count($patientIds) . " patients with medical_history entries\n";

$updated = 0;
foreach ($patientIds as $pid) {
    $stmt = $conn->prepare('SELECT history_type, history_from, history_to, detailed_info FROM medical_history WHERE patients_id = ? ORDER BY history_from ASC');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $mres = $stmt->get_result();
    $hist = [];
    while ($row = $mres->fetch_assoc()) {
        $hist[] = [
            'type' => $row['history_type'],
            'from' => $row['history_from'],
            'to' => $row['history_to'],
            'details' => $row['detailed_info']
        ];
    }
    $stmt->close();
    if (empty($hist)) continue;

    // fetch existing profiling
    $pstmt = $conn->prepare('SELECT profiling FROM patients WHERE patient_id = ? LIMIT 1');
    $pstmt->bind_param('i', $pid);
    $pstmt->execute();
    $pres = $pstmt->get_result();
    $prow = $pres ? $pres->fetch_assoc() : null;
    $pstmt->close();
    $existing = null;
    if ($prow && isset($prow['profiling']) && $prow['profiling'] !== null) {
        $decoded = json_decode($prow['profiling'], true);
        if ($decoded !== null) $existing = $decoded;
    }
    if (!is_array($existing)) $existing = [];
    // merge medical_history into profiling.medical_history (append)
    if (!isset($existing['medical_history']) || !is_array($existing['medical_history'])) $existing['medical_history'] = [];
    $existing['medical_history'] = array_merge($existing['medical_history'], $hist);

    $json = json_encode($existing, JSON_UNESCAPED_UNICODE);
    $ust = $conn->prepare('UPDATE patients SET profiling = ? WHERE patient_id = ?');
    $ust->bind_param('si', $json, $pid);
    if (!$ust->execute()) {
        echo "Failed to update patient {$pid}: " . $ust->error . "\n";
        $ust->close();
        continue;
    }
    $ust->close();

    // insert audit row
    $changed_by = 1; // system user id; adjust if needed
    $ast = $conn->prepare('INSERT INTO patient_profiling_audit (patient_id, profiling, changed_by) VALUES (?, ?, ?)');
    $ast->bind_param('isi', $pid, $json, $changed_by);
    $ast->execute();
    $ast->close();

    $updated++;
}

echo "Migration complete. Updated profiling for {$updated} patients.\n";
?>