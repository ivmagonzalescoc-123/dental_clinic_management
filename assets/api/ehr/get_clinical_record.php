<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','secretary'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$appointment_id = (int)($_GET['appointment_id'] ?? 0);
if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing appointment_id']);
    exit;
}

$stmt = $conn->prepare('SELECT * FROM clinical_records WHERE appointment_id = ?');
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$res = $stmt->get_result();
$rec = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$rec) {
    echo json_encode(['success'=>true,'record'=>null]);
    exit;
}

// Hide odontogram for secretary
if ($role === 'secretary') {
    unset($rec['odontogram']);
}

// Ensure JSON fields are decoded
foreach (['recommended_services','treatment_plan','materials_used','odontogram'] as $f) {
    if (isset($rec[$f]) && $rec[$f] !== null) {
        $rec[$f] = json_decode($rec[$f], true);
    }
}

echo json_encode(['success'=>true,'record'=>$rec]);

?>
