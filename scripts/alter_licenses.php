<?php
require_once __DIR__ . '/../config/database.php';

$sql = "ALTER TABLE doctor_prescription_profiles 
    DROP COLUMN license_type_1,
    DROP COLUMN license_number_1,
    DROP COLUMN license_type_2,
    DROP COLUMN license_number_2,
    DROP COLUMN license_type_3,
    DROP COLUMN license_number_3,
    ADD COLUMN licenses JSON DEFAULT NULL AFTER professional_title";

if ($conn->query($sql)) {
    echo "Table altered successfully!" . PHP_EOL;
} else {
    echo "Error: " . $conn->error . PHP_EOL;
}

// Show new structure
$res = $conn->query("DESCRIBE doctor_prescription_profiles");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}