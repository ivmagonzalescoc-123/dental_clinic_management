<?php
require_once __DIR__ . '/../config/database.php';

// config/database.php exposes $conn
global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "DB connection failed\n";
    exit(1);
}

$sql = "SELECT notification_id, user_id, title, body, link, is_read, created_at FROM notifications ORDER BY created_at DESC LIMIT 20";
$res = $conn->query($sql);
if (!$res) {
    echo "Query failed: " . $conn->error . "\n";
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    echo implode(" | ", [
        $row['notification_id'],
        $row['user_id'],
        $row['title'],
        str_replace("\n", ' ', $row['body']),
        $row['link'],
        $row['is_read'],
        $row['created_at']
    ]) . "\n";
}

$res->free();
$conn->close();

?>
