<?php
/**
 * Create Secretary Record API
 * Submits clinical record, auto-generates billing, optional installment plan.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/idempotency_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/followup_helpers.php';

header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'secretary') {
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
$patientId = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
$visitClinicalRaw = $_POST['visit_clinical'] ?? ($_POST['profiling'] ?? '[]');
$visitClinicalData = json_decode($visitClinicalRaw, true);
$odontogramData = isset($_POST['odontogram']) ? json_decode($_POST['odontogram'], true) : [];
$billingItems = isset($_POST['billing_items']) ? json_decode($_POST['billing_items'], true) : [];
$prescriptions = isset($_POST['prescriptions']) ? json_decode($_POST['prescriptions'], true) : [];
$summary = isset($_POST['summary']) ? trim($_POST['summary']) : '';
$followUpDate = isset($_POST['follow_up_date']) ? trim($_POST['follow_up_date']) : '';
$followUpIntervalMonths = isset($_POST['follow_up_interval_months']) ? (int)$_POST['follow_up_interval_months'] : 0;
$followUpTotalVisits = isset($_POST['follow_up_total_visits']) ? (int)$_POST['follow_up_total_visits'] : 0;
$useInstallment = isset($_POST['use_installment']) ? (int)$_POST['use_installment'] : 0;
$installmentTerms = isset($_POST['installment_terms']) ? (int)$_POST['installment_terms'] : 0;
$discountType = billing_normalize_discount_type($_POST['discount_type'] ?? 'none');
$discountPercent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0.0;
$discountNotes = isset($_POST['discount_notes']) ? trim($_POST['discount_notes']) : '';
$secretaryId = (int)($_SESSION['user_id'] ?? 0);
$idempotencyKey = $_POST['idempotency_key'] ?? '';

if (!$appointmentId || !$patientId) {
    echo json_encode(['success' => false, 'message' => 'Appointment and patient ID required']);
    exit;
}

if (!is_array($visitClinicalData)) {
    $visitClinicalData = [];
}
$visitClinicalData = filter_visit_clinical_data($visitClinicalData);
if (!is_array($odontogramData)) {
    $odontogramData = [];
}
$odontogramData = odontogram_normalize_data($odontogramData);
if (!is_array($billingItems)) {
    $billingItems = [];
}
if (!is_array($prescriptions)) {
    $prescriptions = [];
}

billing_ensure_tables($conn);

$idempotencyScope = 'secretary.create_record.' . $appointmentId;
$cached = idempotency_handle_request($idempotencyScope, $idempotencyKey);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

try {
    $stmt = $conn->prepare('
        SELECT appointment_id, status, user_id AS doctor_id, patient_id
        FROM appointments
        WHERE appointment_id = ? AND patient_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new Exception('Failed to load appointment: ' . $conn->error);
    }
    $stmt->bind_param('ii', $appointmentId, $patientId);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        idempotency_clear_processing($idempotencyScope, $idempotencyKey);
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }

    if ($appointment['status'] !== 'awaiting_secretary_records') {
        idempotency_clear_processing($idempotencyScope, $idempotencyKey);
        $response = [
            'success' => false,
            'message' => 'Appointment not in awaiting_secretary_records status',
            'code' => 'already_processed',
        ];
        if (in_array($appointment['status'], ['completed', 'records_complete'], true)) {
            $response['success'] = true;
            $response['message'] = 'Clinical record was already submitted for this appointment.';
            $response['already_processed'] = true;
            idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
        }
        echo json_encode($response);
        exit;
    }

    $doctorId = (int)($appointment['doctor_id'] ?? 0);

    $doctorNotes = '';
    $noteStmt = $conn->prepare('SELECT note_text FROM appointment_doctor_notes WHERE appointment_id = ? ORDER BY created_at DESC LIMIT 1');
    if ($noteStmt) {
        $noteStmt->bind_param('i', $appointmentId);
        $noteStmt->execute();
        $noteRow = $noteStmt->get_result()->fetch_assoc();
        $noteStmt->close();
        $doctorNotes = trim((string)($noteRow['note_text'] ?? ''));
    }

    $treatmentPlan = null;
    if ($followUpIntervalMonths > 0 || $followUpTotalVisits > 0) {
        $treatmentPlan = json_encode([
            'follow_up_interval_months' => $followUpIntervalMonths,
            'follow_up_total_visits' => $followUpTotalVisits,
        ]);
    }

    $followUpDateDb = null;
    if ($followUpDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $followUpDate)) {
        $followUpDateDb = $followUpDate;
    }

    $conn->begin_transaction();

    ensure_clinical_records_profiling_column($conn);
    $visitClinicalJson = json_encode($visitClinicalData, JSON_UNESCAPED_UNICODE);

    $submittedAt = date('Y-m-d H:i:s');
    $recordStatus = 'finalized';

    $existingStmt = $conn->prepare('SELECT record_id, odontogram FROM clinical_records WHERE appointment_id = ? LIMIT 1');
    $existingStmt->bind_param('i', $appointmentId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if (empty($odontogramData) && $existing && !empty($existing['odontogram'])) {
        $decodedExisting = json_decode($existing['odontogram'], true);
        if (is_array($decodedExisting)) {
            $odontogramData = odontogram_normalize_data($decodedExisting);
        }
    }
    $odontogramJson = json_encode($odontogramData);

    if ($existing) {
        $clinicalRecordId = (int)$existing['record_id'];
        $upd = $conn->prepare('
            UPDATE clinical_records
            SET doctor_id = ?, patient_id = ?, summary = ?, odontogram = ?, doctor_notes = ?,
                treatment_plan = ?, follow_up_date = ?, status = ?, submitted_by = ?, submitted_at = ?,
                profiling = ?
            WHERE record_id = ?
        ');
        $upd->bind_param(
            'iissssssissi',
            $doctorId,
            $patientId,
            $summary,
            $odontogramJson,
            $doctorNotes,
            $treatmentPlan,
            $followUpDateDb,
            $recordStatus,
            $secretaryId,
            $submittedAt,
            $visitClinicalJson,
            $clinicalRecordId
        );
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare('
            INSERT INTO clinical_records
            (appointment_id, doctor_id, patient_id, summary, odontogram, doctor_notes, treatment_plan, follow_up_date, status, submitted_by, submitted_at, profiling)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ins->bind_param(
            'iiissssssiss',
            $appointmentId,
            $doctorId,
            $patientId,
            $summary,
            $odontogramJson,
            $doctorNotes,
            $treatmentPlan,
            $followUpDateDb,
            $recordStatus,
            $secretaryId,
            $submittedAt,
            $visitClinicalJson
        );
        $ins->execute();
        $clinicalRecordId = (int)$conn->insert_id;
        $ins->close();
    }

    $delRx = $conn->prepare('DELETE FROM prescriptions WHERE appointment_id = ?');
    if ($delRx) {
        $delRx->bind_param('i', $appointmentId);
        $delRx->execute();
        $delRx->close();
    }

    if (!empty($prescriptions)) {
        $rxIns = $conn->prepare('
            INSERT INTO prescriptions (appointment_id, prescription_date, medicine_name, dosage, medicine_brand, duration, notes)
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?)
        ');
        if ($rxIns) {
            foreach ($prescriptions as $rx) {
                $med = trim((string)($rx['medicine_name'] ?? ''));
                if ($med === '') {
                    continue;
                }
                $dosage = trim((string)($rx['dosage'] ?? ''));
                $brand = trim((string)($rx['medicine_brand'] ?? ''));
                $duration = trim((string)($rx['duration'] ?? ''));
                $notes = trim((string)($rx['notes'] ?? ''));
                $rxIns->bind_param('isssss', $appointmentId, $med, $dosage, $brand, $duration, $notes);
                $rxIns->execute();
            }
            $rxIns->close();
        }
    }

    $lineItems = [];
    $subtotal = 0.0;

    if (!empty($billingItems)) {
        foreach ($billingItems as $item) {
            $serviceId = (int)($item['service_id'] ?? 0);
            if ($serviceId > 0) {
                $svcStmt = $conn->prepare('
                    SELECT service_name, initial_price
                    FROM services
                    WHERE service_id = ? AND is_active = 1
                    LIMIT 1
                ');
                if (!$svcStmt) {
                    continue;
                }
                $svcStmt->bind_param('i', $serviceId);
                $svcStmt->execute();
                $svcRow = $svcStmt->get_result()->fetch_assoc();
                $svcStmt->close();
                if (!$svcRow) {
                    continue;
                }
                $amt = round((float)($svcRow['initial_price'] ?? 0), 2);
                $desc = trim((string)($svcRow['service_name'] ?? ''));
                if ($desc === '' || $amt <= 0) {
                    continue;
                }
                $subtotal += $amt;
                $lineItems[] = [
                    'service_id' => $serviceId,
                    'description' => $desc,
                    'amount' => $amt,
                    'item_type' => 'service',
                ];
                continue;
            }

            $desc = trim((string)($item['description'] ?? ''));
            $amt = round((float)($item['amount'] ?? 0), 2);
            if ($desc === '' && $amt <= 0) {
                continue;
            }
            if ($desc === '') {
                $desc = 'Additional charge';
            }
            $subtotal += $amt;
            $lineItems[] = [
                'service_id' => null,
                'description' => $desc,
                'amount' => $amt,
                'item_type' => 'extra',
            ];
        }
    }

    if (empty($lineItems)) {
        [, $serviceItems] = billing_sum_appointment_services($conn, $appointmentId);
        foreach ($serviceItems as $svc) {
            $subtotal += (float)$svc['amount'];
            $lineItems[] = $svc;
        }
    }

    $subtotal = round($subtotal, 2);
    if ($discountType === 'custom' && $discountNotes === '') {
        $discountNotes = 'Doctor-approved custom discount';
    }
    if ($discountType !== 'custom') {
        $discountNotes = '';
    }

    $discountTotals = billing_apply_discount($subtotal, $discountType, $discountPercent);
    $grandTotal = $discountTotals['total_amount'];

    $billingId = billing_create_or_update($conn, $appointmentId, $patientId, $subtotal, 'unpaid', [
        'discount_type' => $discountType,
        'discount_percent' => $discountType === 'custom' ? $discountPercent : null,
        'discount_notes' => $discountNotes,
    ]);
    if ($billingId <= 0) {
        throw new Exception('Failed to create billing record');
    }

    billing_save_line_items($conn, $appointmentId, $billingId, $lineItems);

    if ($useInstallment === 1 && $installmentTerms > 0) {
        billing_create_installment_plan($conn, $billingId, $grandTotal, $installmentTerms, 50.0);
    }

    billing_create_invoice($conn, $billingId, $secretaryId);

    $followUpAppointmentId = null;
    if ($followUpDateDb !== null) {
        $followUpAppointmentId = workflow_create_followup_appointment(
            $conn,
            $appointmentId,
            $patientId,
            $doctorId,
            $followUpDateDb
        );
    }

    $recordsCompletedAt = date('Y-m-d H:i:s');
    $completedStatus = 'completed';
    $apptUpd = $conn->prepare('
        UPDATE appointments
        SET status = ?, secretary_records_completed_at = ?
        WHERE appointment_id = ?
    ');
    $apptUpd->bind_param('ssi', $completedStatus, $recordsCompletedAt, $appointmentId);
    $apptUpd->execute();
    $apptUpd->close();

    $schedComplete = $conn->prepare("UPDATE patient_schedules SET status = 'completed' WHERE appointment_id = ? AND status IN ('scheduled','rescheduled')");
    if ($schedComplete) {
        $schedComplete->bind_param('i', $appointmentId);
        $schedComplete->execute();
        $schedComplete->close();
    }

    $action = 'appointment.secretary_records_created';
    $details = 'Secretary submitted clinical record. Record ID: ' . $clinicalRecordId . ', Billing ID: ' . $billingId . ', Total: ' . $grandTotal;
    $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $logStmt = $conn->prepare('
        INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
        VALUES (?, ?, \'appointments\', ?, ?, ?, ?, NOW())
    ');
    $logStmt->bind_param('isisss', $secretaryId, $action, $appointmentId, $details, $ipAddr, $userAgent);
    $logStmt->execute();
    $logStmt->close();

    $conn->commit();

    wf_notify_record_submitted($conn, $appointmentId, $patientId, $doctorId, $billingId, $grandTotal);

    $response = [
        'success' => true,
        'message' => 'Clinical record submitted and billing generated',
        'clinical_record_id' => $clinicalRecordId,
        'billing_id' => $billingId,
        'subtotal_amount' => $subtotal,
        'discount_type' => $discountTotals['discount_type'],
        'discount_percent' => $discountTotals['discount_percent'],
        'discount_amount' => $discountTotals['discount_amount'],
        'total_amount' => $grandTotal,
        'installment' => ($useInstallment === 1 && $installmentTerms > 0),
        'follow_up_appointment_id' => $followUpAppointmentId,
    ];
    idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
    echo json_encode($response);
} catch (Exception $e) {
    $conn->rollback();
    idempotency_clear_processing($idempotencyScope, $idempotencyKey);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
