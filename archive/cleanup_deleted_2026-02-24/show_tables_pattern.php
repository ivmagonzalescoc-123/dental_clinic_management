<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain');
$patterns = ['%schedule%','%avail%','%notification%'];
foreach ($patterns as $p) {
    $res = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($p)."'");
    echo "Pattern: $p -> ";
    if ($res && $res->num_rows) {
        while ($r = $res->fetch_row()) echo $r[0] . ' ';
        echo PHP_EOL;
    } else echo "(no match)" . PHP_EOL;
}
?>