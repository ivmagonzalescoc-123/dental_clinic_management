<?php
include '../../../config/database.php';
include_once '../../../config/session_check.php';
header('Content-Type: application/json');
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','admin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}
// Read JSON body
$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!$data || !isset($data['patient_id'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Invalid payload']);
    exit;
}
$patient_id = (int)$data['patient_id'];
$profiling = isset($data['profiling']) ? $data['profiling'] : null;
try {
    // Ensure column exists
    $colRes = $conn->query("SHOW COLUMNS FROM patients LIKE 'profiling'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN profiling JSON DEFAULT NULL");
    }

    // Create audit table if missing
    $conn->query("CREATE TABLE IF NOT EXISTS patient_profiling_audit (
        audit_id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        profiling JSON,
        changed_by INT NULL,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Basic validation: ensure profiling is an object with allowed keys
    $allowedKeys = ['medical_history','medications','allergies','smoking','alcohol','asa','periodontal','dental_chart','imaging','last_visit'];
    if ($profiling !== null && !is_array($profiling)) {
        throw new Exception('Profiling must be an object');
    }
    if (is_array($profiling)) {
        foreach ($profiling as $k => $v) {
            if (!in_array($k, $allowedKeys)) {
                // strip unknown keys
                unset($profiling[$k]);
            }
        }
    }

    $json = $profiling !== null ? json_encode($profiling, JSON_UNESCAPED_UNICODE) : null;
    $ust = $conn->prepare('UPDATE patients SET profiling = ? WHERE patient_id = ?');
    $ust->bind_param('si', $json, $patient_id);
    if (!$ust->execute()) throw new Exception('Update failed: ' . $ust->error);
    $ust->close();

    // Insert audit row
    $audit_stmt = $conn->prepare('INSERT INTO patient_profiling_audit (patient_id, profiling, changed_by) VALUES (?, ?, ?)');
    $changed_by = $_SESSION['user_id'] ?? null;
    $audit_stmt->bind_param('isi', $patient_id, $json, $changed_by);
    $audit_stmt->execute();
    $audit_stmt->close();

    echo json_encode(['success'=>true,'message'=>'Profiling saved']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>