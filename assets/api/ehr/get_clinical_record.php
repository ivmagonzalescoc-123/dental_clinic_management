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
$record_id = (int)($_GET['record_id'] ?? 0);
$patient_id = (int)($_GET['patient_id'] ?? 0);

if ($record_id > 0) {
    $stmt = $conn->prepare('SELECT * FROM clinical_records WHERE record_id = ?');
    $stmt->bind_param('i', $record_id);
} elseif ($appointment_id > 0) {
    $stmt = $conn->prepare('SELECT * FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
} elseif ($patient_id > 0) {
    // fetch the most recent clinical record for this patient
    $stmt = $conn->prepare('SELECT * FROM clinical_records WHERE patient_id = ? ORDER BY COALESCE(submitted_at, record_id) DESC LIMIT 1');
    $stmt->bind_param('i', $patient_id);
} else {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing appointment_id, record_id, or patient_id']);
    exit;
}
$stmt->execute();
$res = $stmt->get_result();
$rec = $res ? $res->fetch_assoc() : null;
$stmt->close();

// If no record found by appointment_id, try falling back to latest by patient for convenience
if (!$rec && $appointment_id > 0) {
    $a = $conn->prepare('SELECT patient_id, appointment_date FROM appointments WHERE appointment_id = ? LIMIT 1');
    $a->bind_param('i', $appointment_id);
    $a->execute();
    $ar = $a->get_result();
    $aRow = $ar ? $ar->fetch_assoc() : null;
    $a->close();
    if ($aRow && !empty($aRow['patient_id'])) {
        $patient_id = (int)$aRow['patient_id'];
        $stmt2 = $conn->prepare('SELECT * FROM clinical_records WHERE patient_id = ? ORDER BY COALESCE(submitted_at, record_id) DESC LIMIT 1');
        $stmt2->bind_param('i', $patient_id);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        $rec = $r2 ? $r2->fetch_assoc() : null;
        $stmt2->close();
        // attach appointment date as context
        if ($rec) {
            $rec['_appointment_date'] = $aRow['appointment_date'];
        }
    }
}

if (!$rec) {
    echo json_encode(['success'=>true,'record'=>null]);
    exit;
}

// Hide odontogram for secretary
if ($role === 'secretary') {
    unset($rec['odontogram']);
}

// Also fetch appointment and patient basic info for display
$appt_stmt = $conn->prepare('SELECT a.appointment_date, p.first_name, p.last_name, p.email, p.phone_number FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ? LIMIT 1');
$appt_stmt->bind_param('i', $appointment_id);
$appt_stmt->execute();
$appt_res = $appt_stmt->get_result();
$appt_row = $appt_res ? $appt_res->fetch_assoc() : null;
$appt_stmt->close();
if ($appt_row) {
    $rec['_appointment_date'] = $appt_row['appointment_date'];
    $rec['_patient_name'] = trim(($appt_row['first_name'] ?? '') . ' ' . ($appt_row['last_name'] ?? ''));
    $rec['_patient_email'] = $appt_row['email'] ?? '';
    $rec['_patient_phone'] = $appt_row['phone_number'] ?? '';
}

// Attach patient-level profiling (if available)
if (isset($rec['patient_id']) && $rec['patient_id']) {
    $pstmt = $conn->prepare('SELECT profiling FROM patients WHERE patient_id = ? LIMIT 1');
    if ($pstmt) {
        $pstmt->bind_param('i', $rec['patient_id']);
        $pstmt->execute();
        $pres = $pstmt->get_result();
        $prow = $pres ? $pres->fetch_assoc() : null;
        $pstmt->close();
        if ($prow && isset($prow['profiling']) && $prow['profiling'] !== null) {
            $decoded = json_decode($prow['profiling'], true);
            if ($decoded !== null) $rec['patient_profiling'] = $decoded;
        }
    }
}

// Ensure JSON/text fields are decoded or present
$toDecode = ['recommended_services','treatment_plan','materials_used','odontogram','images','profiling'];
foreach ($toDecode as $f) {
    if (isset($rec[$f]) && $rec[$f] !== null) {
        $decoded = json_decode($rec[$f], true);
        $rec[$f] = ($decoded !== null) ? $decoded : $rec[$f];
    }
}

// If recommended_services is an array of IDs, fetch service details and replace with objects
if (isset($rec['recommended_services']) && is_array($rec['recommended_services']) && count($rec['recommended_services']) > 0) {
    // sanitize and cast to ints
    $ids = array_values(array_map('intval', $rec['recommended_services']));
    // build IN list safely as ints
    $inList = implode(',', $ids);
    if ($inList !== '') {
        $sSql = "SELECT service_id, service_name, initial_price FROM services WHERE service_id IN ($inList)";
        $sRes = $conn->query($sSql);
        $svcMap = [];
        if ($sRes) {
            while ($sr = $sRes->fetch_assoc()) {
                $svcMap[(int)$sr['service_id']] = $sr;
            }
        }
        // preserve original ordering
        $full = [];
        foreach ($ids as $id) {
            if (isset($svcMap[$id])) $full[] = $svcMap[$id];
            else $full[] = ['service_id' => $id, 'service_name' => 'Service #' . $id, 'initial_price' => 0];
        }
        $rec['recommended_services'] = $full;
    }
}

// radiographs_notes and tooth_image may be stored as plain fields - ensure they exist
if (!isset($rec['radiographs_notes'])) $rec['radiographs_notes'] = null;
if (!isset($rec['tooth_image'])) $rec['tooth_image'] = null;

echo json_encode(['success'=>true,'record'=>$rec]);

?>
