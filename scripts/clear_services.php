<?php
require_once __DIR__ . '/../config/database.php';

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("TRUNCATE TABLE services");
$conn->query("TRUNCATE TABLE timeslot_service");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "Cleared services and timeslot_service" . PHP_EOL;