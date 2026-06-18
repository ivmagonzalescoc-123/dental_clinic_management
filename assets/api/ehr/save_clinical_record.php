<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
include_once __DIR__ . '/../appointments/availability_guard.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';
header('Content-Type: application/json');

// Only doctors may save clinical records
check_session(['doctor']);
capabilities_require_json('clinical.save');
$doctor_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

if (!function_exists('normalize_positive_int_list')) {
    function normalize_positive_int_list($values)
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('extract_service_ids_from_node')) {
    function extract_service_ids_from_node($node, &$bucket)
    {
        if (is_array($node)) {
            if (array_key_exists('service_id', $node)) {
                $sid = (int)$node['service_id'];
                if ($sid > 0) {
                    $bucket[$sid] = $sid;
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    extract_service_ids_from_node($child, $bucket);
                }
            }
        }
    }
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : null;
$recommended_services_raw = isset($_POST['recommended_services']) ? $_POST['recommended_services'] : '[]';
$recommended_services_decoded = json_decode($recommended_services_raw, true);
$recommended_services = normalize_positive_int_list(
    is_array($recommended_services_decoded) ? $recommended_services_decoded : []
);

$treatment_plan_raw = isset($_POST['treatment_plan']) ? trim((string)$_POST['treatment_plan']) : '';
$treatment_plan = null;
if ($treatment_plan_raw !== '') {
    $decodedTreatment = json_decode($treatment_plan_raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $treatment_plan = $decodedTreatment;
    } else {
        // Accept plain text treatment notes in addition to structured JSON payloads.
        $treatment_plan = $treatment_plan_raw;
    }
}

$materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
if (!is_array($materials_used)) {
    $materials_used = null;
}
$odontogram = isset($_POST['odontogram']) ? json_decode($_POST['odontogram'], true) : null;
if (is_array($odontogram)) {
    $odontogram = odontogram_normalize_data($odontogram);
}
$doctor_notes = $_POST['doctor_notes'] ?? '';
$follow_up_date = null;
$submit_and_finalize = isset($_POST['finalize']) && $_POST['finalize'] == '1';

$doctorFirstName = trim((string)($_SESSION['first_name'] ?? ''));
$doctorLastName = trim((string)($_SESSION['last_name'] ?? ''));
$doctorDisplayName = trim($doctorFirstName . ' ' . $doctorLastName);
if ($doctorDisplayName === '') {
    $doctorDisplayName = 'doctor #' . (int)$doctor_id;
}

if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    // Get appointment and patient
    $stmt = $conn->prepare('SELECT appointment_id, patient_id, user_id FROM appointments WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$appt) throw new Exception('Appointment not found');
    $patient_id = (int)$appt['patient_id'];

    // Read profiling payload from POST (if any)
    $profiling_json = isset($_POST['profiling']) ? $_POST['profiling'] : null;

    // Ensure patients table has profiling_locked column
    $colResP = $conn->query("SHOW COLUMNS FROM patients LIKE 'profiling_locked'");
    if (!$colResP || $colResP->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN profiling_locked TINYINT(1) DEFAULT 0");
    }

    // Ensure clinical_records has a `profiling` column to snapshot profiling at time of record
    $colResCR = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'profiling'");
    if (!$colResCR || $colResCR->num_rows === 0) {
        $conn->query("ALTER TABLE clinical_records ADD COLUMN profiling JSON DEFAULT NULL");
    }

    // Fetch current patient profiling & lock status
    $pstmt = $conn->prepare('SELECT profiling, profiling_locked FROM patients WHERE patient_id = ? LIMIT 1');
    $pstmt->bind_param('i', $patient_id);
    $pstmt->execute();
    $pres = $pstmt->get_result();
    $prow = $pres ? $pres->fetch_assoc() : null;
    $pstmt->close();
    $patient_has_profiling = ($prow && !empty($prow['profiling']));
    $patient_locked = ($prow && !empty($prow['profiling_locked']));

    // Upsert clinical_records
    $treatment_service_map = [];
    if (is_array($treatment_plan)) {
        extract_service_ids_from_node($treatment_plan, $treatment_service_map);
    }

    $service_ids = array_values(array_unique(array_merge($recommended_services, array_values($treatment_service_map))));

    $treatment_payload = $treatment_plan;

    $json_recommended = !empty($recommended_services) ? json_encode($recommended_services, JSON_UNESCAPED_UNICODE) : null;
    $json_treatment = ($treatment_payload !== null) ? json_encode($treatment_payload, JSON_UNESCAPED_UNICODE) : null;
    $json_materials = $materials_used ? json_encode($materials_used, JSON_UNESCAPED_UNICODE) : null;
    $json_odontogram = $odontogram ? json_encode($odontogram, JSON_UNESCAPED_UNICODE) : null;
    $json_profiling = $profiling_json ? $profiling_json : null;
    // Handle uploaded images (optional)
    $uploaded_images = [];
    if (!empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $baseDir = __DIR__ . '/../../uploads/clinical_images/' . $appointment_id . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
        for ($i = 0; $i < $count; $i++) {
            $err = $files['error'][$i];
            if ($err !== UPLOAD_ERR_OK) continue;
            $tmp = $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue; // not an image
            $maxSize = 5 * 1024 * 1024; // 5MB
            if (filesize($tmp) > $maxSize) continue;
            $ext = image_type_to_extension($info[2]);
            $fname = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $dest = $baseDir . $fname;
            if (@move_uploaded_file($tmp, $dest)) {
                // web-accessible path
                $webPath = 'assets/uploads/clinical_images/' . $appointment_id . '/' . $fname;
                $uploaded_images[] = $webPath;
            }
        }
    }
    // Also accept tooth-specific images under 'tooth_images' (x-rays / tooth photos)
    if (!empty($_FILES['tooth_images'])) {
        $files = $_FILES['tooth_images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $baseDir = __DIR__ . '/../../uploads/clinical_images/' . $appointment_id . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
        for ($i = 0; $i < $count; $i++) {
            $err = $files['error'][$i];
            if ($err !== UPLOAD_ERR_OK) continue;
            $tmp = $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue; // not an image
            $maxSize = 5 * 1024 * 1024; // 5MB
            if (filesize($tmp) > $maxSize) continue;
            $ext = image_type_to_extension($info[2]);
            $fname = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $dest = $baseDir . $fname;
            if (@move_uploaded_file($tmp, $dest)) {
                $webPath = 'assets/uploads/clinical_images/' . $appointment_id . '/' . $fname;
                $uploaded_images[] = $webPath;
            }
        }
    }
    $json_images = !empty($uploaded_images) ? json_encode(array_values($uploaded_images), JSON_UNESCAPED_UNICODE) : null;

    // Ensure clinical_records has an `images` column (add if missing)
    $colRes = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'images'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE clinical_records ADD COLUMN images JSON DEFAULT NULL");
    }
    // Ensure clinical_records has a `tooth_image` column to store primary tooth/xray path
    $colRes2 = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'tooth_image'");
    if (!$colRes2 || $colRes2->num_rows === 0) {
        $conn->query("ALTER TABLE clinical_records ADD COLUMN tooth_image VARCHAR(255) DEFAULT NULL");
    }

    // Check if record exists
    $stmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare('UPDATE clinical_records SET doctor_id = ?, patient_id = ?, recommended_services = ?, treatment_plan = ?, materials_used = ?, odontogram = ?, images = ?, doctor_notes = ?, follow_up_date = ?, status = ?, profiling = ?, submitted_by = ?, submitted_at = IF(?=1,NOW(),submitted_at) WHERE appointment_id = ?');
        $status = $submit_and_finalize ? 'submitted' : 'in_progress';
        $submitted_flag = $submit_and_finalize ? 1 : 0;
        $stmt->bind_param('iisssssssssiii', $doctor_id, $patient_id, $json_recommended, $json_treatment, $json_materials, $json_odontogram, $json_images, $doctor_notes, $follow_up_date, $status, $json_profiling, $doctor_id, $submitted_flag, $appointment_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, recommended_services, treatment_plan, materials_used, odontogram, images, doctor_notes, follow_up_date, status, profiling, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?=1,NOW(),NULL))');
        $status = $submit_and_finalize ? 'submitted' : 'in_progress';
        $submitted_flag = $submit_and_finalize ? 1 : 0;
        $stmt->bind_param('iiisssssssssii', $appointment_id, $doctor_id, $patient_id, $json_recommended, $json_treatment, $json_materials, $json_odontogram, $json_images, $doctor_notes, $follow_up_date, $status, $json_profiling, $doctor_id, $submitted_flag);
        $stmt->execute();
        $stmt->close();
    }

    // If patient-level profiling provided and patient has no profiling yet, save it to patients (silent auto-lock)
    if ($profiling_json !== null && !$patient_has_profiling) {
        $up = $conn->prepare('UPDATE patients SET profiling = ?, profiling_locked = 1 WHERE patient_id = ?');
        if ($up) {
            $up->bind_param('si', $profiling_json, $patient_id);
            $up->execute();
            $up->close();
        }
    }

    // If there are uploaded images, set the primary tooth_image to the first uploaded path
    if (!empty($uploaded_images)) {
        $first = $uploaded_images[0];
        $ust_img = $conn->prepare('UPDATE clinical_records SET tooth_image = ? WHERE appointment_id = ?');
        if ($ust_img) {
            $ust_img->bind_param('si', $first, $appointment_id);
            $ust_img->execute();
            $ust_img->close();
        }
    }

    // Keep appointment-level service links in selected_services (appointments.service_id is removed).
    $existingServiceIds = [];
    $findExistingServices = $conn->prepare('SELECT service_id FROM selected_services WHERE appointment_id = ?');
    if ($findExistingServices) {
        $findExistingServices->bind_param('i', $appointment_id);
        $findExistingServices->execute();
        $existingServiceRes = $findExistingServices->get_result();
        while ($existingServiceRes && ($existingServiceRow = $existingServiceRes->fetch_assoc())) {
            $sid = (int)($existingServiceRow['service_id'] ?? 0);
            if ($sid > 0) {
                $existingServiceIds[$sid] = $sid;
            }
        }
        $findExistingServices->close();
    }

    // Keep the originally selected appointment services in addition to any clinical recommendations.
    // This ensures base checkup/service pricing is always part of billing.
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

    // If finalized/submitted, update appointment status and queue, and create billing
    if ($submit_and_finalize) {
        $status = 'completed';
        $ust = $conn->prepare("UPDATE appointments SET status = ?, notes = CONCAT(IFNULL(notes,''), ?) WHERE appointment_id = ?");
        $note_append = "\n[Clinical record submitted by " . $doctorDisplayName . "]";
        $ust->bind_param('ssi', $status, $note_append, $appointment_id);
        $ust->execute();
        $ust->close();

        // Calculate billing: sum of services + materials total + optional doctor fee
        $total = 0.0;
        // Services from recommended services + structured treatment plan service references.
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
        // Materials
        if ($materials_used && is_array($materials_used)) {
            foreach ($materials_used as $m) {
                $qty = isset($m['qty']) ? (float)$m['qty'] : 0;
                $price = isset($m['price']) ? (float)$m['price'] : 0;
                $total += $qty * $price;
            }
        }
        // Doctor fee (optional POST param)
        $doctor_fee = isset($_POST['doctor_fee']) ? (float)$_POST['doctor_fee'] : 0.0;
        if ($doctor_fee > 0) $total += $doctor_fee;

        // Insert or update billing record. If a billing exists for this appointment, update it instead of inserting.
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
            // fallback: try to insert
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
        // Do not prompt suggested scheduling after profiling submission
        'needs_scheduling' => false
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    // Log error for debugging
    $logDir = __DIR__ . '/../../../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/save_clinical_record_error.log';
    $logEntry = date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);

    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
