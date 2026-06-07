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
set_error_handler(function($severity, $message, $file, $line) {
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
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
$odontogram = isset($_POST['odontogram']) && $_POST['odontogram'] !== '' ? $_POST['odontogram'] : null;
if ($odontogram !== null) {
    $decodedOdonto = json_decode($odontogram, true);
    if (is_array($decodedOdonto)) {
        $odontogram = json_encode(odontogram_normalize_data($decodedOdonto), JSON_UNESCAPED_UNICODE);
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
        SELECT a.appointment_id, a.status, a.user_id, a.patient_id 
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
        $stmt = $conn->prepare('UPDATE clinical_records SET doctor_id = ?, patient_id = ?, odontogram = IFNULL(?, odontogram), doctor_notes = IFNULL(?, doctor_notes), status = ?, submitted_by = ?, submitted_at = NOW() WHERE appointment_id = ?');
        $stmt->bind_param('iisssii', $doctorId, $patientId, $odontogram, $notes, $crStatus, $doctorId, $appointmentId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, odontogram, doctor_notes, status, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('iiisssi', $appointmentId, $doctorId, $patientId, $odontogram, $notes, $crStatus, $doctorId);
        $stmt->execute();
        $stmt->close();
    }
    
    // 2. Handle file uploads (photos)
    $photoCount = 0;
    if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $photoCount = count($_FILES['photos']['name']);
        
        for ($i = 0; $i < $photoCount; $i++) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            
            if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload error: ' . $_FILES['photos']['error'][$i]);
            }
            
            $fileSize = $_FILES['photos']['size'][$i];
            if ($fileSize > 5 * 1024 * 1024) {  // 5MB limit
                throw new Exception('File size exceeds 5MB limit');
            }
            
            $tmpPath = $_FILES['photos']['tmp_name'][$i];
            $mimeType = function_exists('mime_content_type')
                ? (mime_content_type($tmpPath) ?: '')
                : '';
            if ($mimeType === '' && !empty($_FILES['photos']['type'][$i])) {
                $mimeType = (string)$_FILES['photos']['type'][$i];
            }
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mimeType, $allowedMimes, true)) {
                $extGuess = strtolower(pathinfo((string)$_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
                $extToMime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                $mimeType = $extToMime[$extGuess] ?? $mimeType;
            }
            if (!in_array($mimeType, $allowedMimes, true)) {
                throw new Exception('Invalid file type: ' . ($mimeType !== '' ? $mimeType : 'unknown'));
            }

            $originalName = basename($_FILES['photos']['name'][$i]);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $uniqueName = 'appt_' . $appointmentId . '_photo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filePath = $uploadDir . $uniqueName;

            $saved = false;
            $gdAvailable = function_exists('imagecreatefromjpeg')
                && function_exists('imagecreatetruecolor')
                && function_exists('imagecopyresampled');
            if ($gdAvailable) {
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
            }
            if (!$saved && !move_uploaded_file($tmpPath, $filePath)) {
                throw new Exception('Failed to save file: ' . $originalName);
            }
            
            // Insert into appointment_media table
            $dbPath = 'assets/uploads/appointment_media/' . $uniqueName;
            $mediaOrder = $i + 1;
            $stmt = $conn->prepare('
                INSERT INTO appointment_media 
                (appointment_id, file_name, file_path, file_type, file_size, media_order, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, \'photo\', ?, ?, ?, NOW())
            ');
            $stmt->bind_param('issiii', $appointmentId, $originalName, $dbPath, $fileSize, $mediaOrder, $doctorId);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // 3. Update appointment status to awaiting_secretary_records
    $completedByDoctorAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('
        UPDATE appointments 
        SET status = \'awaiting_secretary_records\', completed_by_doctor_at = ?
        WHERE appointment_id = ?
    ');
    $stmt->bind_param('si', $completedByDoctorAt, $appointmentId);
    $stmt->execute();
    $stmt->close();
    
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
