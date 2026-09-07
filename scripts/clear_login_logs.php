<?php
require_once __DIR__ . '/../config/database.php';

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("TRUNCATE TABLE system_logs");
$conn->query("TRUNCATE TABLE user_logs");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "Cleared system_logs and user_logs" . PHP_EOL;