<?php
include '../../../config/database.php';
include_once '../../../config/session_check.php';
header('Content-Type: application/json');

if (!in_array($_SESSION['role'] ?? '', ['doctor', 'secretary', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$appointment_id = (int)($_GET['appointment_id'] ?? 0);

if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
    exit;
}

try {
    // Ensure profiling column exists
    $colRes = $conn->query("SHOW COLUMNS FROM patients LIKE 'profiling'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN profiling JSON DEFAULT NULL");
    }

    // Fetch patient profiling via appointment
    $stmt = $conn->prepare('
        SELECT p.profiling 
        FROM patients p
        INNER JOIN appointments a ON p.patient_id = a.patient_id
        WHERE a.appointment_id = ?
    ');
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment or patient not found']);
        exit;
    }

    $profiling = null;
    if ($row['profiling']) {
        $profiling = json_decode($row['profiling'], true);
    }

    echo json_encode([
        'success' => true,
        'profiling' => $profiling
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
