<?php
/**
 * Simple migration runner for local environment.
 * Reads all .sql files in migrations/ and applies them once using a migrations_applied table.
 */
require_once __DIR__ . '/../config/database.php';

function ensureMigrationsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS migrations_applied (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    if (!$conn->query($sql)) {
        echo "Failed to ensure migrations table: " . $conn->error . PHP_EOL;
        exit(1);
    }
}

function getSqlFiles($dir) {
    $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "*.sql");
    sort($files, SORT_NATURAL);
    return $files;
}

ensureMigrationsTable($conn);
$files = getSqlFiles(__DIR__);
if (empty($files)) {
    echo "No migration files found in migrations/.\n";
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);
    // check applied
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM migrations_applied WHERE filename = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    if ($row && (int)$row['c'] > 0) {
        echo "Skipping already applied: $name\n";
        continue;
    }

    echo "Applying: $name\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "Failed to read $file\n";
        exit(1);
    }

    // Execute multi-statement SQL
    if ($conn->multi_query($sql)) {
        do {
            if ($res = $conn->store_result()) {
                $res->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        if ($conn->errno) {
            echo "Error applying $name: " . $conn->error . PHP_EOL;
            exit(1);
        }
        // mark applied
        $ap = $conn->prepare('INSERT INTO migrations_applied (filename, applied_at) VALUES (?, NOW())');
        $ap->bind_param('s', $name);
        $ap->execute();
        $ap->close();
        echo "Applied: $name\n";
    } else {
        echo "Failed to execute SQL for $name: " . $conn->error . PHP_EOL;
        exit(1);
    }
}

echo "All migrations processed.\n";