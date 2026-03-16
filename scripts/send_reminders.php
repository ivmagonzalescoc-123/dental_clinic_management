<?php
// Cron worker: send pending schedule reminders
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/send_reminders.log');

include __DIR__ . '/../config/database.php';

// Fetch pending reminders
$sql = "SELECT r.reminder_id, r.patient_schedule_id, r.remind_at, r.channel, r.payload, s.scheduled_start, s.appointment_id
        FROM schedule_reminders r
    JOIN patient_schedules s ON s.schedule_id = r.patient_schedule_id
        WHERE r.sent_at IS NULL AND r.remind_at <= NOW()";

// Guard: ensure schedule_reminders and patient_schedules tables exist
if (!function_exists('table_exists') || !table_exists($conn, 'schedule_reminders') || !table_exists($conn, 'patient_schedules')) {
    error_log('send_reminders: required scheduling tables missing; aborting reminders run');
    exit(0);
}

$res = $conn->query($sql);
if (!$res) {
    error_log('Reminder fetch failed: ' . $conn->error);
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    $reminder_id = (int)$row['reminder_id'];
    $channel = $row['channel'];
    $payload = json_decode($row['payload'], true) ?: [];

    // Placeholder: dispatch based on channel
    $sent = false;
    // Create an in-app notification for the target user (patient and doctor)
    $title = 'Upcoming appointment reminder';
    $body = 'Appointment #' . ($row['appointment_id'] ?? '') . ' is scheduled at ' . ($row['scheduled_start'] ?? '') . '. Please be prepared.';
    $link = '/patient/patient.php?page=appointments&appointment_id=' . ($row['appointment_id'] ?? '');

    // Attempt to find the patient user id from appointment
    $apptStmt = $conn->prepare('SELECT patient_id, user_id FROM appointments WHERE appointment_id = ? LIMIT 1');
    if ($apptStmt) {
        $apptStmt->bind_param('i', $row['appointment_id']);
        $apptStmt->execute();
        $apres = $apptStmt->get_result();
        $ap = $apres ? $apres->fetch_assoc() : null;
        $apptStmt->close();
    }

    $patient_user_id = $ap['patient_id'] ?? null;
    $doctor_user_id = $ap['user_id'] ?? null;

    // Insert notification for patient and doctor if notifications table exists
    if (function_exists('table_exists') && table_exists($conn, 'notifications')) {
        if ($patient_user_id) {
            $ni = $conn->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
            if ($ni) {
                $ni->bind_param('isss', $patient_user_id, $title, $body, $link);
                $ni->execute();
                $ni->close();
            }
        }

        if ($doctor_user_id) {
            $ni2 = $conn->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
            if ($ni2) {
                $ni2->bind_param('isss', $doctor_user_id, $title, $body, $link);
                $ni2->execute();
                $ni2->close();
            }
        }
        // Also notify all secretaries about upcoming reminders (optional: limit to active secretaries)
        try {
            if (function_exists('table_exists') && table_exists($conn, 'users') && table_exists($conn, 'role')) {
                $secStmt = $conn->prepare("SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary' AND u.is_active = 1");
                if ($secStmt) {
                    $secStmt->execute();
                    $sres = $secStmt->get_result();
                    while ($s = $sres->fetch_assoc()) {
                        $sid = (int)$s['user_id'];
                        $ni3 = $conn->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
                        if ($ni3) {
                            $ni3->bind_param('isss', $sid, $title, $body, $link);
                            $ni3->execute();
                            $ni3->close();
                        }
                    }
                    $secStmt->close();
                }
            }
        } catch (Throwable $e) {
            error_log('send_reminders: failed to notify secretaries: ' . $e->getMessage());
        }
    } else {
        error_log('send_reminders: notifications table missing; skipping in-app notifications');
    }

    // Mark reminder as sent
    $u = $conn->prepare('UPDATE schedule_reminders SET sent_at = NOW() WHERE reminder_id = ?');
    $u->bind_param('i', $reminder_id);
    $u->execute();
    $u->close();
}

echo "done\n";

?>
