<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include __DIR__ . '/availability_guard.php';

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $sessionPatientId = (int)($_SESSION['patient_id'] ?? 0);
    if ($sessionPatientId <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only patients can submit reschedule requests.']);
        exit;
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
        exit;
    }

    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $requestedDoctorId = (int)($_POST['requested_doctor_id'] ?? 0);
    $requestedDate = trim((string)($_POST['requested_date'] ?? ''));
    $requestedTimeslotId = (int)($_POST['requested_timeslot_id'] ?? 0);
    $requestNote = trim((string)($_POST['request_note'] ?? ''));

    if ($appointmentId <= 0 || $requestedDoctorId <= 0 || $requestedTimeslotId <= 0 || $requestedDate === '') {
        echo json_encode(['success' => false, 'message' => 'Missing required request details.']);
        exit;
    }

    if (!appt_validate_appointment_date($requestedDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit;
    }

    if (mb_strlen($requestNote) > 500) {
        echo json_encode(['success' => false, 'message' => 'Request note must be 500 characters or less.']);
        exit;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS appointment_reschedule_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        patient_id INT NOT NULL,
        requested_doctor_id INT NULL,
        requested_date DATE NOT NULL,
        requested_timeslot_id INT NOT NULL,
        request_note VARCHAR(500) NULL,
        status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        review_note VARCHAR(500) NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_arr_status_created (status, created_at),
        KEY idx_arr_appointment_status (appointment_id, status),
        KEY idx_arr_patient_status (patient_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $apptStmt = $conn->prepare('SELECT appointment_id, patient_id, user_id, appointment_date, status FROM appointments WHERE appointment_id = ? LIMIT 1');
    if (!$apptStmt) {
        throw new Exception('Failed to load appointment.');
    }
    $apptStmt->bind_param('i', $appointmentId);
    $apptStmt->execute();
    $appt = $apptStmt->get_result()->fetch_assoc();
    $apptStmt->close();

    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
        exit;
    }

    if ((int)$appt['patient_id'] !== $sessionPatientId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only request changes for your own appointment.']);
        exit;
    }

    $statusKey = strtolower((string)($appt['status'] ?? ''));
    if (!in_array($statusKey, ['scheduled', 'confirmed', 'missed'], true)) {
        echo json_encode(['success' => false, 'message' => 'This appointment cannot be rescheduled in its current status.']);
        exit;
    }

    $existingReqStmt = $conn->prepare('SELECT request_id FROM appointment_reschedule_requests WHERE appointment_id = ? AND status = ? LIMIT 1');
    if ($existingReqStmt) {
        $pending = 'pending';
        $existingReqStmt->bind_param('is', $appointmentId, $pending);
        $existingReqStmt->execute();
        $existingReq = $existingReqStmt->get_result()->fetch_assoc();
        $existingReqStmt->close();
        if ($existingReq) {
            echo json_encode(['success' => false, 'message' => 'A pending reschedule request already exists for this appointment.']);
            exit;
        }
    }

    [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $requestedDoctorId, $requestedDate);
    if (!$bookable) {
        echo json_encode(['success' => false, 'message' => $bookableMsg]);
        exit;
    }

    $slotStmt = $conn->prepare('SELECT timeslot_id, doctor_id, slot_date, status FROM timeslot WHERE timeslot_id = ? LIMIT 1');
    if (!$slotStmt) {
        throw new Exception('Failed to validate timeslot.');
    }
    $slotStmt->bind_param('i', $requestedTimeslotId);
    $slotStmt->execute();
    $slot = $slotStmt->get_result()->fetch_assoc();
    $slotStmt->close();

    if (!$slot) {
        echo json_encode(['success' => false, 'message' => 'Selected timeslot not found.']);
        exit;
    }

    if ((int)$slot['doctor_id'] !== $requestedDoctorId || (string)$slot['slot_date'] !== $requestedDate) {
        echo json_encode(['success' => false, 'message' => 'Selected timeslot does not match requested doctor/date.']);
        exit;
    }

    if ((string)$slot['status'] !== 'available') {
        echo json_encode(['success' => false, 'message' => 'Selected timeslot is no longer available.']);
        exit;
    }

    $ins = $conn->prepare('INSERT INTO appointment_reschedule_requests (appointment_id, patient_id, requested_doctor_id, requested_date, requested_timeslot_id, request_note, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$ins) {
        throw new Exception('Failed to save request.');
    }

    $pendingStatus = 'pending';
    $ins->bind_param('iiisiss', $appointmentId, $sessionPatientId, $requestedDoctorId, $requestedDate, $requestedTimeslotId, $requestNote, $pendingStatus);
    if (!$ins->execute()) {
        throw new Exception('Failed to save request: ' . $ins->error);
    }
    $requestId = (int)$conn->insert_id;
    $ins->close();

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'appointment.reschedule_request.create',
            'appointment_reschedule_requests',
            $requestId,
            'Requested date=' . $requestedDate . ', requested_doctor_id=' . $requestedDoctorId . ', timeslot_id=' . $requestedTimeslotId,
            null
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reschedule request submitted. Waiting for secretary approval.',
        'request_id' => $requestId
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
