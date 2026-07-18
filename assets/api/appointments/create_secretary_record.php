<?php
/**
 * Create Secretary Record API
 * Submits clinical record, auto-generates billing, optional installment plan.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/idempotency_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/workflow_notifications.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/followup_helpers.php';

// Discard any PHP warnings/notices buffered during includes
if (ob_get_level()) ob_end_clean();

// Helper: flush buffered warnings then emit JSON
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    api_respond(['success' => false, 'message' => 'Invalid security token.'], 403);
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
$recordPayment = isset($_POST['record_payment']) ? (int)$_POST['record_payment'] : 0;
$paymentAmountApplied = isset($_POST['payment_amount_applied']) ? (float)$_POST['payment_amount_applied'] : 0.0;
$paymentAmountTendered = isset($_POST['payment_amount_tendered']) ? (float)$_POST['payment_amount_tendered'] : 0.0;
$paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
$paymentOrNumber = isset($_POST['payment_or_number']) ? trim($_POST['payment_or_number']) : '';
$paymentNotes = isset($_POST['payment_notes']) ? trim($_POST['payment_notes']) : '';
$secretaryId = (int)($_SESSION['user_id'] ?? 0);
$idempotencyKey = $_POST['idempotency_key'] ?? '';

if (!$appointmentId || !$patientId) {
    api_respond(['success' => false, 'message' => 'Appointment and patient ID required']);
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
    api_respond($cached);
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
        api_respond(['success' => false, 'message' => 'Appointment not found']);
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
        api_respond($response);
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

    $submittedAt = date('Y-m-d H:i:s');
    $recordStatus = 'finalized';

    $existingStmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ? LIMIT 1');
    $existingStmt->bind_param('i', $appointmentId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existing) {
        $clinicalRecordId = (int)$existing['record_id'];
        $upd = $conn->prepare('UPDATE clinical_records SET doctor_id = ?, patient_id = ?, summary = ?, doctor_notes = ?, follow_up_date = ?, status = ?, submitted_by = ?, submitted_at = ? WHERE record_id = ?');
        $upd->bind_param('iissssisi', $doctorId, $patientId, $summary, $doctorNotes, $followUpDateDb, $recordStatus, $secretaryId, $submittedAt, $clinicalRecordId);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, summary, doctor_notes, follow_up_date, status, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->bind_param('iiissssis', $appointmentId, $doctorId, $patientId, $summary, $doctorNotes, $followUpDateDb, $recordStatus, $secretaryId, $submittedAt);
        $ins->execute();
        $clinicalRecordId = (int)$conn->insert_id;
        $ins->close();
    }

    if ($clinicalRecordId > 0) {
        if (!empty($odontogramData)) {
            $conn->query("DELETE FROM tooth_status WHERE record_id = $clinicalRecordId");

            $codeToTypeId = [];
            $codeRes = $conn->query('SELECT status_type_id, code FROM tooth_status_type');
            if ($codeRes) {
                while ($crow = $codeRes->fetch_assoc()) {
                    $codeToTypeId[$crow['code']] = (int)$crow['status_type_id'];
                }
            }

            $tsStmt = $conn->prepare('INSERT INTO tooth_status (record_id, tooth_selected, status_type_id, tooth_surface, status, notes) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($odontogramData as $fdiKey => $td) {
                if (!is_array($td)) continue;
                $toothNum = (int)$fdiKey;
                if ($toothNum <= 0) continue;
                $surfaces = $td['surfaces'] ?? [];
                $comment = trim((string)($td['comment'] ?? ''));
                $commentOrNull = $comment !== '' ? $comment : null;
                if (empty($surfaces)) {
                    if ($comment !== '') {
                        // bind_param() requires variables, not literals, for by-reference args.
                        // status_type_id must be NULL, not 0 — 0 doesn't exist in tooth_status_type
                        // and violates the fk_tooth_status_type foreign key.
                        $commentOnlyTypeId = null;
                        $commentOnlySurface = '';
                        $tsStmt->bind_param('iiisss', $clinicalRecordId, $toothNum, $commentOnlyTypeId, $commentOnlySurface, $comment, $commentOrNull);
                        $tsStmt->execute();
                    }
                    continue;
                }
                foreach ($surfaces as $surfaceName => $codes) {
                    $codeList = is_array($codes) ? $codes : [$codes];
                    foreach ($codeList as $code) {
                        $code = (string)$code;
                        $typeId = $codeToTypeId[$code] ?? 0;
                        $tsStmt->bind_param('iiisss', $clinicalRecordId, $toothNum, $typeId, $surfaceName, $code, $commentOrNull);
                        $tsStmt->execute();
                    }
                }
            }
            $tsStmt->close();
        }

        if ($treatmentPlan !== null) {
            $conn->query("DELETE FROM treatment_plans WHERE record_id = $clinicalRecordId");
            $tpStmt = $conn->prepare('INSERT INTO treatment_plans (record_id, description, status) VALUES (?, ?, ?)');
            $tpDesc = is_string($treatmentPlan) ? $treatmentPlan : json_encode($treatmentPlan);
            $planned = 'planned';
            $tpStmt->bind_param('iss', $clinicalRecordId, $tpDesc, $planned);
            $tpStmt->execute();
            $tpStmt->close();
        }

        if (!empty($visitClinicalData)) {
            $conn->query("DELETE FROM patient_profiling WHERE patient_id = $patientId AND record_id = $clinicalRecordId");
            $pf_race = $visitClinicalData['race'] ?? null;
            $pf_occupation = $visitClinicalData['occupation'] ?? null;
            $pf_medical_history = $visitClinicalData['medical_history'] ?? null;
            $pf_medications = $visitClinicalData['medications'] ?? null;
            $pf_allergies = $visitClinicalData['allergies'] ?? null;
            $pf_smoking = $visitClinicalData['smoking'] ?? null;
            $pf_alcohol = $visitClinicalData['alcohol'] ?? null;
            $pf_hypertensive = (int)($visitClinicalData['hypertensive'] ?? 0);
            $pf_diabetic = (int)($visitClinicalData['diabetic'] ?? 0);
            $pf_asthmatic = (int)($visitClinicalData['asthmatic'] ?? 0);
            $pf_heart_disease = (int)($visitClinicalData['heart_disease'] ?? 0);
            $pf_hiv_positive = (int)($visitClinicalData['hiv_positive'] ?? 0);
            $pf_bleeding_disorder = $visitClinicalData['bleeding_disorder'] ?? null;
            $pf_pregnancy_status = $visitClinicalData['pregnancy_status'] ?? null;
            $pf_previous_dental_treatment = $visitClinicalData['previous_dental_treatment'] ?? null;
            $pf_dental_anxiety = $visitClinicalData['dental_anxiety'] ?? null;
            $pf_emergency_contact = $visitClinicalData['emergency_contact'] ?? null;
            $pf_emergency_phone = $visitClinicalData['emergency_phone'] ?? null;
            $pf_background_notes = $visitClinicalData['background_notes'] ?? null;

            $pfStmt = $conn->prepare('INSERT INTO patient_profiling (patient_id, record_id, race, occupation, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, pregnancy_status, previous_dental_treatment, dental_anxiety, emergency_contact, emergency_phone, background_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $pfStmt->bind_param('iisssssssiiiiisssssss',
                $patientId, $clinicalRecordId,
                $pf_race, $pf_occupation,
                $pf_medical_history, $pf_medications,
                $pf_allergies, $pf_smoking,
                $pf_alcohol,
                $pf_hypertensive, $pf_diabetic,
                $pf_asthmatic, $pf_heart_disease,
                $pf_hiv_positive,
                $pf_bleeding_disorder,
                $pf_pregnancy_status, $pf_previous_dental_treatment,
                $pf_dental_anxiety, $pf_emergency_contact,
                $pf_emergency_phone, $pf_background_notes
            );
            $pfStmt->execute();
            $pfStmt->close();
        }
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

    // Auto-add consultation fee if no line items
    if (empty($lineItems)) {
        $consultationFee = get_clinical_setting($conn, 'consultation_fee', 0);
        if ($consultationFee > 0) {
            $lineItems[] = [
                'description' => 'Consultation Fee',
                'amount' => $consultationFee,
                'item_type' => 'fee',
            ];
            $subtotal = $consultationFee;
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

    $paymentRecorded = false;
    if ($recordPayment === 1) {
        if (!capabilities_can('billing.record')) {
            throw new Exception('Not authorized to record payments.');
        }
        if ($paymentOrNumber === '') {
            throw new Exception('OR number is required to record a payment.');
        }
        $amountApplied = round(min($paymentAmountApplied, $grandTotal), 2);
        if ($amountApplied <= 0) {
            throw new Exception('Enter an amount tendered to record a payment.');
        }
        if ($amountApplied < $grandTotal && $amountApplied < round($grandTotal * 0.5, 2)) {
            throw new Exception('A partial payment must be at least 50% of the total due.');
        }
        $change = max(0, round($paymentAmountTendered - $amountApplied, 2));
        $paymentNotesFull = trim($paymentNotes . sprintf(
            ' (Tendered: ₱%s, Change: ₱%s)',
            number_format($paymentAmountTendered, 2),
            number_format($change, 2)
        ));
        $paymentOk = billing_record_payment($conn, $billingId, $amountApplied, $paymentMethod, $paymentOrNumber, $paymentNotesFull, $secretaryId);
        if (!$paymentOk) {
            throw new Exception('Failed to record payment.');
        }
        $paymentRecorded = true;
    }

    $followUpAppointmentId = null;
    if ($followUpDateDb !== null) {
        try {
            $followUpAppointmentId = workflow_create_followup_appointment(
                $conn,
                $appointmentId,
                $patientId,
                $doctorId,
                $followUpDateDb
            );
        } catch (Exception $e) {
            error_log('Follow-up scheduling failed: ' . $e->getMessage());
        }
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
    $details = 'Secretary submitted clinical record. Record ID: ' . $clinicalRecordId . ', Billing ID: ' . $billingId . ', Total: ' . $grandTotal
        . ($paymentRecorded ? ', Payment recorded: ' . round(min($paymentAmountApplied, $grandTotal), 2) : '');
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
        'payment_recorded' => $paymentRecorded,
    ];
    idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
    api_respond($response);
} catch (Exception $e) {
    $conn->rollback();
    idempotency_clear_processing($idempotencyScope, $idempotencyKey);
    error_log('create_secretary_record error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_respond(['success' => false, 'message' => 'The system encountered an issue while saving. Please try again.'], 500);
}
