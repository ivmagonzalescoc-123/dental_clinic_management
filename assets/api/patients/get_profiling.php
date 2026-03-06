<?php
include '../../../config/database.php';
include_once '../../../config/session_check.php';
header('Content-Type: application/json');

$patient_id = (int)($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
    exit;
}

try {
    // Ensure profiling column exists
    $colRes = $conn->query("SHOW COLUMNS FROM patients LIKE 'profiling'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN profiling JSON DEFAULT NULL");
    }

    // Fetch patient profiling
    $stmt = $conn->prepare('SELECT profiling FROM patients WHERE patient_id = ?');
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
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
