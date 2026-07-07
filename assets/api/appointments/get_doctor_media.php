<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';

// Discard any warnings from includes, then start fresh buffer
if (ob_get_level()) ob_end_clean();

function api_respond(array $data, int $code = 200): never {
    http_response_code($code);
    if (ob_get_level()) ob_clean();
    echo json_encode($data);
    exit;
}

header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'secretary') {
    api_respond(['success' => false, 'message' => 'Unauthorized'], 403);
}

$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

if (!$appointmentId) {
    api_respond(['success' => false, 'message' => 'Appointment ID required']);
}

billing_ensure_tables($conn);

try {
    $stmt = $conn->prepare('SELECT a.appointment_id, a.status, p.patient_id, p.first_name, p.last_name, p.date_of_birth, p.phone_number, p.email FROM appointments a LEFT JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?');
    if (!$stmt) throw new Exception('Failed to load appointment: ' . $conn->error);
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        api_respond(['success' => false, 'message' => 'Appointment not found']);
    }

    $stmt = $conn->prepare('SELECT media_id, file_name, file_path, file_type, tooth_number, notes, uploaded_at FROM appointment_media WHERE appointment_id = ? ORDER BY media_order ASC');
    if (!$stmt) throw new Exception('Failed to load appointment media: ' . $conn->error);
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $photos = [];
    $mediaResult = $stmt->get_result();
    while ($row = $mediaResult->fetch_assoc()) $photos[] = $row;
    $stmt->close();

    $stmt = $conn->prepare('SELECT note_id, note_text, created_at FROM appointment_doctor_notes WHERE appointment_id = ? ORDER BY created_at DESC');
    if (!$stmt) throw new Exception('Failed to load doctor notes: ' . $conn->error);
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $notes = [];
    $notesResult = $stmt->get_result();
    while ($row = $notesResult->fetch_assoc()) $notes[] = $row;
    $stmt->close();

    $odontogram = null;
    $followUpDate = null;
    $summary = '';
    $treatmentPlan = null;
    $visitClinical = [];

    $crStmt = $conn->prepare('SELECT record_id, follow_up_date, summary FROM clinical_records WHERE appointment_id = ? ORDER BY record_id DESC LIMIT 1');
    if ($crStmt) {
        $crStmt->bind_param('i', $appointmentId);
        $crStmt->execute();
        $crRow = $crStmt->get_result()->fetch_assoc();
        $crStmt->close();

        if ($crRow) {
            $recId = (int)$crRow['record_id'];
            $followUpDate = $crRow['follow_up_date'] ?? null;
            $summary = (string)($crRow['summary'] ?? '');

            $toothStmt = $conn->prepare('SELECT ts.tooth_selected, ts.status_type_id, ts.tooth_surface, ts.status, tst.label, tst.code FROM tooth_status ts LEFT JOIN tooth_status_type tst ON tst.status_type_id = ts.status_type_id WHERE ts.record_id = ?');
            $toothStmt->bind_param('i', $recId);
            $toothStmt->execute();
            $toothRes = $toothStmt->get_result();
            $rawToothRows = [];
            while ($trow = $toothRes->fetch_assoc()) $rawToothRows[] = $trow;
            $toothStmt->close();
            if (!empty($rawToothRows)) {
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
                if (!empty($odontogramFormatted)) $odontogram = $odontogramFormatted;
            }

            $tpStmt = $conn->prepare('SELECT * FROM treatment_plans WHERE record_id = ?');
            $tpStmt->bind_param('i', $recId);
            $tpStmt->execute();
            $tpRes = $tpStmt->get_result();
            $tpItems = [];
            while ($tpRow = $tpRes->fetch_assoc()) $tpItems[] = $tpRow;
            $tpStmt->close();
            $treatmentPlan = empty($tpItems) ? null : $tpItems;

            $pfStmt = $conn->prepare('SELECT * FROM patient_profiling WHERE patient_id = ? ORDER BY profiling_id DESC LIMIT 1');
            if ($pfStmt) {
                $pfStmt->bind_param('i', $appointment['patient_id']);
                $pfStmt->execute();
                $pfRes = $pfStmt->get_result();
                $pfRow = $pfRes ? $pfRes->fetch_assoc() : null;
                $pfStmt->close();
                if ($pfRow) $visitClinical = $pfRow;
            }
        }
    }

    $billingItems = [];
    $idCol = isset(billing_line_items_columns($conn)['item_id']) ? 'item_id' : 'line_id';
    $hasItemType = isset(billing_line_items_columns($conn)['item_type']);
    $selectCols = $idCol . ', service_id, description, amount' . ($hasItemType ? ', item_type' : '');
    $stmt = $conn->prepare("SELECT $selectCols FROM billing_line_items WHERE appointment_id = ? ORDER BY $idCol ASC");
    if ($stmt) {
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $billingResult = $stmt->get_result();
        while ($row = $billingResult->fetch_assoc()) {
            if (!$hasItemType) $row['item_type'] = 'extra';
            $billingItems[] = $row;
        }
        $stmt->close();
    }

    $selectedServices = [];
    [, $serviceItems] = billing_sum_appointment_services($conn, $appointmentId);
    foreach ($serviceItems as $svc) $selectedServices[] = $svc;

    $extraCharges = [];
    foreach ($billingItems as $row) {
        $type = (string)($row['item_type'] ?? 'extra');
        $sid = (int)($row['service_id'] ?? 0);
        if ($type === 'extra' || $sid <= 0) {
            $extraCharges[] = ['description' => (string)($row['description'] ?? ''), 'amount' => (float)($row['amount'] ?? 0), 'item_type' => 'extra'];
        }
    }

    $availableServices = [];
    $svcRes = $conn->query('SELECT service_id, service_name, initial_price FROM services WHERE is_active = 1 ORDER BY service_name ASC');
    if ($svcRes) {
        while ($svc = $svcRes->fetch_assoc()) {
            $availableServices[] = ['service_id' => (int)$svc['service_id'], 'description' => (string)$svc['service_name'], 'amount' => (float)($svc['initial_price'] ?? 0), 'item_type' => 'service'];
        }
    }

    $prescriptions = [];
    $rxStmt = $conn->prepare('SELECT medicine_name, dosage, medicine_brand, duration, notes FROM prescriptions WHERE appointment_id = ?');
    if ($rxStmt) {
        $rxStmt->bind_param('i', $appointmentId);
        $rxStmt->execute();
        $rxRes = $rxStmt->get_result();
        while ($rx = $rxRes->fetch_assoc()) $prescriptions[] = $rx;
        $rxStmt->close();
    }

    $patientBackground = [];
    $bgStmt = $conn->prepare('SELECT * FROM patient_profiling WHERE patient_id = ? ORDER BY profiling_id DESC LIMIT 1');
    if ($bgStmt) {
        $bgStmt->bind_param('i', $appointment['patient_id']);
        $bgStmt->execute();
        $bgRes = $bgStmt->get_result();
        $bgRow = $bgRes ? $bgRes->fetch_assoc() : null;
        $bgStmt->close();
        if ($bgRow) $patientBackground = $bgRow;
    }

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'appointment' => [
            'appointment_id' => $appointment['appointment_id'],
            'status' => $appointment['status'],
            'patient_id' => $appointment['patient_id'],
            'patient_name' => trim($appointment['first_name'] . ' ' . $appointment['last_name']),
            'date_of_birth' => $appointment['date_of_birth'],
            'phone_number' => $appointment['phone_number'],
            'email' => $appointment['email'],
        ],
        'photos' => $photos,
        'notes' => $notes,
        'odontogram' => $odontogram,
        'billing_items' => $billingItems,
        'selected_services' => $selectedServices,
        'extra_charges' => $extraCharges,
        'available_services' => $availableServices,
        'existing_visit_clinical' => $visitClinical,
        'existing_profiling' => $visitClinical,
        'patient_background' => $patientBackground,
        'summary' => $summary,
        'follow_up_date' => $followUpDate,
        'treatment_plan' => $treatmentPlan,
        'prescriptions' => $prescriptions,
    ]);
} catch (Exception $e) {
    error_log('get_doctor_media error: ' . $e->getMessage());
    api_respond(['success' => false, 'message' => 'Unable to load appointment data.'], 500);
}
