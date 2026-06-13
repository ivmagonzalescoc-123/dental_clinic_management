<?php
// Cron worker: send pending schedule reminders
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/send_reminders.log');

include __DIR__ . '/../config/database.php';
include __DIR__ . '/../config/reminders.php';
include __DIR__ . '/../config/system_logs.php';
include __DIR__ . '/../config/billing_helpers.php';

if (!function_exists('fetch_secretary_ids')) {
    function fetch_secretary_ids($conn)
    {
        $ids = [];
        try {
            if (!reminder_table_exists($conn, 'users') || !reminder_table_exists($conn, 'role')) {
                return $ids;
            }

            $stmt = $conn->prepare("SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary' AND u.is_active = 1");
            if (!$stmt) {
                return $ids;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['user_id'];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('send_reminders: failed to fetch secretary ids: ' . $e->getMessage());
        }

        return $ids;
    }
}

if (!function_exists('build_reminder_template')) {
    function build_reminder_template($template, $appointmentId, $scheduledStart, $patientName, $doctorName)
    {
        $t = strtolower((string)$template);
        $patientDisplay = trim((string)$patientName) !== '' ? trim((string)$patientName) : 'Patient';
        $doctorDisplay = trim((string)$doctorName) !== '' ? ('Dr. ' . trim((string)$doctorName)) : 'your doctor';

        switch ($t) {
            case 'appt_24h':
                return [
                    'subject' => 'Appointment reminder: 24 hours before',
                    'email' => "Hello {$patientName},\n\nThis is a reminder that your dental appointment is tomorrow at {$scheduledStart}.\n" . ($doctorName !== '' ? ("Doctor: {$doctorName}\n") : '') . "Appointment ID: #{$appointmentId}\n\nPlease arrive a few minutes early.\n\nAzucena's Dental Clinic",
                    'sms' => "Reminder: You have a dental appointment tomorrow at {$scheduledStart} (ID #{$appointmentId}).",
                    'title' => 'Appointment reminder (24h)',
                    'body' => "{$patientDisplay} has an appointment tomorrow at {$scheduledStart}."
                ];

            case 'appt_24h_staff':
                return [
                    'subject' => 'Staff reminder: appointment tomorrow',
                    'email' => '',
                    'sms' => '',
                    'title' => 'Patient appointment tomorrow',
                    'body' => "{$patientDisplay} is scheduled with {$doctorDisplay} tomorrow at {$scheduledStart}."
                ];

            case 'appt_24h_patient':
                return [
                    'subject' => 'Appointment reminder: tomorrow',
                    'email' => '',
                    'sms' => '',
                    'title' => 'Appointment tomorrow',
                    'body' => "Your appointment with {$doctorDisplay} is tomorrow at {$scheduledStart}."
                ];

            case 'appt_1h':
                return [
                    'subject' => 'Appointment reminder: 1 hour before',
                    'email' => "Hello {$patientName},\n\nYour appointment starts in about 1 hour at {$scheduledStart}.\nAppointment ID: #{$appointmentId}\n\nIf you cannot attend, please contact the clinic as soon as possible.\n\nAzucena's Dental Clinic",
                    'sms' => "Reminder: Appointment #{$appointmentId} starts in about 1 hour ({$scheduledStart}).",
                    'title' => 'Appointment in 1 hour',
                    'body' => "{$patientDisplay} has an appointment in about 1 hour ({$scheduledStart})."
                ];

            case 'appt_1h_staff':
                return [
                    'subject' => 'Staff reminder: appointment in 1 hour',
                    'email' => '',
                    'sms' => '',
                    'title' => 'Appointment in 1 hour',
                    'body' => "{$patientDisplay} with {$doctorDisplay} starts in about 1 hour ({$scheduledStart})."
                ];

            case 'appt_1h_patient':
                return [
                    'subject' => 'Appointment in 1 hour',
                    'email' => '',
                    'sms' => '',
                    'title' => 'Appointment in 1 hour',
                    'body' => "Your appointment with {$doctorDisplay} starts in about 1 hour ({$scheduledStart})."
                ];

            case 'appt_missed_patient':
                return [
                    'subject' => 'Missed appointment alert',
                    'email' => "Hello {$patientName},\n\nOur records show that your appointment time ({$scheduledStart}) may have been missed.\nAppointment ID: #{$appointmentId}\n\nPlease contact the clinic immediately so our secretary can help reschedule or confirm cancellation.\n\nAzucena's Dental Clinic",
                    'sms' => "Alert: It looks like you missed appointment #{$appointmentId} at {$scheduledStart}. Please contact the clinic to reschedule.",
                    'title' => 'Possible missed appointment',
                    'body' => "Your appointment with {$doctorDisplay} at {$scheduledStart} appears missed. Secretary follow-up may be needed."
                ];

            case 'appt_missed_staff':
                return [
                    'subject' => 'Missed appointment staff alert',
                    'email' => '',
                    'sms' => '',
                    'title' => 'Possible missed appointment',
                    'body' => "{$patientDisplay} with {$doctorDisplay} appears missed at {$scheduledStart}. Please contact the patient and reschedule/cancel if needed."
                ];

            case 'appt_no_show_patient':
                return [
                    'subject' => 'Appointment marked as no-show',
                    'email' => "Hello {$patientName},\n\nBy end of day, appointment #{$appointmentId} has been marked as no-show.\nPlease contact the clinic so the secretary can help with next steps.\n\nAzucena's Dental Clinic",
                    'sms' => "Update: Appointment #{$appointmentId} is now marked as no-show. Please contact the clinic for next steps.",
                    'title' => 'Appointment marked no-show',
                    'body' => "Your appointment with {$doctorDisplay} at {$scheduledStart} was marked no-show."
                ];

            case 'appt_no_show_staff':
                return [
                    'subject' => 'No-show update',
                    'email' => '',
                    'sms' => '',
                    'title' => 'No-show recorded',
                    'body' => "{$patientDisplay} with {$doctorDisplay} was marked no-show at {$scheduledStart}. Continue secretary follow-up workflow."
                ];

            default:
                return [
                    'subject' => 'Dental appointment reminder',
                    'email' => "Hello {$patientName},\n\nThis is a reminder for your appointment at {$scheduledStart}.\n" . ($doctorName !== '' ? ("Doctor: {$doctorName}\n") : '') . "Appointment ID: #{$appointmentId}\n\nPlease arrive a few minutes early.\n\nAzucena's Dental Clinic",
                    'sms' => "Reminder: Appointment #{$appointmentId} at {$scheduledStart}.",
                    'title' => 'Upcoming appointment reminder',
                    'body' => "{$patientDisplay} has an appointment at {$scheduledStart}. Please be prepared."
                ];
        }
    }
}

if (!function_exists('queue_missed_reminders')) {
    function queue_missed_reminders($conn)
    {
        $sql = "SELECT s.schedule_id, s.scheduled_start, s.appointment_id
                FROM patient_schedules s
                INNER JOIN appointments a ON a.appointment_id = s.appointment_id
                WHERE a.status IN ('scheduled','confirmed')
                  AND s.status IN ('scheduled','rescheduled')
                  AND s.scheduled_start <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  AND s.scheduled_start >= DATE_SUB(NOW(), INTERVAL 2 DAY)";

        $res = $conn->query($sql);
        if (!$res) {
            error_log('send_reminders: queue_missed_reminders query failed: ' . $conn->error);
            return;
        }

        while ($row = $res->fetch_assoc()) {
            reminder_queue_missed_pack(
                $conn,
                (int)$row['schedule_id'],
                (string)$row['scheduled_start'],
                (int)$row['appointment_id']
            );
        }
    }
}

if (!function_exists('auto_mark_no_show_end_of_day')) {
    function auto_mark_no_show_end_of_day($conn)
    {
        $hour = (int)date('G');
        if ($hour < 23) {
            return;
        }

        $sql = "SELECT a.appointment_id, a.timeslot_id, a.status, s.schedule_id, s.scheduled_start
                FROM appointments a
                INNER JOIN patient_schedules s ON s.appointment_id = a.appointment_id
                WHERE a.status IN ('scheduled','confirmed')
                  AND s.scheduled_start >= CONCAT(CURDATE(), ' 00:00:00')
                  AND s.scheduled_start <= NOW()";
        $res = $conn->query($sql);
        if (!$res) {
            error_log('send_reminders: auto_mark_no_show_end_of_day query failed: ' . $conn->error);
            return;
        }

        while ($row = $res->fetch_assoc()) {
            $appointmentId = (int)$row['appointment_id'];
            $timeslotId = (int)($row['timeslot_id'] ?? 0);
            $scheduleId = (int)$row['schedule_id'];

            $upd = $conn->prepare("UPDATE appointments SET status = 'no_show' WHERE appointment_id = ? AND status IN ('scheduled','confirmed')");
            if (!$upd) {
                continue;
            }
            $upd->bind_param('i', $appointmentId);
            $upd->execute();
            $affected = (int)$upd->affected_rows;
            $upd->close();

            if ($affected <= 0) {
                continue;
            }

            if ($timeslotId > 0) {
                $free = $conn->prepare("UPDATE timeslot SET status = 'available' WHERE timeslot_id = ? AND status = 'booked'");
                if ($free) {
                    $free->bind_param('i', $timeslotId);
                    $free->execute();
                    $free->close();
                }
            }

            reminder_queue_no_show_followup_pack($conn, $scheduleId, (string)$row['scheduled_start'], $appointmentId);

            if (function_exists('log_system_event')) {
                log_system_event(
                    $conn,
                    'appointment.auto_no_show',
                    'appointments',
                    $appointmentId,
                    'Auto-marked as no_show at end of day by reminder worker.',
                    null
                );
            }
        }
    }
}

// Guard: ensure schedule_reminders and patient_schedules tables exist
if (!function_exists('table_exists') || !table_exists($conn, 'schedule_reminders') || !table_exists($conn, 'patient_schedules')) {
    error_log('send_reminders: required scheduling tables missing; aborting reminders run');
    exit(0);
}

// Queue late and no-show workflow reminders before dispatch
queue_missed_reminders($conn);
auto_mark_no_show_end_of_day($conn);

// Installment due-soon and overdue email reminders
if (function_exists('billing_send_installment_reminders')) {
    billing_send_installment_reminders($conn);
}

// Fetch pending reminders
$sql = "SELECT r.reminder_id, r.patient_schedule_id, r.remind_at, r.channel, r.payload,
           s.scheduled_start, s.appointment_id,
           a.patient_id, a.user_id AS doctor_user_id, a.status AS appointment_status,
           p.first_name AS patient_first_name, p.last_name AS patient_last_name, p.email AS patient_email, p.phone_number AS patient_phone,
           d.first_name AS doctor_first_name, d.last_name AS doctor_last_name, d.email AS doctor_email
    FROM schedule_reminders r
    JOIN patient_schedules s ON s.schedule_id = r.patient_schedule_id
    JOIN appointments a ON a.appointment_id = s.appointment_id
    LEFT JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN users d ON d.user_id = a.user_id
    WHERE r.sent_at IS NULL AND r.remind_at <= NOW()
      AND s.status IN ('scheduled','rescheduled')
      AND a.status IN ('scheduled','confirmed','no_show')";

$res = $conn->query($sql);
if (!$res) {
    error_log('Reminder fetch failed: ' . $conn->error);
    exit(1);
}

$secretaryIds = fetch_secretary_ids($conn);

while ($row = $res->fetch_assoc()) {
    $reminder_id = (int)$row['reminder_id'];
    $channel = reminder_normalize_channel($row['channel'] ?? 'push');
    $payload = json_decode($row['payload'], true) ?: [];
    $template = (string)($payload['template'] ?? 'default');

    $sent = false;
    $appointmentId = (int)($row['appointment_id'] ?? 0);
    $scheduledStart = (string)($row['scheduled_start'] ?? '');
    $patientName = trim((string)($row['patient_first_name'] ?? '') . ' ' . (string)($row['patient_last_name'] ?? ''));
    if ($patientName === '') {
        $patientName = 'Patient';
    }
    $doctorName = trim((string)($row['doctor_first_name'] ?? '') . ' ' . (string)($row['doctor_last_name'] ?? ''));
    $content = build_reminder_template($template, $appointmentId, $scheduledStart, $patientName, $doctorName);
    $title = (string)$content['title'];
    $body = (string)$content['body'];
    $staffLink = '/secretary/secretary.php?page=appointments';
    $doctorLink = '/doctor/doctor.php?page=appointments';
    $patientLink = '/patient/patient.php?page=appointments';

    if ($channel === 'email') {
        $to = (string)($row['patient_email'] ?? '');
        $subject = (string)$content['subject'];
        $message = (string)$content['email'];
        $sent = reminder_send_email($to, $subject, $message);
    } elseif ($channel === 'sms') {
        $to = (string)($row['patient_phone'] ?? '');
        $message = (string)$content['sms'];
        $sent = reminder_send_sms($to, $message);
    } else {
        $sent = false;
        $doctorUserId = (int)($row['doctor_user_id'] ?? 0);
        $patientId = (int)($row['patient_id'] ?? 0);

        $notifyDoctor = in_array($template, ['appt_24h_staff'], true);
        $notifySecretary = in_array($template, ['appt_24h_staff', 'appt_1h_staff', 'appt_missed_staff', 'appt_no_show_staff'], true);
        $notifyPatient = in_array($template, ['appt_24h_patient', 'appt_1h_patient', 'appt_missed_patient', 'appt_no_show_patient'], true);

        if ($notifyDoctor && $doctorUserId > 0 && reminder_insert_inapp_notification($conn, $doctorUserId, $title, $body, $doctorLink)) {
            $sent = true;
        }

        if ($notifySecretary) {
            try {
                foreach ($secretaryIds as $sid) {
                    if (reminder_insert_inapp_notification($conn, $sid, $title, $body, $staffLink)) {
                        $sent = true;
                    }
                }
            } catch (Throwable $e) {
                error_log('send_reminders: failed to notify secretaries: ' . $e->getMessage());
            }
        }

        if ($notifyPatient && $patientId > 0 && reminder_insert_patient_inapp_notification($conn, $patientId, $title, $body, $patientLink)) {
            $sent = true;
        }
    }

    if ($sent) {
        $u = $conn->prepare('UPDATE schedule_reminders SET sent_at = NOW() WHERE reminder_id = ?');
        if ($u) {
            $u->bind_param('i', $reminder_id);
            $u->execute();
            $u->close();
        }
    } else {
        error_log('send_reminders: dispatch failed for reminder_id=' . $reminder_id . ' channel=' . $channel);
    }
}

echo "done\n";

?>
