<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain');
$db = DB_NAME;
$sql = "SELECT constraint_name, table_name, constraint_type FROM information_schema.table_constraints WHERE table_schema = '".$conn->real_escape_string($db)."' AND constraint_name LIKE 'fk_%' ORDER BY table_name";
$res = $conn->query($sql);
if (!$res) { echo "Query failed: " . $conn->error; exit(1); }
while ($r = $res->fetch_assoc()) {
    echo "{$r['constraint_name']} on {$r['table_name']} ({$r['constraint_type']})\n";
}
?>