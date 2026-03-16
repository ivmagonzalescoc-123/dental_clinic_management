<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';

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
$requiredTables = ['doctor_availability','patient_schedules','schedule_reminders','notifications','clinical_records','appointments','billing'];
foreach ($requiredTables as $t) {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
    echo "Table $t: " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;
}
$res = $conn->query("SHOW TABLES LIKE 'migrations_applied'");
echo "Table migrations_applied (optional): " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;

echo "\nChecking clinical_records columns...\n";
$res = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'schedule_id'");
echo "legacy schedule_id column (should be MISSING): " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;

echo "\nChecking schedule_reminders columns...\n";
$res = $conn->query("SHOW COLUMNS FROM schedule_reminders LIKE 'patient_schedule_id'");
echo "patient_schedule_id column (should be FOUND): " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;
$res = $conn->query("SHOW COLUMNS FROM schedule_reminders LIKE 'schedule_id'");
echo "legacy schedule_id column (should be MISSING): " . ($res && $res->num_rows ? 'FOUND' : 'MISSING') . PHP_EOL;

echo "\nRecent migrations_applied entries:\n";
$exists = $conn->query("SHOW TABLES LIKE 'migrations_applied'");
if ($exists && $exists->num_rows) {
    $res = $conn->query("SELECT filename, applied_at FROM migrations_applied ORDER BY applied_at DESC LIMIT 20");
    if ($res) {
        while ($r = $res->fetch_assoc()) echo "  - {$r['filename']} @ {$r['applied_at']}\n";
    } else {
        echo "  (cannot read migrations_applied: " . $conn->error . ")\n";
    }
} else {
    echo "  (table missing; this is expected after full schema import without migration history)\n";
}

echo "\nCheck complete.\n";

?>