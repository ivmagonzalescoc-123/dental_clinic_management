<?php
// Pulls customer emails sent directly to the clinic's Gmail inbox (not through
// the website's Contact Us form) into the contact_messages table, then marks
// each imported email as read (\Seen) in Gmail so it stops showing as unread there.

if (!function_exists('gmail_contact_sync_configured')) {
    function gmail_contact_sync_configured(): bool
    {
        if (!function_exists('env')) {
            return false;
        }
        $user = trim((string)env('SMTP_USER', ''));
        $pass = trim((string)env('SMTP_PASS', ''));
        return $user !== '' && $pass !== '';
    }
}

if (!function_exists('gmail_contact_sync_mailbox_string')) {
    function gmail_contact_sync_mailbox_string(): string
    {
        $host = trim((string)(function_exists('env') ? env('IMAP_HOST', 'imap.gmail.com') : 'imap.gmail.com'));
        $port = (int)(function_exists('env') ? env('IMAP_PORT', 993) : 993);
        return '{' . $host . ':' . $port . '/imap/ssl}INBOX';
    }
}

if (!function_exists('gmail_contact_sync_find_part_body')) {
    // Recursively looks for a MIME part matching $wantSubtype ('PLAIN' or 'HTML').
    // Returns decoded body text, or null if not found in this branch.
    function gmail_contact_sync_find_part_body($imap, $uid, $structure, string $prefix, string $wantSubtype): ?string
    {
        if (!empty($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $section = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);
                $found = gmail_contact_sync_find_part_body($imap, $uid, $part, $section, $wantSubtype);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        }

        if (strtoupper((string)($structure->subtype ?? '')) !== $wantSubtype) {
            return null;
        }

        $section = $prefix === '' ? '1' : $prefix;
        $raw = imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);

        if ((int)($structure->encoding ?? 0) === 3) {
            $raw = base64_decode($raw);
        } elseif ((int)($structure->encoding ?? 0) === 4) {
            $raw = quoted_printable_decode($raw);
        }

        return $raw;
    }
}

if (!function_exists('gmail_contact_sync_extract_text')) {
    function gmail_contact_sync_extract_text($imap, $uid): string
    {
        $structure = @imap_fetchstructure($imap, $uid, FT_UID);
        if (!$structure) {
            return trim((string)imap_fetchbody($imap, $uid, '1', FT_UID | FT_PEEK));
        }

        $plain = gmail_contact_sync_find_part_body($imap, $uid, $structure, '', 'PLAIN');
        if ($plain !== null && trim($plain) !== '') {
            return trim($plain);
        }

        $html = gmail_contact_sync_find_part_body($imap, $uid, $structure, '', 'HTML');
        if ($html !== null && trim($html) !== '') {
            $text = preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $html);
            $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8');
            return trim(preg_replace("/\n{3,}/", "\n\n", $text));
        }

        return '';
    }
}

if (!function_exists('gmail_contact_sync_fetch')) {
    function gmail_contact_sync_fetch(mysqli $conn): array
    {
        if (!extension_loaded('imap')) {
            return ['success' => false, 'imported' => 0, 'message' => 'The PHP IMAP extension is not enabled on this server.'];
        }

        if (!gmail_contact_sync_configured()) {
            return ['success' => false, 'imported' => 0, 'message' => 'Gmail is not configured. Set SMTP_USER and SMTP_PASS (Google App Password) in .env.'];
        }

        contact_messages_ensure_table($conn);

        $user = trim((string)env('SMTP_USER', ''));
        $pass = (string)env('SMTP_PASS', '');
        $mailbox = gmail_contact_sync_mailbox_string();

        $imap = @imap_open($mailbox, $user, $pass);
        if (!$imap) {
            $err = imap_last_error();
            return ['success' => false, 'imported' => 0, 'message' => 'Unable to connect to Gmail: ' . ($err ?: 'unknown error')];
        }

        $uids = imap_search($imap, 'UNSEEN', SE_UID);
        if ($uids === false) {
            imap_close($imap);
            return ['success' => true, 'imported' => 0, 'message' => 'No new messages in the inbox.'];
        }

        $insert = $conn->prepare(
            "INSERT IGNORE INTO contact_messages (full_name, email, phone_number, message, status, source, gmail_uid)
             VALUES (?, ?, NULL, ?, 'new', 'gmail', ?)"
        );
        if (!$insert) {
            imap_close($imap);
            return ['success' => false, 'imported' => 0, 'message' => 'Database error preparing insert.'];
        }

        $imported = 0;
        $skipped = 0;

        foreach ($uids as $uid) {
            $overview = imap_fetch_overview($imap, (string)$uid, FT_UID);
            if (!$overview) {
                continue;
            }
            $ov = $overview[0];

            $fromRaw = (string)($ov->from ?? '');
            $fromName = '';
            $fromEmail = $fromRaw;
            $addrs = imap_rfc822_parse_adrlist($fromRaw, '');
            if (!empty($addrs)) {
                $a = $addrs[0];
                $fromEmail = strtolower($a->mailbox . '@' . $a->host);
                $fromName = isset($a->personal) ? imap_utf8($a->personal) : '';
            }
            if ($fromName === '') {
                $fromName = $fromEmail;
            }

            $subject = isset($ov->subject) ? imap_utf8($ov->subject) : '(no subject)';
            $body = gmail_contact_sync_extract_text($imap, $uid);
            $fullMessage = mb_substr(trim("Subject: {$subject}\n\n{$body}"), 0, 5000);

            $gmailUid = (string)($ov->message_id ?? ('uid-' . $uid));
            $gmailUid = mb_substr($gmailUid, 0, 255);

            $insert->bind_param('ssss', $fromName, $fromEmail, $fullMessage, $gmailUid);
            $insert->execute();

            if ($insert->affected_rows > 0) {
                $imported++;
            } else {
                $skipped++;
            }

            // Mark read in Gmail regardless of import result, so it doesn't linger as unread.
            imap_setflag_full($imap, (string)$uid, '\\Seen', FT_UID);
        }

        $insert->close();
        imap_close($imap);

        $message = $imported > 0
            ? "Imported {$imported} new message(s) from Gmail."
            : 'No new contact messages found in Gmail.';

        return ['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => $message];
    }
}
