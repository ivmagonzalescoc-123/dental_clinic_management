<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

function check($conn, $sql, $label) {
    $res = $conn->query($sql);
    if (!$res) {
        echo "$label: ERROR - " . $conn->error . PHP_EOL;
        return;
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo "$label:\n";
    if (empty($rows)) echo "  (no rows)\n";
    else {
        foreach ($rows as $row) {
            echo '  - ' . implode(' | ', array_map(function($k,$v){ return "$k=$v"; }, array_keys($row), $row)) . PHP_EOL;
        }
    }
}

echo "Checking DB connection...\n";
if (!$conn) { echo "No DB connection.\n"; exit(1); }
echo "Connected to DB: " . DB_NAME . "@" . DB_HOST . "\n\n";

// Tables
$tables = ['doctor_availability','schedules','schedule_reminders','notifications','clinical_records','migrations_applied','appointments','billing'];
foreach ($tables as $t) {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
    echo "Table $t: " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;
}

echo "\nChecking clinical_records columns...\n";
$res = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'schedule_id'");
echo "schedule_id column: " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;

echo "\nRecent migrations_applied entries:\n";
$res = $conn->query("SELECT filename, applied_at FROM migrations_applied ORDER BY applied_at DESC LIMIT 20");
if ($res) {
    while ($r = $res->fetch_assoc()) echo "  - {$r['filename']} @ {$r['applied_at']}\n";
} else {
    echo "  (cannot read migrations_applied: " . $conn->error . ")\n";
}

echo "\nCheck complete.\n";

?>