<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain');
function sc($conn, $table){
    $res = $conn->query("SHOW CREATE TABLE `".$conn->real_escape_string($table)."`");
    if (!$res) { echo "SHOW CREATE TABLE $table failed: " . $conn->error . "\n"; return; }
    $r = $res->fetch_assoc();
    echo "--- $table ---\n";
    echo ($r['Create Table'] ?? $r['Create View'] ?? "(no create)") . "\n\n";
}

sc($conn,'users');
sc($conn,'appointments');
sc($conn,'clinical_records');
sc($conn,'schedule');
sc($conn,'doctor_availability');
sc($conn,'notifications');
?>