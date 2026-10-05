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

        // Original HTML of an imported Gmail message, so it can be shown with its own layout.
        // SHOW COLUMNS check instead of ADD COLUMN IF NOT EXISTS, which MySQL 8 doesn't support.
        $col = $conn->query("SHOW COLUMNS FROM contact_messages LIKE 'html_body'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE contact_messages ADD COLUMN html_body MEDIUMTEXT NULL AFTER message");
        }
    }
}

if (!function_exists('contact_messages_decode_header')) {
    /** Decodes MIME encoded-words like "=?UTF-8?Q?=E2=9C=85_Hello?=" into plain UTF-8 text. */
    function contact_messages_decode_header(string $value): string
    {
        if (strpos($value, '=?') === false) {
            return $value;
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        }
        return $value;
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
