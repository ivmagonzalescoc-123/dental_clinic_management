<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';

    if (!$conn) throw new Exception('Database connection failed');

    // Basic inputs: appointment_id (optional), user_id (doctor), days_ahead, duration_minutes
    $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0; // doctor
    $days_ahead = isset($_POST['days_ahead']) ? (int)$_POST['days_ahead'] : 14;
    $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 30; // minutes

    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Doctor (user_id) is required.']);
        exit;
    }

    // 1) Load doctor availability for weekdays
    $avail_sql = 'SELECT weekday, start_time, end_time FROM doctor_availability WHERE user_id = ?';
    $avail_stmt = $conn->prepare($avail_sql);
    $avail_stmt->bind_param('i', $user_id);
    $avail_stmt->execute();
    $avail_res = $avail_stmt->get_result();
    $availability = [];
    while ($row = $avail_res->fetch_assoc()) {
        $availability[(int)$row['weekday']][] = ['start' => $row['start_time'], 'end' => $row['end_time']];
    }
    $avail_stmt->close();

    // 2) Iterate days and produce candidate slots avoiding existing schedules/appointments
    $slots = [];
    $now = new DateTimeImmutable('now');
    for ($d = 0; $d <= $days_ahead; $d++) {
        $day = $now->modify("+{$d} days");
        $weekday = (int)$day->format('w');
        if (empty($availability[$weekday])) continue; // doctor not available this weekday

        foreach ($availability[$weekday] as $block) {
            $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d') . ' ' . $block['start']);
            $end_dt = DateTime::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d') . ' ' . $block['end']);
            if (!$start_dt || !$end_dt) continue;

            $cursor = clone $start_dt;
            while ($cursor->getTimestamp() + ($duration * 60) <= $end_dt->getTimestamp()) {
                $candidate_start = $cursor->format('Y-m-d H:i:s');
                $candidate_end = $cursor->modify("+{$duration} minutes")->format('Y-m-d H:i:s');

                // Check overlapping schedules for this doctor
                $overlap_sql = "SELECT COUNT(*) AS cnt FROM schedules WHERE user_id = ? AND status IN ('scheduled','rescheduled') AND NOT (scheduled_end <= ? OR scheduled_start >= ?)
                                ";
                $ov_stmt = $conn->prepare($overlap_sql);
                $ov_stmt->bind_param('iss', $user_id, $candidate_start, $candidate_end);
                $ov_stmt->execute();
                $ov_res = $ov_stmt->get_result();
                $ov_row = $ov_res ? $ov_res->fetch_assoc() : null;
                $ov_stmt->close();

                if ((int)($ov_row['cnt'] ?? 0) === 0) {
                    $slots[] = ['start' => $candidate_start, 'end' => $candidate_end];
                    if (count($slots) >= 20) break 3; // limit suggestions
                }

                $cursor = $cursor->modify('+10 minutes'); // step window by 10 minutes
            }
        }
    }

    // If appointment_id provided and user asked to book, create schedule row
    if ($appointment_id && isset($_POST['book_start'])) {
        $book_start = trim($_POST['book_start']);
        $book_end = isset($_POST['book_end']) ? trim($_POST['book_end']) : (new DateTime($book_start))->modify("+{$duration} minutes")->format('Y-m-d H:i:s');

        // Enforce daily cap: count appointments + schedules for this doctor on the chosen date
        $book_date = (new DateTime($book_start))->format('Y-m-d');
        $cap_sql = "SELECT (
            (SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND user_id = ? AND status IN ('confirmed','checked_in','in_progress','completed'))
            + (SELECT COUNT(*) FROM schedules WHERE DATE(scheduled_start) = ? AND user_id = ? AND status IN ('scheduled','rescheduled'))
        ) AS cnt";
        $cap_stmt = $conn->prepare($cap_sql);
        $cap_stmt->bind_param('siss', $book_date, $user_id, $book_date, $user_id);
        $cap_stmt->execute();
        $cap_res = $cap_stmt->get_result();
        $cap_row = $cap_res ? $cap_res->fetch_assoc() : null;
        $cap_count = (int)($cap_row['cnt'] ?? 0);
        $cap_stmt->close();
        if ($cap_count >= 15) {
            echo json_encode(['success'=>false,'message'=>'The selected date has reached the maximum number of bookings (appointments + schedules) (15). Please choose another date.']);
            exit;
        }

        // Insert schedule
        $ins_sql = 'INSERT INTO schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, "scheduled")';
        $ins_stmt = $conn->prepare($ins_sql);
        $ins_stmt->bind_param('iiss', $appointment_id, $user_id, $book_start, $book_end);
        if (!$ins_stmt->execute()) {
            throw new Exception('Failed to create schedule: ' . $ins_stmt->error);
        }
        $schedule_id = $conn->insert_id;
        $ins_stmt->close();

        // Create default reminder 24 hours before
        $remind_dt = (new DateTime($book_start))->modify('-24 hours')->format('Y-m-d H:i:s');
        $r_sql = 'INSERT INTO schedule_reminders (schedule_id, remind_at, channel, payload) VALUES (?, ?, "email", ?)';
        $payload = json_encode(['appointment_id'=>$appointment_id, 'schedule_id'=>$schedule_id]);
        $r_stmt = $conn->prepare($r_sql);
        $r_stmt->bind_param('iss', $schedule_id, $remind_dt, $payload);
        $r_stmt->execute();
        $r_stmt->close();

        echo json_encode(['success'=>true,'message'=>'Scheduled successfully','schedule_id'=>$schedule_id]);
        exit;
    }

    echo json_encode(['success' => true, 'slots' => $slots]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
