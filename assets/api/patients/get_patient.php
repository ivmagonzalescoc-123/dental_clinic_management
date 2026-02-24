<?php
include '../../../config/database.php';
include_once '../../../config/session_check.php';
header('Content-Type: application/json');
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','secretary','admin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if (!$patient_id) { echo json_encode(['success'=>false,'message'=>'Missing patient_id']); exit; }
$stmt = $conn->prepare('SELECT patient_id, first_name, last_name, date_of_birth, phone_number, email, profiling FROM patients WHERE patient_id = ? LIMIT 1');
$stmt->bind_param('i', $patient_id);
$stmt->execute();
$res = $stmt->get_result();
$pat = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$pat) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
// attempt to decode profiling JSON
if (isset($pat['profiling']) && $pat['profiling'] !== null) {
    $decoded = json_decode($pat['profiling'], true);
    if ($decoded !== null) $pat['profiling'] = $decoded;
}

echo json_encode(['success'=>true,'patient'=>$pat]);
?>