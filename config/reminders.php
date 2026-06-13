<?php

if (!function_exists('reminder_table_exists')) {
    function reminder_table_exists($conn, $tableName)
    {
        if (function_exists('table_exists')) {
            return table_exists($conn, $tableName);
        }

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
        if ($safe === '') {
            return false;
        }

        $q = "SHOW TABLES LIKE '" . $conn->real_escape_string($safe) . "'";
        $r = $conn->query($q);
        return $r && $r->num_rows > 0;
    }
}

if (!function_exists('reminder_normalize_channel')) {
    function reminder_normalize_channel($channel)
    {
        $c = strtolower(trim((string)$channel));
        if ($c === 'in_app') {
            return 'push';
        }
        if (!in_array($c, ['email', 'sms', 'push'], true)) {
            return 'push';
        }
        return $c;
    }
}

if (!function_exists('reminder_queue_once')) {
    function reminder_queue_once($conn, $scheduleId, $remindAt, $channel, array $payload)
    {
        if (!reminder_table_exists($conn, 'schedule_reminders')) {
            return false;
        }

        $normalized = reminder_normalize_channel($channel);
        $payloadJson = json_encode($payload);

        $check = $conn->prepare('SELECT reminder_id FROM schedule_reminders WHERE patient_schedule_id = ? AND channel = ? AND remind_at = ? AND payload = ? LIMIT 1');
        if (!$check) {
            return false;
        }
        $check->bind_param('isss', $scheduleId, $normalized, $remindAt, $payloadJson);
        $check->execute();
        $existing = $check->get_result();
        $already = $existing && $existing->fetch_assoc();
        $check->close();
        if ($already) {
            return true;
        }

        $ins = $conn->prepare('INSERT INTO schedule_reminders (patient_schedule_id, remind_at, channel, payload) VALUES (?, ?, ?, ?)');
        if (!$ins) {
            return false;
        }
        $ins->bind_param('isss', $scheduleId, $remindAt, $normalized, $payloadJson);
        $ok = $ins->execute();
        $ins->close();

        return (bool)$ok;
    }
}

if (!function_exists('reminder_queue_default_pack')) {
    function reminder_queue_default_pack($conn, $scheduleId, $scheduledStart, $appointmentId)
    {
        $start = DateTime::createFromFormat('Y-m-d H:i:s', (string)$scheduledStart) ?: new DateTime((string)$scheduledStart);
        if (!$start) {
            return 0;
        }

        $basePayload = [
            'appointment_id' => (int)$appointmentId,
            'schedule_id' => (int)$scheduleId,
            'patient_schedule_id' => (int)$scheduleId,
        ];

        $queued = 0;
        $dayBeforeAt = (clone $start)->modify('-24 hours');
        $staffOneHourAt = (clone $start)->modify('-1 hour');
        $patientOneHourAt = (clone $start)->modify('-1 hour');
        $dayBeforeStr = $dayBeforeAt->format('Y-m-d H:i:s');

        // 24 hours before: email only (patient)
        if (reminder_queue_once($conn, (int)$scheduleId, $dayBeforeStr, 'email', $basePayload + ['template' => 'appt_24h'])) {
            $queued++;
        }

        // 1 hour before: in-app only (staff + patient)
        if (reminder_queue_once($conn, (int)$scheduleId, $staffOneHourAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_1h_staff'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $patientOneHourAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_1h_patient'])) {
            $queued++;
        }

        return $queued;
    }
}

if (!function_exists('reminder_queue_missed_pack')) {
    function reminder_queue_missed_pack($conn, $scheduleId, $scheduledStart, $appointmentId)
    {
        $start = DateTime::createFromFormat('Y-m-d H:i:s', (string)$scheduledStart) ?: new DateTime((string)$scheduledStart);
        if (!$start) {
            return 0;
        }

        $basePayload = [
            'appointment_id' => (int)$appointmentId,
            'schedule_id' => (int)$scheduleId,
            'patient_schedule_id' => (int)$scheduleId,
        ];

        $queued = 0;
        $missedAt = (clone $start)->modify('+5 minutes');

        // 5 minutes after missed slot: SMS only (patient)
        if (reminder_queue_once($conn, (int)$scheduleId, $missedAt->format('Y-m-d H:i:s'), 'sms', $basePayload + ['template' => 'appt_missed_patient'])) {
            $queued++;
        }

        return $queued;
    }
}

if (!function_exists('reminder_queue_no_show_followup_pack')) {
    function reminder_queue_no_show_followup_pack($conn, $scheduleId, $scheduledStart, $appointmentId)
    {
        $start = DateTime::createFromFormat('Y-m-d H:i:s', (string)$scheduledStart) ?: new DateTime((string)$scheduledStart);
        if (!$start) {
            return 0;
        }

        $basePayload = [
            'appointment_id' => (int)$appointmentId,
            'schedule_id' => (int)$scheduleId,
            'patient_schedule_id' => (int)$scheduleId,
        ];

        $queued = 0;
        $noShowAt = (clone $start)->setTime(23, 0, 0);
        $now = new DateTime('now');
        if ($noShowAt < $now) {
            $noShowAt = (clone $now)->modify('+1 minute');
        }
        $noShowAtStr = $noShowAt->format('Y-m-d H:i:s');

        // No-show follow-up: email + SMS (patient)
        if (reminder_queue_once($conn, (int)$scheduleId, $noShowAtStr, 'email', $basePayload + ['template' => 'appt_no_show_patient'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $noShowAtStr, 'sms', $basePayload + ['template' => 'appt_no_show_patient'])) {
            $queued++;
        }

        return $queued;
    }
}

if (!function_exists('reminder_normalize_phone')) {
    function reminder_normalize_phone($phoneNumber)
    {
        $digits = preg_replace('/\D+/', '', (string)$phoneNumber);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10 && $digits[0] === '9') {
            return '0' . $digits;
        }
        if (strlen($digits) === 12 && substr($digits, 0, 2) === '63') {
            return '0' . substr($digits, 2);
        }
        return $digits;
    }
}

if (!function_exists('reminder_brevo_api_key')) {
    function reminder_brevo_api_key()
    {
        if (!function_exists('env')) {
            return '';
        }
        return trim((string)env('BREVO_API_KEY', ''));
    }
}

if (!function_exists('reminder_normalize_phone_brevo')) {
    function reminder_normalize_phone_brevo($phoneNumber)
    {
        $digits = preg_replace('/\D+/', '', (string)$phoneNumber);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '63' . substr($digits, 1);
        }
        if (strlen($digits) === 10 && $digits[0] === '9') {
            return '63' . $digits;
        }
        if (strlen($digits) === 12 && substr($digits, 0, 2) === '63') {
            return $digits;
        }
        return $digits;
    }
}

if (!function_exists('reminder_curl_apply_ssl_options')) {
    function reminder_curl_apply_ssl_options($ch)
    {
        $caFile = trim((string)(function_exists('env') ? env('CURL_CA_FILE', '') : ''));
        if ($caFile !== '' && is_file($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
            return;
        }

        $appEnv = strtolower((string)(function_exists('env') ? env('APP_ENV', '') : ''));
        $verify = function_exists('env') ? env('BREVO_SSL_VERIFY', null) : null;
        if ($verify === false || $verify === 'false' || $verify === '0' || $appEnv === 'development') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
    }
}

if (!function_exists('reminder_brevo_last_error')) {
    function reminder_brevo_last_error()
    {
        return $GLOBALS['_reminder_brevo_last_error'] ?? '';
    }
}

if (!function_exists('reminder_brevo_post')) {
    function reminder_brevo_post($path, array $payload, $logPrefix)
    {
        $GLOBALS['_reminder_brevo_last_error'] = '';

        $apiKey = reminder_brevo_api_key();
        if ($apiKey === '' || !function_exists('curl_init')) {
            $GLOBALS['_reminder_brevo_last_error'] = 'missing BREVO_API_KEY or curl extension';
            error_log($logPrefix . ': ' . $GLOBALS['_reminder_brevo_last_error']);
            return false;
        }

        $url = 'https://api.brevo.com/v3/' . ltrim((string)$path, '/');
        $ch = curl_init($url);
        if (!$ch) {
            return false;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $apiKey,
            ],
        ]);
        reminder_curl_apply_ssl_options($ch);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($http >= 200 && $http < 300) {
            return true;
        }

        if ($response === false || $http === 0) {
            $GLOBALS['_reminder_brevo_last_error'] = $curlError !== ''
                ? $curlError
                : 'Unable to reach Brevo API (connection or SSL error).';
        } else {
            $GLOBALS['_reminder_brevo_last_error'] = (string)$response;
        }
        error_log($logPrefix . ': http=' . $http . ' response=' . (string)$response . ' curl=' . $curlError);
        return false;
    }
}

if (!function_exists('reminder_send_email_brevo')) {
    function reminder_send_email_brevo($toEmail, $subject, $message, $htmlMessage = null)
    {
        $from = trim((string)(function_exists('env') ? env('BREVO_EMAIL_FROM', env('REMINDER_EMAIL_FROM', env('SMTP_FROM_EMAIL', ''))) : ''));
        $fromName = trim((string)(function_exists('env') ? env('BREVO_EMAIL_FROM_NAME', env('REMINDER_EMAIL_FROM_NAME', env('SMTP_FROM_NAME', "Azucena's Dental Clinic"))) : "Azucena's Dental Clinic"));

        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            error_log('REMINDER_EMAIL(brevo): invalid BREVO_EMAIL_FROM / REMINDER_EMAIL_FROM');
            return false;
        }

        $payload = [
            'sender' => [
                'name' => $fromName,
                'email' => $from,
            ],
            'to' => [
                ['email' => $toEmail],
            ],
            'subject' => $subject,
            'textContent' => $message,
        ];

        if ($htmlMessage !== null && trim((string)$htmlMessage) !== '') {
            $payload['htmlContent'] = (string)$htmlMessage;
        }

        return reminder_brevo_post('smtp/email', $payload, 'REMINDER_EMAIL(brevo)');
    }
}

if (!function_exists('reminder_send_sms_brevo')) {
    function reminder_send_sms_brevo($phoneNumber, $message)
    {
        $recipient = reminder_normalize_phone_brevo($phoneNumber);
        if ($recipient === '') {
            return false;
        }

        $sender = trim((string)(function_exists('env') ? env('BREVO_SMS_SENDER', 'AzucenaDental') : 'AzucenaDental'));
        if ($sender === '') {
            $sender = 'AzucenaDental';
        }

        return reminder_brevo_post('transactionalSMS/sms', [
            'sender' => $sender,
            'recipient' => $recipient,
            'content' => $message,
            'type' => 'transactional',
        ], 'REMINDER_SMS(brevo)');
    }
}

if (!function_exists('app_email_mode')) {
    function app_email_mode()
    {
        if (!function_exists('env')) {
            return 'log';
        }

        $raw = env('EMAIL_MODE', null);
        if ($raw === null || $raw === false || trim((string)$raw) === '') {
            $raw = env('REMINDER_EMAIL_MODE', 'log');
        }

        $mode = strtolower(trim((string)$raw));
        return in_array($mode, ['smtp', 'brevo', 'mail', 'log'], true) ? $mode : 'log';
    }
}

if (!function_exists('app_reminder_email_mode')) {
    function app_reminder_email_mode()
    {
        if (!function_exists('env')) {
            return 'log';
        }

        $raw = env('REMINDER_EMAIL_MODE', null);
        if ($raw === null || $raw === false || trim((string)$raw) === '') {
            $raw = env('EMAIL_MODE', 'log');
        }

        $mode = strtolower(trim((string)$raw));
        return in_array($mode, ['smtp', 'brevo', 'mail', 'log'], true) ? $mode : 'log';
    }
}

if (!function_exists('smtp_is_configured')) {
    function smtp_is_configured()
    {
        if (!function_exists('env')) {
            return false;
        }
        $host = trim((string)env('SMTP_HOST', ''));
        $user = trim((string)env('SMTP_USER', ''));
        $pass = trim((string)env('SMTP_PASS', ''));
        return $host !== '' && $user !== '' && $pass !== '';
    }
}

if (!function_exists('smtp_missing_message')) {
    function smtp_missing_message()
    {
        $missing = [];
        if (trim((string)env('SMTP_HOST', '')) === '') {
            $missing[] = 'SMTP_HOST';
        }
        if (trim((string)env('SMTP_USER', '')) === '') {
            $missing[] = 'SMTP_USER';
        }
        if (trim((string)env('SMTP_PASS', '')) === '') {
            $missing[] = 'SMTP_PASS';
        }
        if ($missing === []) {
            return 'SMTP is not configured.';
        }
        return 'SMTP is not configured. Missing in .env: ' . implode(', ', $missing)
            . '. For Gmail, SMTP_PASS must be a Google App Password (save .env after editing).';
    }
}

if (!function_exists('reminder_send_email')) {
    function reminder_send_email($toEmail, $subject, $message, $htmlMessage = null)
    {
        $to = trim((string)$toEmail);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $mode = app_reminder_email_mode();
        $from = trim((string)(function_exists('env') ? env('REMINDER_EMAIL_FROM', env('SMTP_FROM_EMAIL', 'no-reply@dental-clinic.local')) : 'no-reply@dental-clinic.local'));
        $fromName = trim((string)(function_exists('env') ? env('REMINDER_EMAIL_FROM_NAME', env('SMTP_FROM_NAME', "Azucena's Dental Clinic")) : "Azucena's Dental Clinic"));

        if ($mode === 'brevo') {
            return reminder_send_email_brevo($to, $subject, $message, $htmlMessage);
        }

        if ($mode === 'smtp') {
            if (!smtp_is_configured()) {
                $isDev = strtolower((string)(function_exists('env') ? env('APP_ENV', 'development') : 'development')) === 'development';
                if ($isDev) {
                    error_log('REMINDER_EMAIL(log): SMTP not configured on localhost — logging email instead. ' . smtp_missing_message());
                    error_log('REMINDER_EMAIL(log): to=' . $to . ' subject=' . $subject . ' message=' . $message);
                    return true;
                }
                error_log('REMINDER_EMAIL(smtp): ' . smtp_missing_message());
                return false;
            }
            return reminder_send_email_smtp($to, $subject, $message, $from, $fromName, $htmlMessage);
        }

        if ($mode === 'mail') {
            $headers = "MIME-Version: 1.0\r\n";
            if ($htmlMessage !== null && trim((string)$htmlMessage) !== '') {
                $boundary = 'b_' . bin2hex(random_bytes(8));
                $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
                $headers .= 'From: ' . reminder_format_mailbox($fromName, $from) . "\r\n";
                $body = reminder_email_multipart_body($message, $htmlMessage, $boundary);
                return @mail($to, $subject, $body, $headers);
            }
            $headers .= "Content-type:text/plain;charset=UTF-8\r\n";
            $headers .= 'From: ' . reminder_format_mailbox($fromName, $from) . "\r\n";
            return @mail($to, $subject, $message, $headers);
        }

        error_log('REMINDER_EMAIL(log): to=' . $to . ' subject=' . $subject . ' message=' . $message);
        return true;
    }
}

if (!function_exists('reminder_format_mailbox')) {
    function reminder_format_mailbox($name, $email)
    {
        $safeName = str_replace(["\r", "\n", '"'], '', (string)$name);
        $safeEmail = trim((string)$email);
        if ($safeName === '') {
            return $safeEmail;
        }
        return '"' . $safeName . '" <' . $safeEmail . '>';
    }
}

if (!function_exists('reminder_email_multipart_body')) {
    function reminder_email_multipart_body($textMessage, $htmlMessage, $boundary)
    {
        $textMessage = str_replace(["\r\n", "\r"], "\n", (string)$textMessage);
        $htmlMessage = str_replace(["\r\n", "\r"], "\n", (string)$htmlMessage);
        $textMessage = str_replace("\n.", "\n..", $textMessage);
        $htmlMessage = str_replace("\n.", "\n..", $htmlMessage);

        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textMessage . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlMessage . "\r\n\r\n";
        $body .= '--' . $boundary . '--';

        return $body;
    }
}

if (!function_exists('reminder_send_email_smtp')) {
    function reminder_send_email_smtp($toEmail, $subject, $message, $fromEmail, $fromName, $htmlMessage = null)
    {
        if (!function_exists('env')) {
            return false;
        }

        $host = trim((string)env('SMTP_HOST', ''));
        $port = (int)env('SMTP_PORT', 587);
        $user = trim((string)env('SMTP_USER', ''));
        $pass = (string)env('SMTP_PASS', '');
        $secure = strtolower(trim((string)env('SMTP_SECURE', env('SMTP_ENCRYPTION', 'tls'))));

        if ($host === '' || $user === '' || $pass === '') {
            error_log('REMINDER_EMAIL(smtp): missing SMTP_HOST/SMTP_USER/SMTP_PASS');
            return false;
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            error_log('REMINDER_EMAIL(smtp): connect failed ' . $errstr);
            return false;
        }

        stream_set_timeout($fp, 15);
        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $write = function ($cmd) use ($fp) {
            fwrite($fp, $cmd . "\r\n");
        };

        $read();
        $write('EHLO localhost');
        $ehlo = $read();
        if ($secure === 'tls' && stripos($ehlo, 'STARTTLS') !== false) {
            $write('STARTTLS');
            $read();
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write('EHLO localhost');
            $read();
        }

        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $auth = $read();
        if (strpos($auth, '235') === false) {
            fclose($fp);
            error_log('REMINDER_EMAIL(smtp): authentication failed');
            return false;
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        $read();
        $write('RCPT TO:<' . $toEmail . '>');
        $read();
        $write('DATA');
        $read();

        $headers = 'From: ' . reminder_format_mailbox($fromName, $fromEmail) . "\r\n";
        $headers .= 'To: <' . $toEmail . ">\r\n";
        $headers .= 'Subject: ' . $subject . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        if ($htmlMessage !== null && trim((string)$htmlMessage) !== '') {
            $boundary = 'b_' . bin2hex(random_bytes(8));
            $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
            $body = $headers . "\r\n" . reminder_email_multipart_body($message, $htmlMessage, $boundary);
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body = $headers . "\r\n" . str_replace("\n.", "\n..", $message);
        }
        $write($body . "\r\n.");
        $sent = $read();
        $write('QUIT');
        fclose($fp);

        return strpos($sent, '250') !== false;
    }
}

if (!function_exists('reminder_send_sms')) {
    function reminder_send_sms($phoneNumber, $message)
    {
        $to = reminder_normalize_phone($phoneNumber);
        if ($to === '') {
            return false;
        }

        $mode = strtolower((string)(function_exists('env') ? env('REMINDER_SMS_MODE', 'log') : (getenv('REMINDER_SMS_MODE') ?: 'log')));

        if ($mode === 'brevo') {
            return reminder_send_sms_brevo($phoneNumber, $message);
        }

        if ($mode === 'semaphore') {
            $apiKey = trim((string)(function_exists('env') ? env('SEMAPHORE_API_KEY', '') : ''));
            if ($apiKey === '' || !function_exists('curl_init')) {
                return false;
            }

            $senderName = trim((string)(function_exists('env') ? env('SEMAPHORE_SENDER_NAME', 'AzucenaDental') : 'AzucenaDental'));
            $query = http_build_query([
                'apikey' => $apiKey,
                'number' => $to,
                'message' => $message,
                'sendername' => $senderName,
            ]);
            $url = 'https://api.semaphore.co/api/v4/messages?' . $query;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            if ($http >= 200 && $http < 300) {
                return true;
            }

            error_log('REMINDER_SMS(semaphore): http=' . $http . ' response=' . (string)$response);
            return false;
        }

        if ($mode === 'webhook') {
            $url = trim((string)(function_exists('env') ? env('SMS_WEBHOOK_URL', '') : ''));
            if ($url === '' || !function_exists('curl_init')) {
                return false;
            }

            $ch = curl_init($url);
            if (!$ch) {
                return false;
            }

            $payload = json_encode(['to' => $to, 'message' => $message]);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            return $http >= 200 && $http < 300;
        }

        error_log('REMINDER_SMS(log): to=' . $to . ' message=' . $message);
        return true;
    }
}

if (!function_exists('reminder_insert_inapp_notification')) {
    function reminder_insert_inapp_notification($conn, $userId, $title, $body, $link)
    {
        $uid = (int)$userId;
        if ($uid <= 0 || !reminder_table_exists($conn, 'notifications')) {
            return false;
        }

        $ins = $conn->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
        if (!$ins) {
            return false;
        }
        $ins->bind_param('isss', $uid, $title, $body, $link);
        $ok = $ins->execute();
        $ins->close();
        return (bool)$ok;
    }
}

if (!function_exists('reminder_insert_patient_inapp_notification')) {
    function reminder_insert_patient_inapp_notification($conn, $patientId, $title, $body, $link)
    {
        $pid = (int)$patientId;
        if ($pid <= 0) {
            return false;
        }

        $createSql = "CREATE TABLE IF NOT EXISTS patient_notifications (
            patient_notification_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_patient_notifications_patient (patient_id),
            KEY idx_patient_notifications_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        $conn->query($createSql);

        if (!reminder_table_exists($conn, 'patient_notifications')) {
            return false;
        }

        $ins = $conn->prepare('INSERT INTO patient_notifications (patient_id, title, body, link) VALUES (?, ?, ?, ?)');
        if (!$ins) {
            return false;
        }
        $ins->bind_param('isss', $pid, $title, $body, $link);
        $ok = $ins->execute();
        $ins->close();

        return (bool)$ok;
    }
}
