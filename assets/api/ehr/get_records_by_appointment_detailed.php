<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
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
$s = $conn->prepare('SELECT * FROM patient_schedules WHERE appointment_id = ? ORDER BY scheduled_start DESC');
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
            if (is_array($decoded)) {
                $record['patient_profiling'] = filter_background_profiling_data($decoded);
            }
        }
    }
}

// Hide odontogram for secretary
if ($record && $role === 'secretary') unset($record['odontogram']);

// Fetch prescriptions
$prescriptions = [];
$p_stmt = $conn->prepare('SELECT * FROM prescriptions WHERE appointment_id = ?');
if ($p_stmt) {
    $p_stmt->bind_param('i', $appointment_id);
    $p_stmt->execute();
    $p_res = $p_stmt->get_result();
    while ($p_row = $p_res->fetch_assoc()) {
        $prescriptions[] = $p_row;
    }
    $p_stmt->close();
}

$toDecode = ['recommended_services','treatment_plan','materials_used','odontogram','images','profiling'];
foreach ($toDecode as $f) {
    if (isset($record[$f]) && $record[$f] !== null) {
        $decoded = json_decode($record[$f], true);
        $record[$f] = ($decoded !== null) ? $decoded : $record[$f];
    }
}

if ($record && isset($record['profiling']) && is_array($record['profiling'])) {
    $record['visit_clinical'] = filter_visit_clinical_data($record['profiling']);
    $record['profiling'] = $record['visit_clinical'];
}

if (!isset($record['radiographs_notes'])) $record['radiographs_notes'] = null;
if (!isset($record['tooth_image'])) $record['tooth_image'] = null;

$patientName = '';
$appointmentDate = '';
$appointmentMeta = null;
$apptStmt = $conn->prepare('
    SELECT a.appointment_date, a.status, a.completed_by_doctor_at, a.secretary_records_completed_at,
           p.first_name, p.last_name
    FROM appointments a
    INNER JOIN patients p ON p.patient_id = a.patient_id
    WHERE a.appointment_id = ?
    LIMIT 1
');
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
    }
}

$followUpAppointments = [];
$fuStmt = $conn->prepare('
    SELECT appointment_id, appointment_date, status, reason
    FROM appointments
    WHERE rescheduled_from = ?
    ORDER BY appointment_date ASC, appointment_id ASC
');
if ($fuStmt) {
    $fuStmt->bind_param('i', $appointment_id);
    $fuStmt->execute();
    $fuRes = $fuStmt->get_result();
    while ($fuRow = $fuRes->fetch_assoc()) {
        $followUpAppointments[] = $fuRow;
    }
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
        if ($desc === '') {
            continue;
        }
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
        if ($desc === '') {
            continue;
        }
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
                if (!isset($nameById[$sid])) {
                    continue;
                }
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
        if ($path === '') {
            continue;
        }
        $appointmentMedia[] = [
            'file_name' => (string)($mediaRow['file_name'] ?? ''),
            'file_path' => $path,
        ];
    }
    $mediaStmt->close();
}

if ($record) {
    $existingImages = [];
    if (!empty($record['images']) && is_array($record['images'])) {
        $existingImages = $record['images'];
    }
    if (!empty($appointmentMedia)) {
        $record['images'] = array_values(array_unique(array_merge(
            $existingImages,
            array_map(static function ($row) {
                return (string)$row['file_path'];
            }, $appointmentMedia)
        )));
    } elseif (!isset($record['images']) || !is_array($record['images'])) {
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
    'record_status' => $recordStatus,
    'is_doctor_session_only' => $isDoctorSessionOnly,
    'is_finalized_record' => $isFinalizedRecord,
    'schedules' => $schedules,
    'follow_up_appointments' => $followUpAppointments,
    'prescriptions' => $prescriptions,
    'services_availed' => $servicesAvailed,
    'read_only_for_doctor' => ($role === 'doctor'),
]);

?>
