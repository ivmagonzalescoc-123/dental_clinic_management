<?php

if (!function_exists('contact_messages_ensure_table')) {
    function contact_messages_ensure_table(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS contact_messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone_number VARCHAR(30) DEFAULT NULL,
            message TEXT NOT NULL,
            status ENUM('new','read','archived') NOT NULL DEFAULT 'new',
            read_by INT NULL,
            read_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_contact_status_created (status, created_at),
            KEY idx_contact_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30) DEFAULT NULL AFTER email");
        $conn->query("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'form' AFTER status");
        $conn->query("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS gmail_uid VARCHAR(255) NULL AFTER source");
        $conn->query("CREATE UNIQUE INDEX IF NOT EXISTS idx_contact_gmail_uid ON contact_messages (gmail_uid)");
    }
}

if (!function_exists('contact_messages_count_new')) {
    function contact_messages_count_new(mysqli $conn): int
    {
        contact_messages_ensure_table($conn);

        $count = 0;
        $res = $conn->query("SELECT COUNT(*) AS c FROM contact_messages WHERE status = 'new'");
        if ($res && ($row = $res->fetch_assoc())) {
            $count = (int)($row['c'] ?? 0);
        }

        return $count;
    }
}
