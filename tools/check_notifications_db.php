<?php
require_once __DIR__ . '/../config/database.php';

global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "DB connection failed\n";
    exit(1);
}

echo "Checking notifications table and sample counts...\n";

$tbl = 'notifications';
$exists = table_exists($conn, $tbl) ? 'YES' : 'NO';
echo "table_exists: $exists\n";
if ($exists === 'NO') {
    exit(0);
}

// print columns
$cols = [];
$res = $conn->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = '$tbl'");
if ($res) {
    while ($r = $res->fetch_assoc()) $cols[] = $r['COLUMN_NAME'] . ':' . $r['COLUMN_TYPE'];
    echo "columns: " . implode(', ', $cols) . "\n";
}

// total count
$row = $conn->query("SELECT COUNT(*) AS c FROM notifications")->fetch_assoc();
echo "total notifications: " . ($row['c'] ?? 0) . "\n";

// sample counts per user (top 10)
$q = "SELECT user_id, COUNT(*) AS c, SUM(is_read=0) AS unread FROM notifications GROUP BY user_id ORDER BY c DESC LIMIT 10";
$r = $conn->query($q);
if ($r) {
    echo "top users:\n";
    while ($rr = $r->fetch_assoc()) {
        echo " - user_id=" . $rr['user_id'] . " total=" . $rr['c'] . " unread=" . $rr['unread'] . "\n";
    }
}

// show recent per-user sample (user ids from top)
$r->data_seek(0);
$uids = [];
while ($rr = $r->fetch_assoc()) $uids[] = $rr['user_id'];
foreach ($uids as $uid) {
    $stmt = $conn->prepare('SELECT notification_id, title, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        echo "\nRecent for user_id=$uid:\n";
        while ($rrr = $res->fetch_assoc()) {
            echo "  " . $rrr['notification_id'] . " | " . $rrr['title'] . " | is_read=" . $rrr['is_read'] . " | " . $rrr['created_at'] . "\n";
        }
        $stmt->close();
    }
}

$conn->close();

?>
