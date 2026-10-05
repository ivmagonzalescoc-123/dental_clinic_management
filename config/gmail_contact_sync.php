<?php
// Pulls customer emails sent directly to the clinic's Gmail inbox (not through
// the website's Contact Us form) into the contact_messages table, then marks
// each imported email as read (\Seen) in Gmail so it stops showing as unread there.
//
// Uses webklex/php-imap (pure-PHP IMAP client over a socket) instead of the
// native ext-imap module, because shared hosts such as InfinityFree don't
// allow enabling ext-imap.

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/contact_messages_helpers.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

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

if (!function_exists('gmail_contact_sync_account_config')) {
    function gmail_contact_sync_account_config(): array
    {
        $host = trim((string)(function_exists('env') ? env('IMAP_HOST', 'imap.gmail.com') : 'imap.gmail.com'));
        $port = (int)(function_exists('env') ? env('IMAP_PORT', 993) : 993);

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => trim((string)env('SMTP_USER', '')),
            'password' => (string)env('SMTP_PASS', ''),
            'authentication' => null,
        ];
    }
}

if (!function_exists('gmail_contact_sync_extract_text')) {
    function gmail_contact_sync_extract_text(Message $message): string
    {
        $plain = trim($message->getTextBody());
        if ($plain !== '') {
            return $plain;
        }

        $html = trim($message->getHTMLBody());
        if ($html !== '') {
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
        if (!gmail_contact_sync_configured()) {
            return ['success' => false, 'imported' => 0, 'message' => 'Gmail is not configured. Set SMTP_USER and SMTP_PASS (Google App Password) in .env.'];
        }

        contact_messages_ensure_table($conn);

        try {
            $client = (new ClientManager())->make(gmail_contact_sync_account_config());
            $client->connect();
            $inbox = $client->getFolder('INBOX');
            if (!$inbox) {
                return ['success' => false, 'imported' => 0, 'message' => 'Unable to open the Gmail inbox.'];
            }
            $messages = $inbox->messages()->whereUnseen()->leaveUnread()->get();
        } catch (\Throwable $e) {
            return ['success' => false, 'imported' => 0, 'message' => 'Unable to connect to Gmail: ' . $e->getMessage()];
        }

        $insert = $conn->prepare(
            "INSERT IGNORE INTO contact_messages (full_name, email, phone_number, message, html_body, status, source, gmail_uid)
             VALUES (?, ?, NULL, ?, ?, 'new', 'gmail', ?)"
        );
        if (!$insert) {
            return ['success' => false, 'imported' => 0, 'message' => 'Database error preparing insert.'];
        }

        $imported = 0;
        $skipped = 0;

        foreach ($messages as $message) {
            /** @var Message $message */
            $from = $message->getFrom()->first();
            $fromEmail = $from ? strtolower((string)$from->mail) : '';
            $fromName = $from ? trim(contact_messages_decode_header((string)$from->personal)) : '';
            if ($fromName === '') {
                $fromName = $fromEmail;
            }
            $fromName = mb_substr($fromName, 0, 150);

            // Gmail sends non-ASCII subjects (emoji, ñ, ’) MIME-encoded: "=?UTF-8?Q?...?=".
            $subject = trim(contact_messages_decode_header((string)$message->getSubject())) ?: '(no subject)';
            $body = gmail_contact_sync_extract_text($message);
            $fullMessage = mb_substr(trim("Subject: {$subject}\n\n{$body}"), 0, 5000);

            // Keep the original HTML (capped at 2 MB) so the email can be shown with its layout.
            $html = trim((string)$message->getHTMLBody());
            $htmlBody = ($html !== '' && strlen($html) <= 2 * 1024 * 1024) ? $html : null;

            $gmailUid = trim((string)$message->getMessageId()) ?: ('uid-' . $message->getUid());
            $gmailUid = mb_substr($gmailUid, 0, 255);

            $insert->bind_param('sssss', $fromName, $fromEmail, $fullMessage, $htmlBody, $gmailUid);
            $insert->execute();

            if ($insert->affected_rows > 0) {
                $imported++;
            } else {
                $skipped++;
            }

            // Mark read in Gmail regardless of import result, so it doesn't linger as unread.
            try {
                $message->setFlag('Seen');
            } catch (\Throwable $e) {
                // Non-fatal: message was still imported (or already seen).
            }
        }

        $insert->close();

        $message = $imported > 0
            ? "Imported {$imported} new message(s) from Gmail."
            : 'No new contact messages found in Gmail.';

        return ['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => $message];
    }
}
