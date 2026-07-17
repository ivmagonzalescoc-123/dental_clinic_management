<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['doctor','secretary','admin','patient'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$appointment_id = (int)($_GET['appointment_id'] ?? 0);
$record_id = (int)($_GET['record_id'] ?? 0);

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

if ($role === 'patient') {
    $sessionPatientId = (int)($_SESSION['patient_id'] ?? 0);
    if ($sessionPatientId <= 0) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Forbidden']);
        exit;
    }

    $ownStmt = $conn->prepare('SELECT patient_id FROM appointments WHERE appointment_id = ? LIMIT 1');
    if ($ownStmt) {
        $ownStmt->bind_param('i', $appointment_id);
        $ownStmt->execute();
        $ownRow = $ownStmt->get_result()->fetch_assoc();
        $ownStmt->close();
        if (!$ownRow || (int)($ownRow['patient_id'] ?? 0) !== $sessionPatientId) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Forbidden']);
            exit;
        }
    }
}

$stmt = $conn->prepare('SELECT * FROM clinical_records WHERE appointment_id = ?');
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$res = $stmt->get_result();
$record = $res ? $res->fetch_assoc() : null;
$stmt->close();

$s = $conn->prepare('SELECT * FROM patient_schedules WHERE appointment_id = ? ORDER BY scheduled_start DESC');
$s->bind_param('i', $appointment_id);
$s->execute();
$sres = $s->get_result();
$schedules = [];
while ($r = $sres->fetch_assoc()) $schedules[] = $r;
$s->close();

if ($record && isset($record['record_id']) && $record['record_id']) {
    $recId = (int)$record['record_id'];

    $cstmt = $conn->prepare('SELECT crs.service_id, s.service_name, s.initial_price FROM clinical_record_services crs LEFT JOIN services s ON s.service_id = crs.service_id WHERE crs.record_id = ?');
    $cstmt->bind_param('i', $recId);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    $recServices = [];
    while ($crow = $cres->fetch_assoc()) $recServices[] = (int)$crow['service_id'];
    $cstmt->close();
    $record['recommended_services'] = $recServices;

    $tpstmt = $conn->prepare('SELECT * FROM treatment_plans WHERE record_id = ?');
    $tpstmt->bind_param('i', $recId);
    $tpstmt->execute();
    $tpres = $tpstmt->get_result();
    $treatments = [];
    while ($tprow = $tpres->fetch_assoc()) $treatments[] = $tprow;
    $tpstmt->close();
    $record['treatment_plan'] = $treatments;

    $mtstmt = $conn->prepare('SELECT * FROM treatment_materials WHERE record_id = ?');
    $mtstmt->bind_param('i', $recId);
    $mtstmt->execute();
    $mtres = $mtstmt->get_result();
    $materials = [];
    while ($mtrow = $mtres->fetch_assoc()) $materials[] = $mtrow;
    $mtstmt->close();
    $record['materials_used'] = $materials;

    $imgStmt = $conn->prepare('SELECT file_path, file_type, tooth_number FROM clinical_record_images WHERE record_id = ?');
    $imgStmt->bind_param('i', $recId);
    $imgStmt->execute();
    $imgRes = $imgStmt->get_result();
    $images = [];
    while ($imgRow = $imgRes->fetch_assoc()) $images[] = $imgRow['file_path'];
    $imgStmt->close();
    $record['images'] = $images;
    if (!isset($record['primary_image'])) $record['primary_image'] = null;
}

$odontogram = null;
if (isset($record['record_id']) && $record['record_id']) {
    $recIdForOdonto = (int)$record['record_id'];
    $toothStmt = $conn->prepare('SELECT ts.tooth_selected, ts.status_type_id, ts.tooth_surface, ts.status, ts.notes, tst.label, tst.code FROM tooth_status ts LEFT JOIN tooth_status_type tst ON tst.status_type_id = ts.status_type_id WHERE ts.record_id = ?');
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
        $noteText = trim((string)($trow['notes'] ?? ''));
        if ($toothNum <= 0) continue;
        if (!isset($odontogramFormatted[$toothNum])) {
            $odontogramFormatted[$toothNum] = ['comment' => '', 'surfaces' => []];
        }
        if ($noteText !== '' && $odontogramFormatted[$toothNum]['comment'] === '') {
            $odontogramFormatted[$toothNum]['comment'] = $noteText;
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
$record['odontogram'] = $odontogram;

if ($record && isset($record['patient_id']) && $record['patient_id']) {
    $pstmt = $conn->prepare('SELECT * FROM patient_profiling WHERE patient_id = ? ORDER BY profiling_id DESC LIMIT 1');
    if ($pstmt) {
        $pstmt->bind_param('i', $record['patient_id']);
        $pstmt->execute();
        $pres = $pstmt->get_result();
        $prow = $pres ? $pres->fetch_assoc() : null;
        $pstmt->close();
        if ($prow) {
            $record['patient_profiling'] = $prow;
            $record['profiling'] = $prow;
        }
    }
}

if ($record && $role === 'secretary') unset($record['odontogram']);

$prescriptions = [];
$p_stmt = $conn->prepare('SELECT * FROM prescriptions WHERE appointment_id = ?');
if ($p_stmt) {
    $p_stmt->bind_param('i', $appointment_id);
    $p_stmt->execute();
    $p_res = $p_stmt->get_result();
    while ($p_row = $p_res->fetch_assoc()) $prescriptions[] = $p_row;
    $p_stmt->close();
}

if (!isset($record['radiographs_notes'])) $record['radiographs_notes'] = null;

$patientName = '';
$appointmentDate = '';
$appointmentMeta = null;
$patientInfo = null;
$apptStmt = $conn->prepare('SELECT a.appointment_date, a.status, a.completed_by_doctor_at, a.secretary_records_completed_at, p.first_name, p.last_name, p.email, p.phone_number, p.address, p.profile_image FROM appointments a INNER JOIN patients p ON p.patient_id = a.patient_id WHERE a.appointment_id = ? LIMIT 1');
if ($apptStmt) {
    $apptStmt->bind_param('i', $appointment_id);
    $apptStmt->execute();
    $apptRow = $apptStmt->get_result()->fetch_assoc();
    $apptStmt->close();
    if ($apptRow) {
        $patientName = trim((string)($apptRow['first_name'] ?? '') . ' ' . (string)($apptRow['last_name'] ?? ''));
        $appointmentDate = (string)($apptRow['appointment_date'] ?? '');
        $appointmentMeta = [
            'status' => (string)($apptRow['status'] ?? ''),
            'completed_by_doctor_at' => $apptRow['completed_by_doctor_at'] ?? null,
            'secretary_records_completed_at' => $apptRow['secretary_records_completed_at'] ?? null,
        ];
        $patientInfo = [
            'name' => $patientName,
            'email' => (string)($apptRow['email'] ?? ''),
            'phone' => (string)($apptRow['phone_number'] ?? ''),
            'address' => (string)($apptRow['address'] ?? ''),
            'profile_image' => (string)($apptRow['profile_image'] ?? ''),
        ];
    }
}

$followUpAppointments = [];
$fuStmt = $conn->prepare('SELECT appointment_id, appointment_date, status, reason FROM appointments WHERE rescheduled_from = ? ORDER BY appointment_date ASC, appointment_id ASC');
if ($fuStmt) {
    $fuStmt->bind_param('i', $appointment_id);
    $fuStmt->execute();
    $fuRes = $fuStmt->get_result();
    while ($fuRow = $fuRes->fetch_assoc()) $followUpAppointments[] = $fuRow;
    $fuStmt->close();
}

if ($record) {
    $record['_patient_name'] = $patientName !== '' ? $patientName : 'Unknown patient';
    $record['_appointment_date'] = $appointmentDate;
}

$servicesAvailed = [];
billing_ensure_tables($conn);
$liCols = billing_line_items_columns($conn);
$idCol = isset($liCols['item_id']) ? 'item_id' : 'line_id';
$hasItemType = isset($liCols['item_type']);
$selectCols = 'description, amount' . ($hasItemType ? ', item_type' : '') . (isset($liCols['service_id']) ? ', service_id' : '');
$liStmt = $conn->prepare("SELECT $selectCols FROM billing_line_items WHERE appointment_id = ? ORDER BY $idCol ASC");
if ($liStmt) {
    $liStmt->bind_param('i', $appointment_id);
    $liStmt->execute();
    $liRes = $liStmt->get_result();
    while ($row = $liRes->fetch_assoc()) {
        $desc = trim((string)($row['description'] ?? ''));
        if ($desc === '') continue;
        $servicesAvailed[] = [
            'description' => $desc,
            'amount' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
            'item_type' => (string)($row['item_type'] ?? 'service'),
            'service_id' => isset($row['service_id']) ? (int)$row['service_id'] : null,
        ];
    }
    $liStmt->close();
}

if (empty($servicesAvailed)) {
    [, $fallbackItems] = billing_sum_appointment_services($conn, $appointment_id);
    foreach ($fallbackItems as $item) {
        $desc = trim((string)($item['description'] ?? ''));
        if ($desc === '') continue;
        $servicesAvailed[] = [
            'description' => $desc,
            'amount' => (float)($item['amount'] ?? 0),
            'item_type' => (string)($item['item_type'] ?? 'service'),
            'service_id' => isset($item['service_id']) ? (int)$item['service_id'] : null,
        ];
    }
}

if (empty($servicesAvailed) && $record && !empty($record['recommended_services']) && is_array($record['recommended_services'])) {
    $svcIds = array_values(array_filter(array_map('intval', $record['recommended_services'])));
    if (!empty($svcIds)) {
        $placeholders = implode(',', array_fill(0, count($svcIds), '?'));
        $types = str_repeat('i', count($svcIds));
        $svcStmt = $conn->prepare("SELECT service_id, service_name, initial_price FROM services WHERE service_id IN ($placeholders)");
        if ($svcStmt) {
            $svcStmt->bind_param($types, ...$svcIds);
            $svcStmt->execute();
            $svcRes = $svcStmt->get_result();
            $nameById = [];
            $priceById = [];
            while ($svcRow = $svcRes->fetch_assoc()) {
                $sid = (int)$svcRow['service_id'];
                $nameById[$sid] = (string)$svcRow['service_name'];
                $priceById[$sid] = (float)($svcRow['initial_price'] ?? 0);
            }
            $svcStmt->close();
            foreach ($svcIds as $sid) {
                if (!isset($nameById[$sid])) continue;
                $servicesAvailed[] = [
                    'description' => $nameById[$sid],
                    'amount' => $priceById[$sid] ?? 0.0,
                    'item_type' => 'service',
                    'service_id' => $sid,
                ];
            }
        }
    }
}

$appointmentMedia = [];
$mediaStmt = $conn->prepare('SELECT file_name, file_path FROM appointment_media WHERE appointment_id = ? ORDER BY media_order ASC');
if ($mediaStmt) {
    $mediaStmt->bind_param('i', $appointment_id);
    $mediaStmt->execute();
    $mediaRes = $mediaStmt->get_result();
    while ($mediaRow = $mediaRes->fetch_assoc()) {
        $path = trim((string)($mediaRow['file_path'] ?? ''));
        if ($path === '') continue;
        $appointmentMedia[] = ['file_name' => (string)($mediaRow['file_name'] ?? ''), 'file_path' => $path];
    }
    $mediaStmt->close();
}

if ($record) {
    $existingImages = $record['images'] ?? [];
    if (!empty($appointmentMedia)) {
        $record['images'] = array_values(array_unique(array_merge($existingImages, array_map(function ($row) { return (string)$row['file_path']; }, $appointmentMedia))));
    } else {
        $record['images'] = $existingImages;
    }
}

$recordStatus = is_array($record) ? (string)($record['status'] ?? '') : '';
$isDoctorSessionOnly = ($recordStatus === 'submitted');
$isFinalizedRecord = ($recordStatus === 'finalized');

echo json_encode([
    'success' => true,
    'record' => $record,
    'appointment' => $appointmentMeta,
    'patient_info' => $patientInfo,
    'record_status' => $recordStatus,
    'is_doctor_session_only' => $isDoctorSessionOnly,
    'is_finalized_record' => $isFinalizedRecord,
    'schedules' => $schedules,
    'follow_up_appointments' => $followUpAppointments,
    'prescriptions' => $prescriptions,
    'services_availed' => $servicesAvailed,
    'read_only_for_doctor' => ($role === 'doctor'),
]);
