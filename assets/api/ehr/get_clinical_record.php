<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
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
        if ($rec) $rec['_appointment_date'] = $aRow['appointment_date'];
    }
}

if (!$rec) {
    echo json_encode(['success'=>true,'record'=>null]);
    exit;
}

if ($role === 'secretary') unset($rec['odontogram']);

$appt_stmt = $conn->prepare('SELECT a.appointment_date, p.first_name, p.last_name, p.email, p.phone_number FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ? LIMIT 1');
if ($appointment_id > 0) {
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
}

if (isset($rec['record_id']) && $rec['record_id']) {
    $recId = (int)$rec['record_id'];

    $cstmt = $conn->prepare('SELECT crs.service_id, s.service_name, s.initial_price FROM clinical_record_services crs LEFT JOIN services s ON s.service_id = crs.service_id WHERE crs.record_id = ?');
    $cstmt->bind_param('i', $recId);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    $svcIds = [];
    while ($crow = $cres->fetch_assoc()) $svcIds[] = (int)$crow['service_id'];
    $cstmt->close();
    $rec['recommended_services'] = $svcIds;

    $tpstmt = $conn->prepare('SELECT * FROM treatment_plans WHERE record_id = ?');
    $tpstmt->bind_param('i', $recId);
    $tpstmt->execute();
    $tpres = $tpstmt->get_result();
    $treatments = [];
    while ($tprow = $tpres->fetch_assoc()) $treatments[] = $tprow;
    $tpstmt->close();
    $rec['treatment_plan'] = $treatments;

    $mtstmt = $conn->prepare('SELECT * FROM treatment_materials WHERE record_id = ?');
    $mtstmt->bind_param('i', $recId);
    $mtstmt->execute();
    $mtres = $mtstmt->get_result();
    $materials = [];
    while ($mtrow = $mtres->fetch_assoc()) $materials[] = $mtrow;
    $mtstmt->close();
    $rec['materials_used'] = $materials;

    $imgStmt = $conn->prepare('SELECT file_path FROM clinical_record_images WHERE record_id = ?');
    $imgStmt->bind_param('i', $recId);
    $imgStmt->execute();
    $imgRes = $imgStmt->get_result();
    $images = [];
    while ($imgRow = $imgRes->fetch_assoc()) $images[] = $imgRow['file_path'];
    $imgStmt->close();
    $rec['images'] = $images;
}

$odontogram = null;
if (isset($rec['record_id']) && $rec['record_id']) {
    $recIdForOdonto = (int)$rec['record_id'];
    $toothStmt = $conn->prepare('SELECT ts.tooth_selected, ts.status_type_id, ts.tooth_surface, ts.status, tst.label, tst.code FROM tooth_status ts LEFT JOIN tooth_status_type tst ON tst.status_type_id = ts.status_type_id WHERE ts.record_id = ?');
    $toothStmt->bind_param('i', $recIdForOdonto);
    $toothStmt->execute();
    $toothRes = $toothStmt->get_result();
    $rawToothRows = [];
    while ($trow = $toothRes->fetch_assoc()) $rawToothRows[] = $trow;
    $toothStmt->close();
    $odontogramFormatted = [];
    foreach ($rawToothRows as $trow) {
        $toothNum = (int)$trow['tooth_selected'];
        $code = (string)($trow['code'] ?? '');
        $surface = (string)($trow['tooth_surface'] ?? 'O');
        if ($toothNum <= 0) continue;
        if (!isset($odontogramFormatted[$toothNum])) {
            $odontogramFormatted[$toothNum] = ['comment' => '', 'surfaces' => []];
        }
        if ($surface !== '' && $code !== '') {
            if (!isset($odontogramFormatted[$toothNum]['surfaces'][$surface])) {
                $odontogramFormatted[$toothNum]['surfaces'][$surface] = [];
            }
            $odontogramFormatted[$toothNum]['surfaces'][$surface][] = $code;
        }
    }
    $odontogram = empty($odontogramFormatted) ? null : $odontogramFormatted;
}
$rec['odontogram'] = $odontogram;

if (isset($rec['patient_id']) && $rec['patient_id']) {
    $pstmt = $conn->prepare('SELECT * FROM patient_profiling WHERE patient_id = ? ORDER BY profiling_id DESC LIMIT 1');
    if ($pstmt) {
        $pstmt->bind_param('i', $rec['patient_id']);
        $pstmt->execute();
        $pres = $pstmt->get_result();
        $prow = $pres ? $pres->fetch_assoc() : null;
        $pstmt->close();
        if ($prow) {
            $rec['patient_profiling'] = $prow;
            $rec['profiling'] = $prow;
        }
    }
}

if (isset($rec['profiling']) && is_array($rec['profiling'])) {
    $rec['visit_clinical'] = filter_visit_clinical_data($rec['profiling']);
    $rec['profiling'] = $rec['visit_clinical'];
}

if (isset($rec['recommended_services']) && is_array($rec['recommended_services']) && count($rec['recommended_services']) > 0) {
    $ids = array_values(array_map('intval', $rec['recommended_services']));
    $inList = implode(',', $ids);
    if ($inList !== '') {
        $sRes = $conn->query("SELECT service_id, service_name, initial_price FROM services WHERE service_id IN ($inList)");
        $svcMap = [];
        if ($sRes) {
            while ($sr = $sRes->fetch_assoc()) $svcMap[(int)$sr['service_id']] = $sr;
        }
        $full = [];
        foreach ($ids as $id) {
            if (isset($svcMap[$id])) $full[] = $svcMap[$id];
            else $full[] = ['service_id' => $id, 'service_name' => 'Service #' . $id, 'initial_price' => 0];
        }
        $rec['recommended_services'] = $full;
    }
}

if (!isset($rec['radiographs_notes'])) $rec['radiographs_notes'] = null;
if (!isset($rec['primary_image'])) $rec['primary_image'] = null;

echo json_encode(['success'=>true,'record'=>$rec]);
