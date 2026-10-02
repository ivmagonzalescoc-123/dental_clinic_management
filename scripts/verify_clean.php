<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Admin User ===" . PHP_EOL;
$res = $conn->query("SELECT user_id, first_name, last_name, email, role_id, is_active FROM users");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n=== Services ===" . PHP_EOL;
$res = $conn->query("SELECT service_id, service_name, initial_price FROM services");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n=== Roles ===" . PHP_EOL;
$res = $conn->query("SELECT * FROM role");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n=== Specializations ===" . PHP_EOL;
$res = $conn->query("SELECT * FROM specialization");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n=== Timeslots ===" . PHP_EOL;
$res = $conn->query("SELECT COUNT(*) as count FROM timeslot");
print_r($res->fetch_assoc());

echo "\nDone!" . PHP_EOL;