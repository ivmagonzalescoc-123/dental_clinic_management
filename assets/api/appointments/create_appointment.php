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
    $is_emergency = isset($_POST['is_emergency']) ? (int)$_POST['is_emergency'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'scheduled';

    // Determine requester role (session) so staff/secretary bookings can be auto-confirmed
    $requester_role = $_SESSION['role'] ?? '';

    if (empty($patient_id) || empty($appointment_date) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Patient, doctor and appointment date are required!']);
        exit;
    }

        // Verify the selected doctor exists and is active
        $doc_check_sql = 'SELECT user_id, is_active, role_id FROM users WHERE user_id = ? LIMIT 1';
        $doc_stmt = $conn->prepare($doc_check_sql);
        if ($doc_stmt) {
            $doc_stmt->bind_param('i', $user_id);
            $doc_stmt->execute();
            $doc_res = $doc_stmt->get_result();
            $doc = $doc_res ? $doc_res->fetch_assoc() : null;
            $doc_stmt->close();

            if (!$doc || (int)($doc['is_active'] ?? 0) !== 1 || (int)($doc['role_id'] ?? 0) !== 2) {
                echo json_encode(['success' => false, 'message' => 'Selected doctor is not available. Please choose another doctor.']);
                exit;
            }
        }

    // Verify the patient exists
    $pat_check_sql = 'SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1';
    $pat_stmt = $conn->prepare($pat_check_sql);
    if ($pat_stmt) {
        $pat_stmt->bind_param('i', $patient_id);
        $pat_stmt->execute();
        $pat_res = $pat_stmt->get_result();
        $patient = $pat_res ? $pat_res->fetch_assoc() : null;
        $pat_stmt->close();

        if (!$patient) {
            echo json_encode(['success' => false, 'message' => 'Selected patient not found. Please select or create a patient account first.']);
            exit;
        }
    }

    // Enforce daily approval/confirmed limit only for patient-side bookings (keep room for walk-ins)
    // If the requester is a patient (session), we enforce max 15 approved/confirmed appointments per day
    $isPatientRequester = isset($_SESSION['patient_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'patient');
    if ($isPatientRequester) {
        // Count both appointments and schedules for the selected doctor on that date to enforce daily cap
        $cap_sql = "SELECT (
            (SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND user_id = ? AND status IN ('confirmed','checked_in','in_progress','completed'))
            + (SELECT COUNT(*) FROM schedules WHERE DATE(scheduled_start) = ? AND user_id = ? AND status IN ('scheduled','rescheduled'))
        ) AS cnt";
        $cap_stmt = $conn->prepare($cap_sql);
        if ($cap_stmt) {
            $cap_stmt->bind_param('siss', $appointment_date, $user_id, $appointment_date, $user_id);
            $cap_stmt->execute();
            $cap_res = $cap_stmt->get_result();
            $cap_row = $cap_res ? $cap_res->fetch_assoc() : null;
            $cap_count = (int)($cap_row['cnt'] ?? 0);
            $cap_stmt->close();

            if ($cap_count >= 15) {
                echo json_encode(['success' => false, 'message' => 'The selected date has reached the maximum number of bookings (appointments + schedules) (15). Please choose another date.']);
                exit;
            }
        }
    }

    // Prevent a patient from being booked more than once on the same date by any requester
    $dup_sql = 'SELECT COUNT(*) AS cnt FROM appointments WHERE patient_id = ? AND appointment_date = ? AND status != "cancelled"';
    $dup_stmt = $conn->prepare($dup_sql);
    if ($dup_stmt) {
        $dup_stmt->bind_param('is', $patient_id, $appointment_date);
        $dup_stmt->execute();
        $dup_res = $dup_stmt->get_result();
        $dup_row = $dup_res ? $dup_res->fetch_assoc() : null;
        $dup_stmt->close();
        $existingForDay = (int)($dup_row['cnt'] ?? 0);
        if ($existingForDay > 0) {
            echo json_encode(['success' => false, 'message' => 'Patient already has an appointment on the selected date. Cannot book more than one appointment per day.']);
            exit;
        }
    }

    // Patients may only create online appointments. Secretary/staff can create walk-ins.
    if ($isPatientRequester) {
        $is_online_appointment = 1;
    }

    // If the requester is a secretary (staff), auto-confirm the appointment instead of leaving it pending
    if (!$isPatientRequester && strtolower($requester_role) === 'secretary') {
        $status = 'confirmed';
    }

    // Ensure appointments table has `is_emergency` column
    $colRes = $conn->query("SHOW COLUMNS FROM appointments LIKE 'is_emergency'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE appointments ADD COLUMN is_emergency TINYINT(1) DEFAULT 0");
    }

    // Insert appointment (timeslot and branch removed)
    $sql = "INSERT INTO appointments (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id, is_emergency) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare insert failed: ' . $conn->error);
    }

    if (!$stmt->bind_param("ississii", $patient_id, $appointment_date, $reason, $is_online_appointment, $notes, $status, $user_id, $is_emergency)) {
        throw new Exception('Bind insert failed: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Insert failed: ' . $stmt->error);
    }

    $appointment_id = $conn->insert_id;
    $stmt->close();

    // Return success for appointment creation. For secretary-created bookings we auto-confirm.
    $responseMsg = 'Appointment created successfully!';
    if ($status === 'confirmed') {
        $responseMsg = 'Appointment created and confirmed.';
    } else {
        $responseMsg = 'Appointment created successfully! Awaiting secretary approval.';
    }
    $response = ['success' => true, 'message' => $responseMsg, 'appointment_id' => $appointment_id, 'status' => $status];
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
