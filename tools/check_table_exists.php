<?php
include __DIR__ . '/../config/database.php';
$tbls = ['schedules','schedule_reminders','notifications'];
foreach ($tbls as $t) {
    echo $t . ': ' . (table_exists($conn, $t) ? "YES\n" : "NO\n");
}
