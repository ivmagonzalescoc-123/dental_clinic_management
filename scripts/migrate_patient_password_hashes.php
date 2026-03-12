<?php
// One-time migration: hash legacy plaintext patient passwords stored in password_hash.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection not available.\n");
    exit(1);
}

$select = $conn->prepare('SELECT patient_id, password_hash FROM patients WHERE password_hash IS NOT NULL AND password_hash <> ""');
if (!$select) {
    fwrite(STDERR, "Failed to prepare select: " . $conn->error . "\n");
    exit(1);
}

$select->execute();
$res = $select->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$select->close();

$total = count($rows);
$hashedCount = 0;
$skippedCount = 0;

$update = $conn->prepare('UPDATE patients SET password_hash = ?, password_updated_at = COALESCE(password_updated_at, NOW()) WHERE patient_id = ?');
if (!$update) {
    fwrite(STDERR, "Failed to prepare update: " . $conn->error . "\n");
    exit(1);
}

foreach ($rows as $row) {
    $patientId = (int)$row['patient_id'];
    $stored = (string)$row['password_hash'];

    if ($stored === '' || security_is_password_hash($stored)) {
        $skippedCount++;
        continue;
    }

    $newHash = security_hash_password($stored);
    $update->bind_param('si', $newHash, $patientId);
    if (!$update->execute()) {
        fwrite(STDERR, "Failed updating patient_id {$patientId}: " . $update->error . "\n");
        continue;
    }

    $hashedCount++;
}

$update->close();

echo "Patient password hash migration complete.\n";
echo "Total checked: {$total}\n";
echo "Hashed now: {$hashedCount}\n";
echo "Already hashed or empty: {$skippedCount}\n";
