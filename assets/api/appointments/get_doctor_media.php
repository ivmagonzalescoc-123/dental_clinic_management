<?php
/**
 * Get Appointment Doctor Media API
 * Retrieves photos, notes, odontogram, services and charges for secretary record form.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';

header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'secretary') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'Appointment ID required']);
    exit;
}

billing_ensure_tables($conn);

try {
    $stmt = $conn->prepare('
        SELECT a.appointment_id, a.status, p.patient_id, p.first_name, p.last_name,
               p.date_of_birth, p.phone_number, p.email, p.profiling
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ?
    ');
    if (!$stmt) {
        throw new Exception('Failed to load appointment: ' . $conn->error);
    }
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }

    $stmt = $conn->prepare('
        SELECT media_id, file_name, file_path, file_type, tooth_number, notes, uploaded_at
        FROM appointment_media
        WHERE appointment_id = ?
        ORDER BY media_order ASC
    ');
    if (!$stmt) {
        throw new Exception('Failed to load appointment media: ' . $conn->error);
    }
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $photos = [];
    $mediaResult = $stmt->get_result();
    while ($row = $mediaResult->fetch_assoc()) {
        $photos[] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare('
        SELECT note_id, note_text, created_at
        FROM appointment_doctor_notes
        WHERE appointment_id = ?
        ORDER BY created_at DESC
    ');
    if (!$stmt) {
        throw new Exception('Failed to load doctor notes: ' . $conn->error);
    }
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $notes = [];
    $notesResult = $stmt->get_result();
    while ($row = $notesResult->fetch_assoc()) {
        $notes[] = $row;
    }
    $stmt->close();

    ensure_clinical_records_profiling_column($conn);

    $odontogram = null;
    $followUpDate = null;
    $summary = '';
    $treatmentPlan = null;
    $visitClinical = [];
    $stmt = $conn->prepare('
        SELECT odontogram, follow_up_date, summary, treatment_plan, profiling
        FROM clinical_records
        WHERE appointment_id = ?
        ORDER BY record_id DESC LIMIT 1
    ');
    if (!$stmt) {
        throw new Exception('Failed to load clinical record: ' . $conn->error);
    }
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $crRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($crRow) {
        if (!empty($crRow['odontogram'])) {
            $odontogram = json_decode($crRow['odontogram'], true);
        }
        $followUpDate = $crRow['follow_up_date'] ?? null;
        $summary = (string)($crRow['summary'] ?? '');
        if (!empty($crRow['treatment_plan'])) {
            $treatmentPlan = json_decode($crRow['treatment_plan'], true);
        }
        if (!empty($crRow['profiling'])) {
            $decodedVisit = json_decode($crRow['profiling'], true);
            if (is_array($decodedVisit)) {
                $visitClinical = filter_visit_clinical_data($decodedVisit);
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
            if (!$hasItemType) {
                $row['item_type'] = 'extra';
            }
            $billingItems[] = $row;
        }
        $stmt->close();
    }

    $selectedServices = [];
    [, $serviceItems] = billing_sum_appointment_services($conn, $appointmentId);
    foreach ($serviceItems as $svc) {
        $selectedServices[] = $svc;
    }

    $extraCharges = [];
    foreach ($billingItems as $row) {
        $type = (string)($row['item_type'] ?? 'extra');
        $sid = (int)($row['service_id'] ?? 0);
        if ($type === 'extra' || $sid <= 0) {
            $extraCharges[] = [
                'description' => (string)($row['description'] ?? ''),
                'amount' => (float)($row['amount'] ?? 0),
                'item_type' => 'extra',
            ];
        }
    }

    $availableServices = [];
    $svcRes = $conn->query('
        SELECT service_id, service_name, initial_price
        FROM services
        WHERE is_active = 1
        ORDER BY service_name ASC
    ');
    if ($svcRes) {
        while ($svc = $svcRes->fetch_assoc()) {
            $availableServices[] = [
                'service_id' => (int)$svc['service_id'],
                'description' => (string)$svc['service_name'],
                'amount' => (float)($svc['initial_price'] ?? 0),
                'item_type' => 'service',
            ];
        }
    }

    $prescriptions = [];
    $rxStmt = $conn->prepare('
        SELECT medicine_name, dosage, medicine_brand, duration, notes
        FROM prescriptions WHERE appointment_id = ?
    ');
    if ($rxStmt) {
        $rxStmt->bind_param('i', $appointmentId);
        $rxStmt->execute();
        $rxRes = $rxStmt->get_result();
        while ($rx = $rxRes->fetch_assoc()) {
            $prescriptions[] = $rx;
        }
        $rxStmt->close();
    }

    $patientBackground = [];
    if (!empty($appointment['profiling'])) {
        $decodedBackground = json_decode($appointment['profiling'], true);
        if (is_array($decodedBackground)) {
            $patientBackground = filter_background_profiling_data($decodedBackground);
        }
    }

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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
