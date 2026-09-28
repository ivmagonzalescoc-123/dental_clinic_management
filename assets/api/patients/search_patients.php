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
    // Every word must match some field, so a full name like "evan lowes" (or "lowes evan")
    // finds the patient even though first and last names are separate columns.
    $terms = array_slice(array_values(array_filter(preg_split('/\s+/', $q), 'strlen')), 0, 5);
    $where = [];
    $params = [];
    foreach ($terms as $term) {
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $where[] = '(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql = "SELECT patient_id, first_name, middle_name, last_name, email, phone_number, date_of_birth, gender, address, profile_image, created_at, last_login_at
            FROM patients
            WHERE " . implode(' AND ', $where) . "
            ORDER BY first_name ASC LIMIT 20";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed');
    }
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
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
