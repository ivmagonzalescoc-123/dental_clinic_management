<?php
/**
 * Finish Appointment API
 * POST /api/appointments/finish_appointment.php
 * 
 * Handles doctor finishing an appointment:
 * 1. Uploads photos to appointment_media table
 * 2. Stores doctor notes in appointment_doctor_notes table  
 * 3. Updates appointment status to 'awaiting_secretary_records'
 */

// JSON-only responses: configure before any includes that might emit warnings
header('Content-Type: application/json');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/idempotency_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include_once __DIR__ . '/availability_guard.php';

// Discard any PHP warnings buffered during includes
if (ob_get_level()) ob_end_clean();

set_error_handler(function($severity, $message, $file, $line) {
    // Respect @-suppressed errors (e.g. @imagecreatefromjpeg()) instead of
    // escalating them to a fatal ErrorException that aborts the whole request.
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // convert warnings/notices to ErrorException so they get caught
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function($e) {
    // Log to Apache/PHP error log
    error_log("Unhandled exception in finish_appointment.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    // Also append detailed trace to a local project log for easier debugging
    try {
        $projectRoot = dirname(dirname(dirname(__DIR__)));
        $logDir = $projectRoot . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'finish_appointment_errors.log';
        $payload = "[" . date('c') . "] Unhandled exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        $payload .= $e->getTraceAsString() . "\n";
        $payload .= "SERVER: " . json_encode(array_intersect_key($_SERVER, array_flip(['REQUEST_URI','REMOTE_ADDR','HTTP_USER_AGENT','REQUEST_METHOD']))) . "\n";
        $payload .= "POST: " . json_encode($_POST) . "\n";
        $payload .= "FILES: " . json_encode(array_map(function($f){ return ['name'=>$f['name'] ?? null,'size'=>$f['size'] ?? null,'error'=>$f['error'] ?? null]; }, $_FILES ?? [])) . "\n\n";
        @file_put_contents($logFile, $payload, FILE_APPEND | LOCK_EX);
    } catch (Throwable $inner) {
        // ignore
    }

    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code(500);
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Shutdown fatal error in finish_appointment.php: " . print_r($err, true));
        if (!headers_sent()) header('Content-Type: application/json');
        http_response_code(500);
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
});

/**
 * Parse the PHP memory_limit ini setting into bytes.
 * Returns -1 for unlimited, 0 if it couldn't be determined.
 */
function appt_media_memory_limit_bytes() {
    $limit = ini_get('memory_limit');
    if ($limit === false || $limit === '') {
        return 0;
    }
    $limit = trim($limit);
    if ($limit === '-1') {
        return -1;
    }
    $unit = strtolower(substr($limit, -1));
    $value = (int)$limit;
    switch ($unit) {
        case 'g': $value *= 1024 * 1024 * 1024; break;
        case 'm': $value *= 1024 * 1024; break;
        case 'k': $value *= 1024; break;
    }
    return $value;
}

// Verify doctor role
if (($_SESSION['role'] ?? '') !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again..']);
    exit;
}

$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
// The doctor's clinical summary (optional). Null = not sent, so an older client or an
// untouched field never wipes a summary that is already on the record.
$summary = isset($_POST['summary']) ? trim((string)$_POST['summary']) : null;
$odontogramRaw = isset($_POST['odontogram']) && $_POST['odontogram'] !== '' ? $_POST['odontogram'] : null;
$odontogramData = null;
if ($odontogramRaw !== null) {
    $decodedOdonto = json_decode($odontogramRaw, true);
    if (is_array($decodedOdonto)) {
        $odontogramData = odontogram_normalize_data($decodedOdonto);
    }
}
$doctorId = $_SESSION['user_id'];
$idempotencyKey = $_POST['idempotency_key'] ?? '';

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'Appointment ID required']);
    exit;
}

$idempotencyScope = 'doctor.finish_appointment.' . $appointmentId;
$cached = idempotency_handle_request($idempotencyScope, $idempotencyKey);
if ($cached !== null) {
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($cached);
    exit;
}

try {
    // Verify appointment exists and belongs to doctor
    $stmt = $conn->prepare('
        SELECT a.appointment_id, a.status, a.user_id, a.patient_id, a.session_started_at
        FROM appointments a
        WHERE a.appointment_id = ?
        LIMIT 1
    ');
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $appt = $result->fetch_assoc();
    $stmt->close();
    
    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    // Verify doctor is assigned to this appointment
    if ($appt['user_id'] != $doctorId) {
        echo json_encode(['success' => false, 'message' => 'Not assigned to this appointment']);
        exit;
    }
    
    // Check appointment status is in_progress
    if ($appt['status'] !== 'in_progress') {
        idempotency_clear_processing($idempotencyScope, $idempotencyKey);
        $response = [
            'success' => false,
            'message' => 'Appointment must be in_progress to finish',
            'code' => 'already_processed',
        ];
        if (in_array($appt['status'], ['awaiting_secretary_records', 'completed', 'records_complete', 'completed_by_doctor'], true)) {
            $response['success'] = true;
            $response['message'] = 'Appointment was already finished.';
            $response['already_processed'] = true;
            idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
        }
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode($response);
        exit;
    }
    
    // Upload directory setup
    $uploadDir = dirname(dirname(__DIR__)) . '/uploads/appointment_media/';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    $conn->begin_transaction();
    
    // 1. Save doctor notes if provided
    if (!empty($notes)) {
        $stmt = $conn->prepare('
            INSERT INTO appointment_doctor_notes 
            (appointment_id, doctor_id, note_text, created_at)
            VALUES (?, ?, ?, NOW())
        ');
        $stmt->bind_param('iis', $appointmentId, $doctorId, $notes);
        $stmt->execute();
        $stmt->close();
    }

    // 3. Upsert clinical records for odontogram
    $patientId = $appt['patient_id'];
    $crStatus = 'submitted';
    
    $stmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $existingCr = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    
    if ($existingCr) {
        $stmt = $conn->prepare('UPDATE clinical_records SET doctor_id = ?, patient_id = ?, doctor_notes = IFNULL(?, doctor_notes), summary = IFNULL(?, summary), status = ?, submitted_by = ?, submitted_at = NOW() WHERE appointment_id = ?');
        $stmt->bind_param('iisssii', $doctorId, $patientId, $notes, $summary, $crStatus, $doctorId, $appointmentId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, doctor_notes, summary, status, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('iiisssi', $appointmentId, $doctorId, $patientId, $notes, $summary, $crStatus, $doctorId);
        $stmt->execute();
        $stmt->close();
    }

    $crCheck = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $crCheck->bind_param('i', $appointmentId);
    $crCheck->execute();
    $crRes = $crCheck->get_result();
    $crRow = $crRes ? $crRes->fetch_assoc() : null;
    $crCheck->close();
    $recordId = $crRow ? (int)$crRow['record_id'] : 0;

    if ($recordId > 0 && is_array($odontogramData) && !empty($odontogramData)) {
        odontogram_ensure_tooth_status_schema($conn);
        $conn->query("DELETE FROM tooth_status WHERE record_id = $recordId");

        $codeToTypeId = [];
        $codeRes = $conn->query('SELECT status_type_id, code FROM tooth_status_type');
        if ($codeRes) {
            while ($crow = $codeRes->fetch_assoc()) {
                $codeToTypeId[$crow['code']] = (int)$crow['status_type_id'];
            }
        }

        $tsStmt = $conn->prepare('INSERT INTO tooth_status (record_id, tooth_selected, status_type_id, tooth_surface, status, notes) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($odontogramData as $fdiKey => $toothData) {
            if (!is_array($toothData)) continue;
            $toothNum = (int)$fdiKey;
            if ($toothNum <= 0) continue;
            $surfaces = $toothData['surfaces'] ?? [];
            $comment = trim((string)($toothData['comment'] ?? ''));
            $commentOrNull = $comment !== '' ? $comment : null;
            if (empty($surfaces)) {
                if ($comment !== '') {
                    // bind_param() requires variables, not literals, for by-reference args.
                    // status_type_id must be NULL, not 0 — 0 doesn't exist in tooth_status_type
                    // and violates the fk_tooth_status_type foreign key.
                    $commentOnlyTypeId = null;
                    $commentOnlySurface = '';
                        $commentOnlyStatus = null; // the comment lives in notes; status is the chart code
                    $tsStmt->bind_param('iiisss', $recordId, $toothNum, $commentOnlyTypeId, $commentOnlySurface, $commentOnlyStatus, $commentOrNull);
                    $tsStmt->execute();
                }
                continue;
            }
            foreach ($surfaces as $surfaceName => $codes) {
                $codeList = is_array($codes) ? $codes : [$codes];
                foreach ($codeList as $code) {
                    $code = (string)$code;
                    $typeId = $codeToTypeId[$code] ?? null;
                    $tsStmt->bind_param('iiisss', $recordId, $toothNum, $typeId, $surfaceName, $code, $commentOrNull);
                    $tsStmt->execute();
                }
            }
        }
        $tsStmt->close();
    }
    
    // 2. Handle file uploads (photos) — resilient: one bad photo should not kill the whole transaction
    $photoCount = 0;
    $uploadErrors = [];
    if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $rawCount = count($_FILES['photos']['name']);
        for ($i = 0; $i < $rawCount; $i++) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                $uploadErrors[] = 'Upload error for file #' . ($i + 1) . ': code ' . $_FILES['photos']['error'][$i];
                continue;
            }

            $fileSize = $_FILES['photos']['size'][$i];
            if ($fileSize > 5 * 1024 * 1024) {
                $uploadErrors[] = basename($_FILES['photos']['name'][$i]) . ' exceeds 5MB limit';
                continue;
            }

            $tmpPath = $_FILES['photos']['tmp_name'][$i];
            $originalName = basename((string)$_FILES['photos']['name'][$i]);
            if (!is_uploaded_file($tmpPath)) {
                $uploadErrors[] = $originalName . ': invalid upload';
                continue;
            }

            // The file must parse as a real image, and the saved extension comes from the
            // detected image type — never from the uploaded filename or the browser's MIME
            // type, which a caller controls (e.g. a GIF-headed HTML file named photo.html).
            $allowedImageTypes = [
                IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
                IMAGETYPE_PNG => ['image/png', 'png'],
                IMAGETYPE_GIF => ['image/gif', 'gif'],
                IMAGETYPE_WEBP => ['image/webp', 'webp'],
            ];
            $imageInfo = @getimagesize($tmpPath);
            $imageType = $imageInfo ? (int)$imageInfo[2] : 0;
            if (!isset($allowedImageTypes[$imageType])) {
                $uploadErrors[] = $originalName . ': not a JPG, PNG, GIF, or WEBP image';
                continue;
            }
            [$mimeType, $ext] = $allowedImageTypes[$imageType];
            $uniqueName = 'appt_' . $appointmentId . '_photo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filePath = $uploadDir . $uniqueName;

            $saved = false;
            $gdAvailable = function_exists('imagecreatefromjpeg')
                && function_exists('imagecreatetruecolor')
                && function_exists('imagecopyresampled');

            // Skip GD entirely if decoding the image wouldn't fit in the
            // remaining memory budget (shared hosts like InfinityFree cap
            // memory_limit low and kill the whole request on exhaustion —
            // better to just store the original file than crash the request).
            if ($gdAvailable && $mimeType !== 'image/webp') {
                $dims = $imageInfo;
                if ($dims) {
                    $estimatedBytes = $dims[0] * $dims[1] * 5; // ~4 bytes/px truecolor + headroom
                    $limitBytes = appt_media_memory_limit_bytes();
                    $usedBytes = memory_get_usage(true);
                    if ($limitBytes > 0 && ($usedBytes + $estimatedBytes) > ($limitBytes * 0.7)) {
                        $gdAvailable = false; // too risky, fall back to raw copy below
                    }
                }
            }

            if ($gdAvailable && $mimeType !== 'image/webp') {
                // Any failure here (including a fatal memory error converted
                // by the shutdown handler, or a caught warning) must not take
                // down the whole finish-appointment transaction — fall back
                // to saving the untouched upload instead.
                try {
                    $image = null;
                    if ($mimeType === 'image/jpeg') {
                        $image = @imagecreatefromjpeg($tmpPath) ?: null;
                    } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
                        $image = @imagecreatefrompng($tmpPath) ?: null;
                    } elseif ($mimeType === 'image/gif' && function_exists('imagecreatefromgif')) {
                        $image = @imagecreatefromgif($tmpPath) ?: null;
                    }
                    if ($image) {
                        $width = imagesx($image);
                        $height = imagesy($image);
                        if ($width > 2000 || $height > 2000) {
                            $ratio = min(2000 / $width, 2000 / $height);
                            $newWidth = (int)($width * $ratio);
                            $newHeight = (int)($height * $ratio);
                            $resized = imagecreatetruecolor($newWidth, $newHeight);
                            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                            imagedestroy($image);
                            $image = $resized;
                        }
                        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagejpeg')) {
                            $saved = imagejpeg($image, $filePath, 85);
                        } elseif ($ext === 'png' && function_exists('imagepng')) {
                            $saved = imagepng($image, $filePath, 7);
                        }
                        imagedestroy($image);
                    }
                } catch (Throwable $gdError) {
                    error_log('finish_appointment.php: GD resize failed for ' . $originalName . ': ' . $gdError->getMessage());
                    $saved = false;
                }
            }
            if (!$saved) {
                $saved = @move_uploaded_file($tmpPath, $filePath);
            }
            if (!$saved) {
                $uploadErrors[] = $originalName . ': could not save to disk';
                continue;
            }

            // Insert into appointment_media table
            $dbPath = 'assets/uploads/appointment_media/' . $uniqueName;
            $mediaOrder = $photoCount + 1;
            $insStmt = $conn->prepare('
                INSERT INTO appointment_media
                (appointment_id, file_name, file_path, file_type, file_size, media_order, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, \'photo\', ?, ?, ?, NOW())
            ');
            if ($insStmt) {
                $insStmt->bind_param('issiii', $appointmentId, $originalName, $dbPath, $fileSize, $mediaOrder, $doctorId);
                $insStmt->execute();
                $insStmt->close();
                $photoCount++;
            } else {
                $uploadErrors[] = $originalName . ': database insert failed';
            }
        }
    }
    
    // 3. Save selected services
    $selectedServiceIds = $_POST['selected_service_ids'] ?? [];
    if (is_array($selectedServiceIds) && !empty($selectedServiceIds)) {
        // Clear existing selected_services for this appointment
        $delStmt = $conn->prepare('DELETE FROM selected_services WHERE appointment_id = ?');
        $delStmt->bind_param('i', $appointmentId);
        $delStmt->execute();
        $delStmt->close();

        // Insert new services
        $insStmt = $conn->prepare('INSERT INTO selected_services (appointment_id, service_id, notes) VALUES (?, ?, ?)');
        $note = 'Added by doctor during session';
        foreach ($selectedServiceIds as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                $insStmt->bind_param('iis', $appointmentId, $sid, $note);
                $insStmt->execute();
            }
        }
        $insStmt->close();
    }

    // 4. Update appointment status to awaiting_secretary_records
    $completedByDoctorAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('
        UPDATE appointments
        SET status = \'awaiting_secretary_records\', completed_by_doctor_at = ?
        WHERE appointment_id = ?
    ');
    $stmt->bind_param('si', $completedByDoctorAt, $appointmentId);
    $stmt->execute();
    $stmt->close();

    // 4b. Release any reserved timeslots beyond what the doctor actually used.
    $releasedSlots = 0;
    $sessionStartedAt = $appt['session_started_at'] ?? null;
    if (!empty($sessionStartedAt)) {
        $slotsStmt = $conn->prepare(
            "SELECT timeslot_id FROM timeslot WHERE appointment_id = ? ORDER BY start_time ASC"
        );
        $slotsStmt->bind_param('i', $appointmentId);
        $slotsStmt->execute();
        $reservedSlotIds = [];
        $slotsRes = $slotsStmt->get_result();
        while ($slotRow = $slotsRes->fetch_assoc()) {
            $reservedSlotIds[] = (int)$slotRow['timeslot_id'];
        }
        $slotsStmt->close();

        $reservedCount = count($reservedSlotIds);
        if ($reservedCount > 1) {
            $actualMinutes = (strtotime($completedByDoctorAt) - strtotime($sessionStartedAt)) / 60;
            $actualMinutes = max(0, $actualMinutes);
            $neededSlots = max(1, (int)ceil($actualMinutes / APPT_BASE_SLOT_MINUTES));

            if ($neededSlots < $reservedCount) {
                $slotsToFree = array_slice($reservedSlotIds, $neededSlots);
                $placeholders = implode(',', array_fill(0, count($slotsToFree), '?'));
                $types = str_repeat('i', count($slotsToFree));
                $freeStmt = $conn->prepare(
                    "UPDATE timeslot SET status = 'available', appointment_id = NULL WHERE timeslot_id IN ($placeholders) AND status = 'booked'"
                );
                $freeStmt->bind_param($types, ...$slotsToFree);
                $freeStmt->execute();
                $releasedSlots = $freeStmt->affected_rows;
                $freeStmt->close();

                if ($releasedSlots > 0 && function_exists('log_system_event')) {
                    log_system_event(
                        $conn,
                        'appointment.release_unused_slots',
                        'appointments',
                        $appointmentId,
                        "Released {$releasedSlots} unused slot(s) after early finish (used ~" . (int)ceil($actualMinutes) . "min of " . ($reservedCount * APPT_BASE_SLOT_MINUTES) . "min reserved)",
                        $doctorId
                    );
                }
            }
        }
    }

    // Log action
    $action = 'appointment.finish_by_doctor';
    $details = 'Doctor finished appointment. Notes: ' . (!empty($notes) ? 'Yes' : 'No') . '. Photos: ' . ($photoCount > 0 ? $photoCount : 'None');
    $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare('
        INSERT INTO system_logs 
        (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
        VALUES (?, ?, \'appointments\', ?, ?, ?, ?, NOW())
    ');
    $stmt->bind_param('isisss', $doctorId, $action, $appointmentId, $details, $ipAddr, $userAgent);
    $stmt->execute();
    $stmt->close();
    
    $conn->commit();

    wf_notify_doctor_finished($conn, $appointmentId, (int)$appt['patient_id']);
    
    $response = [
        'success' => true,
        'message' => 'Appointment finished successfully',
        'photos_uploaded' => $photoCount,
        'notes_saved' => !empty($notes),
    ];
    if (!empty($uploadErrors)) {
        $response['upload_warnings'] = $uploadErrors;
    }
    idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($response);
    
} catch (Exception $e) {
    $conn->rollback();
    idempotency_clear_processing($idempotencyScope, $idempotencyKey);
    http_response_code(500);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
