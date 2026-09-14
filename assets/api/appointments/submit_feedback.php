<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

ob_start();
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/feedback_helpers.php';
    if (!$conn) throw new Exception('Database connection failed');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
        exit;
    }

    $patientId = (int)($_SESSION['patient_id'] ?? 0);
    if ($patientId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Patient session expired. Please log in again.']);
        exit;
    }

    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Please select a star rating from 1 to 5.']);
        exit;
    }
    if (mb_strlen($comment) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Comment is too long (max 1000 characters).']);
        exit;
    }

    feedback_ensure_table($conn);

    if (!feedback_patient_is_eligible($conn, $patientId)) {
        echo json_encode(['success' => false, 'message' => 'Reviews are available once you have completed an appointment with us.']);
        exit;
    }

    if (feedback_patient_has_reviewed($conn, $patientId)) {
        echo json_encode(['success' => false, 'message' => 'You already submitted a review. Thank you!']);
        exit;
    }

    // Most recent completed appointment, kept only as context for admin — not shown to the patient.
    $appointmentId = null;
    $doctorId = null;
    $ctxStmt = $conn->prepare("SELECT appointment_id, user_id FROM appointments WHERE patient_id = ? AND status = 'completed' ORDER BY appointment_date DESC, appointment_id DESC LIMIT 1");
    if ($ctxStmt) {
        $ctxStmt->bind_param('i', $patientId);
        $ctxStmt->execute();
        $ctxRow = $ctxStmt->get_result()->fetch_assoc();
        $ctxStmt->close();
        if ($ctxRow) {
            $appointmentId = (int)$ctxRow['appointment_id'];
            $doctorId = !empty($ctxRow['user_id']) ? (int)$ctxRow['user_id'] : null;
        }
    }

    $insert = $conn->prepare('INSERT INTO patient_feedback (patient_id, appointment_id, doctor_id, rating, comment) VALUES (?, ?, ?, ?, ?)');
    $insert->bind_param('iiiis', $patientId, $appointmentId, $doctorId, $rating, $comment);

    if (!$insert->execute()) {
        // Race with a concurrent submission from the same patient (unique key).
        if ($conn->errno === 1062) {
            echo json_encode(['success' => false, 'message' => 'You already submitted a review. Thank you!']);
            exit;
        }
        throw new Exception('Failed to save feedback: ' . $insert->error);
    }
    $insert->close();

    echo json_encode(['success' => true, 'message' => 'Thank you for your feedback!']);
} catch (Throwable $e) {
    error_log('submit_feedback.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while submitting feedback.']);
}
