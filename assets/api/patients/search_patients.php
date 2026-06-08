<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include_once '../../../config/session_check.php';

security_start_session();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor', 'secretary', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['success' => true, 'patients' => []]);
    exit;
}

try {
    $like = '%' . $q . '%';
    $sql = "SELECT patient_id, first_name, middle_name, last_name, email, phone_number FROM patients WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?) ORDER BY first_name ASC LIMIT 20";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed');
    }
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $patients = [];
    while ($row = $res->fetch_assoc()) {
        $patients[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'patients' => $patients]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to search patients']);
}
