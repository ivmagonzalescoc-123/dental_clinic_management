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
        $emailAt = (clone $start)->modify('-24 hours');
        $patientSmsAt = (clone $start)->modify('-24 hours');
        $staffDayBeforeAt = (clone $start)->modify('-24 hours');
        $patientDayBeforeAt = (clone $start)->modify('-24 hours');
        $staffOneHourAt = (clone $start)->modify('-1 hour');
        $patientOneHourAt = (clone $start)->modify('-1 hour');

        if (reminder_queue_once($conn, (int)$scheduleId, $emailAt->format('Y-m-d H:i:s'), 'email', $basePayload + ['template' => 'appt_24h'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $patientSmsAt->format('Y-m-d H:i:s'), 'sms', $basePayload + ['template' => 'appt_24h'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $staffDayBeforeAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_24h_staff'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $patientDayBeforeAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_24h_patient'])) {
            $queued++;
        }
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

        if (reminder_queue_once($conn, (int)$scheduleId, $missedAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_missed_staff'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $missedAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_missed_patient'])) {
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

        if (reminder_queue_once($conn, (int)$scheduleId, $noShowAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_no_show_staff'])) {
            $queued++;
        }
        if (reminder_queue_once($conn, (int)$scheduleId, $noShowAt->format('Y-m-d H:i:s'), 'push', $basePayload + ['template' => 'appt_no_show_patient'])) {
            $queued++;
        }

        return $queued;
    }
}

if (!function_exists('reminder_send_email')) {
    function reminder_send_email($toEmail, $subject, $message)
    {
        $to = trim((string)$toEmail);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $mode = strtolower((string)(getenv('REMINDER_EMAIL_MODE') ?: 'log'));
        if ($mode === 'mail') {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/plain;charset=UTF-8\r\n";
            $headers .= "From: no-reply@dental-clinic.local\r\n";
            return @mail($to, $subject, $message, $headers);
        }

        error_log('REMINDER_EMAIL(log): to=' . $to . ' subject=' . $subject . ' message=' . $message);
        return true;
    }
}

if (!function_exists('reminder_send_sms')) {
    function reminder_send_sms($phoneNumber, $message)
    {
        $to = trim((string)$phoneNumber);
        if ($to === '') {
            return false;
        }

        $mode = strtolower((string)(getenv('REMINDER_SMS_MODE') ?: 'log'));
        if ($mode === 'webhook') {
            $url = trim((string)(getenv('SMS_WEBHOOK_URL') ?: ''));
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
