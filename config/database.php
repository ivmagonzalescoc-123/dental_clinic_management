<?php
/**
 * Database Connection Configuration
 * Database: capstone_dental
 * Server: MariaDB 10.4.32
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'capstone_dental');
define('DB_PORT', 3306);

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

// Check connection
if ($conn->connect_error) {
    error_log("Database Connection Failed: " . $conn->connect_error);
    die("Connection Error: Unable to connect to database. Please try again later.");
}

// Set charset to utf8mb4
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error loading character set utf8mb4: " . $conn->error);
}

// Set timezone
date_default_timezone_set('UTC');

?>
