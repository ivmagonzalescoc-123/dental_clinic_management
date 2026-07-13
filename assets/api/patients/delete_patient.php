<?php
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
security_start_session();
check_session(['admin','secretary','doctor']);

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    if (!$conn) throw new Exception('Database connection failed');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($patientId <= 0) throw new Exception('Invalid patient ID');

    // Only delete if patient has no appointments (safety check)
    $check = $conn->prepare('SELECT COUNT(*) AS cnt FROM appointments WHERE patient_id = ?');
    $check->bind_param('i', $patientId);
    $check->execute();
    $cnt = $check->get_result()->fetch_assoc()['cnt'] ?? 0;
    $check->close();

    if ($cnt > 0) {
        echo json_encode(['success' => true, 'message' => 'Patient has appointments, skipping cleanup']);
        exit;
    }

    $stmt = $conn->prepare('DELETE FROM patients WHERE patient_id = ?');
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Orphaned patient cleaned up', 'deleted' => $deleted]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
