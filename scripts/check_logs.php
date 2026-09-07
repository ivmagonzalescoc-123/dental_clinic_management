<?php
require_once __DIR__ . '/../config/database.php';

echo "=== system_logs (login/logout) ===" . PHP_EOL;
$res = $conn->query("SELECT COUNT(*) as c FROM system_logs");
print_r($res->fetch_assoc());

$res = $conn->query("SELECT * FROM system_logs WHERE action IN ('auth.login', 'auth.logout') ORDER BY created_at DESC LIMIT 10");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n=== user_logs (patient login) ===" . PHP_EOL;
$res = $conn->query("SELECT COUNT(*) as c FROM user_logs");
print_r($res->fetch_assoc());