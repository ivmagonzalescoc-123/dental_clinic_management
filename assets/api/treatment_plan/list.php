<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/treatment_plan_helpers.php';
header('Content-Type: application/json');

if (!in_array($_SESSION['role'] ?? '', ['doctor', 'secretary'], true) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing patient_id']);
    exit;
}

$items = treatment_plan_list($conn, $patientId);
echo json_encode([
    'success' => true,
    'items' => $items,
    'totals' => treatment_plan_totals($items),
]);
