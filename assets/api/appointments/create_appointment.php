<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
    $appointment_date = isset($_POST['appointment_date']) ? trim($_POST['appointment_date']) : '';
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $is_online_appointment = isset($_POST['is_online_appointment']) ? (int)$_POST['is_online_appointment'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'scheduled';

    if (empty($patient_id) || empty($appointment_date) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Patient, doctor and appointment date are required!']);
        exit;
    }

    // Enforce daily approval/confirmed limit: do not allow booking if the date already has 15 approved/confirmed appointments
    $cap_sql = "SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = ? AND status IN ('confirmed','checked_in','in_progress','completed')";
    $cap_stmt = $conn->prepare($cap_sql);
    if ($cap_stmt) {
        $cap_stmt->bind_param('s', $appointment_date);
        $cap_stmt->execute();
        $cap_res = $cap_stmt->get_result();
        $cap_row = $cap_res ? $cap_res->fetch_assoc() : null;
        $cap_count = (int)($cap_row['cnt'] ?? 0);
        $cap_stmt->close();

        if ($cap_count >= 15) {
            echo json_encode(['success' => false, 'message' => 'The selected date has reached the maximum number of approved appointments. Please choose another date.']);
            exit;
        }
    }

    // Insert appointment (timeslot and branch removed)
    $sql = "INSERT INTO appointments (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare insert failed: ' . $conn->error);
    }

    if (!$stmt->bind_param("ississi", $patient_id, $appointment_date, $reason, $is_online_appointment, $notes, $status, $user_id)) {
        throw new Exception('Bind insert failed: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Insert failed: ' . $stmt->error);
    }

    $appointment_id = $conn->insert_id;
    $stmt->close();

    // If appointment is for today, automatically check-in and add to queue
    $today = date('Y-m-d');
    $queue_number = null;
    if ($appointment_date === $today) {
        $conn->begin_transaction();
        try {
            // determine next queue number for today
            $nextQueueNo = 1;
            $qstmt = $conn->prepare('SELECT COALESCE(MAX(que_number), 0) + 1 AS next_no FROM check_in_queue WHERE DATE(checkin_time) = ?');
            if ($qstmt) {
                $qstmt->bind_param('s', $today);
                $qstmt->execute();
                $qres = $qstmt->get_result();
                if ($qres && ($qrow = $qres->fetch_assoc())) {
                    $nextQueueNo = (int)($qrow['next_no'] ?? 1);
                    if ($nextQueueNo <= 0) $nextQueueNo = 1;
                }
                $qstmt->close();
            }

            $status_queue = 'waiting';
            $ins = $conn->prepare('INSERT INTO check_in_queue (status, que_number, appointment_id) VALUES (?, ?, ?)');
            if (!$ins) {
                throw new Exception('Prepare queue insert failed: ' . $conn->error);
            }
            $ins->bind_param('sii', $status_queue, $nextQueueNo, $appointment_id);
            if (!$ins->execute()) {
                throw new Exception('Queue insert failed: ' . $ins->error);
            }
            $ins->close();

            // update appointment status to checked_in
            $newApptStatus = 'checked_in';
            $ustmt = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
            if ($ustmt) {
                $ustmt->bind_param('si', $newApptStatus, $appointment_id);
                $ustmt->execute();
                $ustmt->close();
            }

            $conn->commit();
            $queue_number = $nextQueueNo;
        } catch (Exception $qe) {
            $conn->rollback();
            // log but don't fail the whole request — still return appointment id
            error_log('Queue insert error: ' . $qe->getMessage());
        }
    }

    $response = ['success' => true, 'message' => 'Appointment created successfully!', 'appointment_id' => $appointment_id];
    if (!is_null($queue_number)) {
        $response['queue_number'] = $queue_number;
        $response['message'] = 'Appointment created and checked-in (Queue #' . $queue_number . ').';
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
