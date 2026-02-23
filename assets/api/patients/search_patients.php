<?php
header('Content-Type: application/json');
include '../../../config/database.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['success' => true, 'patients' => []]);
    exit;
}

try {
    $like = '%' . $q . '%';
    $sql = "SELECT patient_id, first_name, middle_name, last_name, username, email, phone_number FROM patients WHERE (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone_number LIKE ?) ORDER BY first_name ASC LIMIT 20";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed');
    $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
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
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
