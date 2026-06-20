<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
include_once __DIR__ . '/../appointments/availability_guard.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';
header('Content-Type: application/json');

check_session(['doctor']);
capabilities_require_json('clinical.save');
$doctor_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

if (!function_exists('normalize_positive_int_list')) {
    function normalize_positive_int_list($values) {
        if (!is_array($values)) return [];
        $out = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) $out[$id] = $id;
        }
        return array_values($out);
    }
}

if (!function_exists('extract_service_ids_from_node')) {
    function extract_service_ids_from_node($node, &$bucket) {
        if (is_array($node)) {
            if (array_key_exists('service_id', $node)) {
                $sid = (int)$node['service_id'];
                if ($sid > 0) $bucket[$sid] = $sid;
            }
            foreach ($node as $child) {
                if (is_array($child)) extract_service_ids_from_node($child, $bucket);
            }
        }
    }
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$recommended_services_raw = isset($_POST['recommended_services']) ? $_POST['recommended_services'] : '[]';
$recommended_services_decoded = json_decode($recommended_services_raw, true);
$recommended_services = normalize_positive_int_list(is_array($recommended_services_decoded) ? $recommended_services_decoded : []);

$treatment_plan_raw = isset($_POST['treatment_plan']) ? trim((string)$_POST['treatment_plan']) : '';
$treatment_plan = null;
if ($treatment_plan_raw !== '') {
    $decodedTreatment = json_decode($treatment_plan_raw, true);
    $treatment_plan = (json_last_error() === JSON_ERROR_NONE) ? $decodedTreatment : $treatment_plan_raw;
}

$materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
if (!is_array($materials_used)) $materials_used = null;

$odontogram = isset($_POST['odontogram']) ? json_decode($_POST['odontogram'], true) : null;
if (is_array($odontogram)) $odontogram = odontogram_normalize_data($odontogram);

$doctor_notes = $_POST['doctor_notes'] ?? '';
$follow_up_date = null;
$submit_and_finalize = isset($_POST['finalize']) && $_POST['finalize'] == '1';

$doctorFirstName = trim((string)($_SESSION['first_name'] ?? ''));
$doctorLastName = trim((string)($_SESSION['last_name'] ?? ''));
$doctorDisplayName = trim($doctorFirstName . ' ' . $doctorLastName);
if ($doctorDisplayName === '') $doctorDisplayName = 'doctor #' . (int)$doctor_id;

if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare('SELECT appointment_id, patient_id, user_id FROM appointments WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$appt) throw new Exception('Appointment not found');
    $patient_id = (int)$appt['patient_id'];

    $profiling_json = isset($_POST['profiling']) ? $_POST['profiling'] : null;

    $treatment_service_map = [];
    if (is_array($treatment_plan)) {
        extract_service_ids_from_node($treatment_plan, $treatment_service_map);
    }
    $service_ids = array_values(array_unique(array_merge($recommended_services, array_values($treatment_service_map))));

    $uploaded_images = [];
    if (!empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $baseDir = __DIR__ . '/../../uploads/clinical_images/' . $appointment_id . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmp = $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue;
            if (filesize($tmp) > 5 * 1024 * 1024) continue;
            $ext = image_type_to_extension($info[2]);
            $fname = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            if (@move_uploaded_file($tmp, $baseDir . $fname)) {
                $uploaded_images[] = 'assets/uploads/clinical_images/' . $appointment_id . '/' . $fname;
            }
        }
    }
    if (!empty($_FILES['tooth_images'])) {
        $files = $_FILES['tooth_images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $baseDir = __DIR__ . '/../../uploads/clinical_images/' . $appointment_id . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmp = $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue;
            if (filesize($tmp) > 5 * 1024 * 1024) continue;
            $ext = image_type_to_extension($info[2]);
            $fname = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            if (@move_uploaded_file($tmp, $baseDir . $fname)) {
                $uploaded_images[] = 'assets/uploads/clinical_images/' . $appointment_id . '/' . $fname;
            }
        }
    }

    $stmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $status = $submit_and_finalize ? 'submitted' : 'in_progress';
    $submitted_flag = $submit_and_finalize ? 1 : 0;

    if ($existing) {
        $stmt = $conn->prepare('UPDATE clinical_records SET doctor_id = ?, patient_id = ?, doctor_notes = ?, follow_up_date = ?, status = ?, submitted_by = ?, submitted_at = IF(?=1,NOW(),submitted_at), primary_image = IFNULL(?, primary_image) WHERE appointment_id = ?');
        $primary_image = !empty($uploaded_images) ? $uploaded_images[0] : null;
        $stmt->bind_param('iisssii si', $doctor_id, $patient_id, $doctor_notes, $follow_up_date, $status, $doctor_id, $submitted_flag, $primary_image, $appointment_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, doctor_notes, follow_up_date, status, submitted_by, submitted_at, primary_image) VALUES (?, ?, ?, ?, ?, ?, ?, IF(?=1,NOW(),NULL), ?)');
        $primary_image = !empty($uploaded_images) ? $uploaded_images[0] : null;
        $stmt->bind_param('iiisssi s', $appointment_id, $doctor_id, $patient_id, $doctor_notes, $follow_up_date, $status, $doctor_id, $submitted_flag, $primary_image);
        $stmt->execute();
        $stmt->close();
    }

    $recStmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $recStmt->bind_param('i', $appointment_id);
    $recStmt->execute();
    $recRes = $recStmt->get_result();
    $recRow = $recRes ? $recRes->fetch_assoc() : null;
    $recStmt->close();
    $record_id = $recRow ? (int)$recRow['record_id'] : 0;

    if ($record_id > 0) {
        if (!empty($uploaded_images)) {
            $conn->query("DELETE FROM clinical_record_images WHERE record_id = $record_id");
            $imgStmt = $conn->prepare('INSERT INTO clinical_record_images (record_id, file_path, file_type, uploaded_by) VALUES (?, ?, ?, ?)');
            foreach ($uploaded_images as $idx => $imgPath) {
                $fileType = 'photo';
                $imgStmt->bind_param('issi', $record_id, $imgPath, $fileType, $doctor_id);
                $imgStmt->execute();
            }
            $imgStmt->close();
        }

        $conn->query("DELETE FROM clinical_record_services WHERE record_id = $record_id");
        if (!empty($service_ids)) {
            $svcStmt = $conn->prepare('INSERT INTO clinical_record_services (record_id, service_id) VALUES (?, ?)');
            foreach ($service_ids as $sid) {
                $svcStmt->bind_param('ii', $record_id, $sid);
                $svcStmt->execute();
            }
            $svcStmt->close();
        }

        $conn->query("DELETE FROM treatment_plans WHERE record_id = $record_id");
        if (is_array($treatment_plan) && !empty($treatment_plan)) {
            $flatItems = [];
            function flatten_tp_node($node, &$flat) {
                if (!is_array($node)) return;
                if (isset($node['service_id']) || isset($node['description'])) {
                    $flat[] = [
                        'service_id' => isset($node['service_id']) ? (int)$node['service_id'] : null,
                        'tooth_number' => isset($node['tooth_number']) ? (int)$node['tooth_number'] : null,
                        'description' => (string)($node['description'] ?? ''),
                        'status' => (string)($node['status'] ?? 'planned'),
                    ];
                }
                foreach ($node as $child) {
                    if (is_array($child)) flatten_tp_node($child, $flat);
                }
            }
            flatten_tp_node($treatment_plan, $flatItems);
            $tpStmt = $conn->prepare('INSERT INTO treatment_plans (record_id, service_id, tooth_number, description, status) VALUES (?, ?, ?, ?, ?)');
            foreach ($flatItems as $tp) {
                $tpStmt->bind_param('iiiss', $record_id, $tp['service_id'], $tp['tooth_number'], $tp['description'], $tp['status']);
                $tpStmt->execute();
            }
            $tpStmt->close();
        } elseif (is_string($treatment_plan) && $treatment_plan !== '') {
            $tpStmt = $conn->prepare('INSERT INTO treatment_plans (record_id, description, status) VALUES (?, ?, ?)');
            $planned = 'planned';
            $tpStmt->bind_param('iss', $record_id, $treatment_plan, $planned);
            $tpStmt->execute();
            $tpStmt->close();
        }

        $conn->query("DELETE FROM treatment_materials WHERE record_id = $record_id");
        if ($materials_used && is_array($materials_used)) {
            $matStmt = $conn->prepare('INSERT INTO treatment_materials (record_id, material_name, quantity, unit, cost) VALUES (?, ?, ?, ?, ?)');
            foreach ($materials_used as $m) {
                $matName = (string)($m['name'] ?? $m['material_name'] ?? '');
                $qty = (float)($m['qty'] ?? $m['quantity'] ?? 1);
                $unit = (string)($m['unit'] ?? '');
                $cost = isset($m['price']) ? (float)$m['price'] : (isset($m['cost']) ? (float)$m['cost'] : null);
                $matStmt->bind_param('isdsd', $record_id, $matName, $qty, $unit, $cost);
                $matStmt->execute();
            }
            $matStmt->close();
        }

        if (is_array($odontogram) && !empty($odontogram)) {
            $conn->query("DELETE FROM tooth_status WHERE record_id = $record_id");

            $codeToTypeId = [];
            $codeRes = $conn->query('SELECT status_type_id, code FROM tooth_status_type');
            if ($codeRes) {
                while ($crow = $codeRes->fetch_assoc()) {
                    $codeToTypeId[$crow['code']] = (int)$crow['status_type_id'];
                }
            }

            $tsStmt = $conn->prepare('INSERT INTO tooth_status (record_id, tooth_selected, status_type_id, tooth_surface, status) VALUES (?, ?, ?, ?, ?)');
            foreach ($odontogram as $fdiKey => $toothData) {
                if (!is_array($toothData)) continue;
                $toothNum = (int)$fdiKey;
                if ($toothNum <= 0) continue;
                $surfaces = $toothData['surfaces'] ?? [];
                if (empty($surfaces)) {
                    $comment = trim((string)($toothData['comment'] ?? ''));
                    if ($comment !== '') {
                        $tsStmt->bind_param('iiiss', $record_id, $toothNum, 0, '', $comment);
                        $tsStmt->execute();
                    }
                    continue;
                }
                foreach ($surfaces as $surfaceName => $codes) {
                    $codeList = is_array($codes) ? $codes : [$codes];
                    foreach ($codeList as $code) {
                        $code = (string)$code;
                        $typeId = $codeToTypeId[$code] ?? 0;
                        $tsStmt->bind_param('iiiss', $record_id, $toothNum, $typeId, $surfaceName, $code);
                        $tsStmt->execute();
                    }
                }
            }
            $tsStmt->close();
        }

        if ($profiling_json !== null) {
            $profData = json_decode($profiling_json, true);
            if (is_array($profData)) {
                $conn->query("DELETE FROM patient_profiling WHERE patient_id = $patient_id AND record_id = $record_id");
                $pfStmt = $conn->prepare('INSERT INTO patient_profiling (patient_id, record_id, race, occupation, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, asa_classification, pregnancy_status, previous_dental_treatment, dental_anxiety, emergency_contact, emergency_phone, background_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $pfStmt->bind_param('iisssssssiiiiisssssss',
                    $patient_id, $record_id,
                    $profData['race'] ?? null, $profData['occupation'] ?? null,
                    $profData['medical_history'] ?? null, $profData['medications'] ?? null,
                    $profData['allergies'] ?? null, $profData['smoking'] ?? null,
                    $profData['alcohol'] ?? null,
                    (int)($profData['hypertensive'] ?? 0), (int)($profData['diabetic'] ?? 0),
                    (int)($profData['asthmatic'] ?? 0), (int)($profData['heart_disease'] ?? 0),
                    (int)($profData['hiv_positive'] ?? 0),
                    $profData['bleeding_disorder'] ?? null, $profData['asa'] ?? null,
                    $profData['pregnancy_status'] ?? null, $profData['previous_dental_treatment'] ?? null,
                    $profData['dental_anxiety'] ?? null, $profData['emergency_contact'] ?? null,
                    $profData['emergency_phone'] ?? null, $profData['background_notes'] ?? null
                );
                $pfStmt->execute();
                $pfStmt->close();
            }
        }
    }

    $existingServiceIds = [];
    $findExistingServices = $conn->prepare('SELECT service_id FROM selected_services WHERE appointment_id = ?');
    if ($findExistingServices) {
        $findExistingServices->bind_param('i', $appointment_id);
        $findExistingServices->execute();
        $existingServiceRes = $findExistingServices->get_result();
        while ($existingServiceRes && ($existingServiceRow = $existingServiceRes->fetch_assoc())) {
            $sid = (int)($existingServiceRow['service_id'] ?? 0);
            if ($sid > 0) $existingServiceIds[$sid] = $sid;
        }
        $findExistingServices->close();
    }

    if (!empty($existingServiceIds)) {
        $service_ids = normalize_positive_int_list(array_merge($service_ids, array_values($existingServiceIds)));
    }

    $delServices = $conn->prepare('DELETE FROM selected_services WHERE appointment_id = ?');
    if ($delServices) {
        $delServices->bind_param('i', $appointment_id);
        $delServices->execute();
        $delServices->close();
    }

    if (!empty($service_ids)) {
        $serviceNote = 'From clinical record';
        $insService = $conn->prepare('INSERT INTO selected_services (appointment_id, service_id, notes) VALUES (?, ?, ?)');
        if ($insService) {
            foreach ($service_ids as $sid) {
                $insService->bind_param('iis', $appointment_id, $sid, $serviceNote);
                if (!$insService->execute()) {
                    $insService->close();
                    throw new Exception('Failed to save selected services: ' . $conn->error);
                }
            }
            $insService->close();
        }
    }

    if ($submit_and_finalize) {
        $status = 'completed';
        $ust = $conn->prepare("UPDATE appointments SET status = ?, notes = CONCAT(IFNULL(notes,''), ?) WHERE appointment_id = ?");
        $note_append = "\n[Clinical record submitted by " . $doctorDisplayName . "]";
        $ust->bind_param('ssi', $status, $note_append, $appointment_id);
        $ust->execute();
        $ust->close();

        $total = 0.0;
        if (!empty($service_ids)) {
            foreach ($service_ids as $sid) {
                $sstmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
                $sstmt->bind_param('i', $sid);
                $sstmt->execute();
                $res = $sstmt->get_result();
                if ($res && ($r = $res->fetch_assoc())) {
                    $total += (float)$r['initial_price'];
                }
                $sstmt->close();
            }
        }
        if ($materials_used && is_array($materials_used)) {
            foreach ($materials_used as $m) {
                $qty = isset($m['qty']) ? (float)$m['qty'] : 0;
                $price = isset($m['price']) ? (float)$m['price'] : 0;
                $total += $qty * $price;
            }
        }
        $doctor_fee = isset($_POST['doctor_fee']) ? (float)$_POST['doctor_fee'] : 0.0;
        if ($doctor_fee > 0) $total += $doctor_fee;

        $bcheck = $conn->prepare('SELECT billing_id FROM billing WHERE appointment_id = ? LIMIT 1');
        if ($bcheck) {
            $bcheck->bind_param('i', $appointment_id);
            $bcheck->execute();
            $bres = $bcheck->get_result();
            $brow = $bres ? $bres->fetch_assoc() : null;
            $bcheck->close();
            if ($brow && !empty($brow['billing_id'])) {
                $bid = (int)$brow['billing_id'];
                $bust = $conn->prepare('UPDATE billing SET total_amount = ?, created_at = NOW() WHERE billing_id = ?');
                if ($bust) {
                    $bust->bind_param('di', $total, $bid);
                    $bust->execute();
                    $bust->close();
                }
            } else {
                $bstmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, created_at) VALUES (?, ?, ?, NOW())');
                if (!$bstmt) throw new Exception('Prepare failed (billing insert): ' . $conn->error);
                $bstmt->bind_param('iid', $appointment_id, $patient_id, $total);
                $bstmt->execute();
                $bstmt->close();
            }
        } else {
            $bstmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, created_at) VALUES (?, ?, ?, NOW())');
            if ($bstmt) {
                $bstmt->bind_param('iid', $appointment_id, $patient_id, $total);
                $bstmt->execute();
                $bstmt->close();
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Clinical record saved',
        'finalized' => $submit_and_finalize,
        'billing_amount' => isset($total) ? $total : null,
        'schedule_id' => null,
        'followup_appointment_id' => null,
        'needs_scheduling' => false
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    $logDir = __DIR__ . '/../../../../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    @file_put_contents($logDir . '/save_clinical_record_error.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
