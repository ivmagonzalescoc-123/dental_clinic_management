<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','secretary','admin'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$appointment_id = (int)($_GET['appointment_id'] ?? 0);
$record_id = (int)($_GET['record_id'] ?? 0);

// if record_id provided, resolve appointment_id
if ($record_id > 0 && !$appointment_id) {
    $rstmt = $conn->prepare('SELECT appointment_id FROM clinical_records WHERE record_id = ? LIMIT 1');
    if ($rstmt) {
        $rstmt->bind_param('i', $record_id);
        $rstmt->execute();
        $rres = $rstmt->get_result();
        $rrow = $rres ? $rres->fetch_assoc() : null;
        $rstmt->close();
        if ($rrow && !empty($rrow['appointment_id'])) $appointment_id = (int)$rrow['appointment_id'];
    }
}
if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing appointment_id']);
    exit;
}

// Fetch clinical records for appointment
$stmt = $conn->prepare('SELECT * FROM clinical_records WHERE appointment_id = ?');
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$res = $stmt->get_result();
$record = $res ? $res->fetch_assoc() : null;
$stmt->close();

// Fetch schedules for this appointment
$s = $conn->prepare('SELECT * FROM schedules WHERE appointment_id = ? ORDER BY scheduled_start DESC');
$s->bind_param('i', $appointment_id);
$s->execute();
$sres = $s->get_result();
$schedules = [];
while ($r = $sres->fetch_assoc()) $schedules[] = $r;
$s->close();

// Attach patient-level profiling (if available)
if ($record && isset($record['patient_id']) && $record['patient_id']) {
    $pstmt = $conn->prepare('SELECT profiling FROM patients WHERE patient_id = ? LIMIT 1');
    if ($pstmt) {
        $pstmt->bind_param('i', $record['patient_id']);
        $pstmt->execute();
        $pres = $pstmt->get_result();
        $prow = $pres ? $pres->fetch_assoc() : null;
        $pstmt->close();
        if ($prow && isset($prow['profiling']) && $prow['profiling'] !== null) {
            $decoded = json_decode($prow['profiling'], true);
            if ($decoded !== null) $record['patient_profiling'] = $decoded;
        }
    }
}

// Hide odontogram for secretary
if ($record && $role === 'secretary') unset($record['odontogram']);

$toDecode = ['recommended_services','treatment_plan','materials_used','odontogram','images','profiling'];
foreach ($toDecode as $f) {
    if (isset($record[$f]) && $record[$f] !== null) {
        $decoded = json_decode($record[$f], true);
        $record[$f] = ($decoded !== null) ? $decoded : $record[$f];
    }
}

if (!isset($record['radiographs_notes'])) $record['radiographs_notes'] = null;
if (!isset($record['tooth_image'])) $record['tooth_image'] = null;

echo json_encode(['success'=>true,'record'=>$record,'schedules'=>$schedules]);

?>
