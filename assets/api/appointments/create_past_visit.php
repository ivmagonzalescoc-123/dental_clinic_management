<?php
/**
 * Create Past Visit API
 * Encodes one visit from the clinic's old paper records: a completed appointment dated in
 * the past, its clinical record, the treatments done and (optionally) a tooth chart.
 *
 * Billing is not part of a past visit. The one exception is an installment plan the
 * patient is still paying: its remaining balance is carried over as a bill with monthly
 * terms, so the next payments can be taken through Billing. No payment is recorded here,
 * which keeps past revenue reports as they were.
 *
 * Unlike create_secretary_record.php this sends no notifications, books no follow-up and
 * checks no timeslots. Open to the admin, and to the secretary only while the admin has
 * switched access on (past_visits_allowed).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/clinic_settings.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/booking_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/idempotency_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/odontogram_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/treatment_plan_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';

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

$encoderId = (int)($_SESSION['user_id'] ?? 0);
if ($encoderId <= 0 || !in_array($_SESSION['role'] ?? '', ['secretary', 'admin'], true)) {
    api_respond(['success' => false, 'message' => 'Unauthorized'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!security_validate_csrf_token($_POST['csrf_token'] ?? '')) {
    api_respond(['success' => false, 'message' => 'Please refresh the page and try again.'], 403);
}

if (!past_visits_allowed($conn)) {
    api_respond(['success' => false, 'message' => 'Past visit entry is switched off. Ask the admin to turn it on.', 'code' => 'past_visits_disabled'], 403);
}

$patientId = (int)($_POST['patient_id'] ?? 0);
$visitDate = trim((string)($_POST['visit_date'] ?? ''));
$doctorId = (int)($_POST['doctor_id'] ?? 0);
$chiefComplaint = trim((string)($_POST['chief_complaint'] ?? ''));
$summary = trim((string)($_POST['summary'] ?? ''));
$proceduresRaw = json_decode((string)($_POST['procedures'] ?? '[]'), true);
$odontogramData = odontogram_normalize_data(json_decode((string)($_POST['odontogram'] ?? '{}'), true) ?: []);
$hasInstallment = (int)($_POST['has_installment'] ?? 0) === 1;
$idempotencyKey = $_POST['idempotency_key'] ?? '';

/** A real calendar date in Y-m-d, or null. */
function past_visit_date(string $value): ?string
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value ? $value : null;
}

if ($patientId <= 0) {
    api_respond(['success' => false, 'message' => 'Missing patient.'], 400);
}
$today = date('Y-m-d');
if (past_visit_date($visitDate) === null || $visitDate >= $today || $visitDate < '1950-01-01') {
    api_respond(['success' => false, 'message' => 'Enter the date of the visit. It must be before today.'], 422);
}

$pStmt = $conn->prepare('SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1');
$pStmt->bind_param('i', $patientId);
$pStmt->execute();
$patientExists = (bool)$pStmt->get_result()->fetch_row();
$pStmt->close();
if (!$patientExists) {
    api_respond(['success' => false, 'message' => 'Patient not found.'], 404);
}

// The dentist is optional: the one who did the work may no longer be in the system.
$doctorIdDb = null;
$doctorName = '';
if ($doctorId > 0) {
    $dStmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name
        FROM users u INNER JOIN role r ON r.role_id = u.role_id
        WHERE u.user_id = ? AND r.role_name = 'doctor'
        LIMIT 1
    ");
    $dStmt->bind_param('i', $doctorId);
    $dStmt->execute();
    $doctorRow = $dStmt->get_result()->fetch_assoc();
    $dStmt->close();
    if (!$doctorRow) {
        api_respond(['success' => false, 'message' => 'Select a valid dentist.'], 422);
    }
    $doctorIdDb = (int)$doctorRow['user_id'];
    $doctorName = trim($doctorRow['first_name'] . ' ' . $doctorRow['last_name']);
}

// Procedures done: a service from the list plus the teeth it was done on.
$procedures = [];
if (is_array($proceduresRaw)) {
    $svcStmt = $conn->prepare('SELECT service_name FROM services WHERE service_id = ? LIMIT 1');
    foreach ($proceduresRaw as $proc) {
        $serviceId = (int)($proc['service_id'] ?? 0);
        if ($serviceId <= 0) continue;
        $teeth = treatment_plan_normalize_teeth((string)($proc['teeth'] ?? ''));
        if ($teeth === null) {
            api_respond(['success' => false, 'message' => 'Each tooth must be a 2-digit FDI number (11–48, or 51–85 for baby teeth), separated by commas (e.g. 11, 12, 21). Leave blank for the whole mouth.'], 422);
        }
        $svcStmt->bind_param('i', $serviceId);
        $svcStmt->execute();
        $svcRow = $svcStmt->get_result()->fetch_assoc();
        if (!$svcRow) {
            api_respond(['success' => false, 'message' => 'Select a valid service for each procedure.'], 422);
        }
        $procedures[] = ['service_id' => $serviceId, 'name' => (string)$svcRow['service_name'], 'teeth' => $teeth === '' ? null : $teeth];
    }
    $svcStmt->close();
}
if (count($procedures) > 30) {
    api_respond(['success' => false, 'message' => 'Too many procedures for one visit.'], 422);
}
if (empty($procedures) && $summary === '') {
    api_respond(['success' => false, 'message' => 'Add at least one procedure, or describe the visit in the notes.'], 422);
}
if (mb_strlen($summary) > 5000) $summary = mb_substr($summary, 0, 5000);
if (mb_strlen($chiefComplaint) > 500) $chiefComplaint = mb_substr($chiefComplaint, 0, 500);

// Carried-over installment plan.
$instBalance = round((float)($_POST['inst_balance'] ?? 0), 2);
$instTerms = (int)($_POST['inst_terms'] ?? 0);
$instNextDue = trim((string)($_POST['inst_next_due'] ?? ''));
$instTreatment = trim((string)($_POST['inst_treatment'] ?? ''));
$instOriginalTotal = round((float)($_POST['inst_original_total'] ?? 0), 2);
$instPaid = round((float)($_POST['inst_paid'] ?? 0), 2);
if ($hasInstallment) {
    if ($instBalance <= 0 || $instBalance > 10000000) {
        api_respond(['success' => false, 'message' => 'Enter the remaining balance of the installment plan.'], 422);
    }
    if ($instTerms < 1 || $instTerms > 60) {
        api_respond(['success' => false, 'message' => 'Enter how many monthly payments are left (1 to 60).'], 422);
    }
    if (past_visit_date($instNextDue) === null || $instNextDue < $visitDate) {
        api_respond(['success' => false, 'message' => 'Enter the date the next installment payment is due.'], 422);
    }
    if ($instOriginalTotal < 0 || $instPaid < 0) {
        api_respond(['success' => false, 'message' => 'The original total and amount paid cannot be negative.'], 422);
    }
}

$reason = 'Past visit';
if (!empty($procedures)) {
    $reason = implode(', ', array_values(array_unique(array_column($procedures, 'name'))));
    if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 252) . '...';
}

$idempotencyScope = 'staff.create_past_visit.' . $patientId;
$cached = idempotency_handle_request($idempotencyScope, $idempotencyKey);
if ($cached !== null) {
    api_respond($cached);
}

// Table and column setup runs before the transaction: these statements commit on their
// own, so running them later would end the transaction early and break the rollback.
appointments_ensure_historical_column($conn);
appointments_ensure_reference_column($conn);
treatment_plan_ensure_table($conn);
ensure_clinical_findings_table($conn);
odontogram_ensure_tooth_status_schema($conn);
billing_ensure_tables($conn);
billing_migrate_billing_columns($conn);

try {
    $conn->begin_transaction();

    $visitDateTime = $visitDate . ' 00:00:00';
    $apptNotes = 'Encoded from paper records';
    $ins = $conn->prepare("
        INSERT INTO appointments
            (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id, is_emergency,
             completed_by_doctor_at, secretary_records_completed_at, is_historical)
        VALUES (?, ?, ?, 0, ?, 'completed', ?, 0, ?, ?, 1)
    ");
    if (!$ins) {
        throw new Exception('Failed to prepare appointment: ' . $conn->error);
    }
    $ins->bind_param('isssiss', $patientId, $visitDate, $reason, $apptNotes, $doctorIdDb, $visitDateTime, $visitDateTime);
    if (!$ins->execute()) {
        throw new Exception('Failed to create appointment: ' . $ins->error);
    }
    $appointmentId = (int)$conn->insert_id;
    $ins->close();
    appointment_assign_reference($conn, $appointmentId);

    // submitted_at is the visit date, not today, so the patient's records stay in the
    // order the visits happened. Who typed it in, and when, is in the system log.
    $recordStatus = 'finalized';
    $rec = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, summary, doctor_notes, follow_up_date, status, submitted_by, submitted_at) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?)');
    if (!$rec) {
        throw new Exception('Failed to prepare clinical record: ' . $conn->error);
    }
    $rec->bind_param('iiissis', $appointmentId, $doctorIdDb, $patientId, $summary, $recordStatus, $encoderId, $visitDateTime);
    if (!$rec->execute()) {
        throw new Exception('Failed to create clinical record: ' . $rec->error);
    }
    $clinicalRecordId = (int)$conn->insert_id;
    $rec->close();

    if ($chiefComplaint !== '') {
        save_clinical_findings($conn, $clinicalRecordId, $patientId, filter_visit_clinical_data(['chief_complaint' => $chiefComplaint]));
    }

    if (!empty($odontogramData)) {
        odontogram_save_for_record($conn, $clinicalRecordId, $odontogramData);
    }

    // Saved as completed treatment-plan items so they show under "Treatments" on the
    // record and in the patient's Treatments tab, like work done on a live visit.
    if (!empty($procedures)) {
        $tp = $conn->prepare("
            INSERT INTO patient_treatment_plan_items
                (patient_id, service_id, tooth_number, phase, estimated_cost, notes, status, created_by,
                 created_at, accepted_at, completed_appointment_id, completed_at)
            VALUES (?, ?, ?, 1, 0, ?, 'completed', ?, ?, ?, ?, ?)
        ");
        if (!$tp) {
            throw new Exception('Failed to prepare treatments: ' . $conn->error);
        }
        foreach ($procedures as $proc) {
            $tp->bind_param('iississis', $patientId, $proc['service_id'], $proc['teeth'], $apptNotes, $encoderId, $visitDateTime, $visitDateTime, $appointmentId, $visitDateTime);
            if (!$tp->execute()) {
                throw new Exception('Failed to save treatment: ' . $tp->error);
            }
        }
        $tp->close();
    }

    $billingId = null;
    if ($hasInstallment) {
        $label = $instTreatment !== '' ? $instTreatment : $reason;
        $description = 'Balance carried over: ' . $label;
        if ($instOriginalTotal > 0 || $instPaid > 0) {
            $description .= ' (original ₱' . number_format($instOriginalTotal, 2) . ', ₱' . number_format($instPaid, 2) . ' paid before this system)';
        }
        if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);

        $billingId = billing_create_or_update($conn, $appointmentId, $patientId, $instBalance, 'unpaid');
        if ($billingId <= 0) {
            throw new Exception('Failed to create billing record');
        }
        billing_save_line_items($conn, $appointmentId, $billingId, [
            ['service_id' => null, 'description' => $description, 'amount' => $instBalance, 'item_type' => 'extra'],
        ]);
        if (billing_create_installment_plan($conn, $billingId, $instBalance, $instTerms, 0.0, $instNextDue) === false) {
            throw new Exception('Failed to create installment plan.');
        }
        billing_create_invoice($conn, $billingId, $encoderId);
    }

    $conn->commit();

    log_system_event(
        $conn,
        'appointment.past_visit_created',
        'appointments',
        $appointmentId,
        'Past visit encoded from paper records. Visit date: ' . $visitDate . ', Record ID: ' . $clinicalRecordId
            . ($billingId ? ', carried-over installment balance: ' . $instBalance . ' in ' . $instTerms . ' terms (Billing ID: ' . $billingId . ')' : ''),
        $encoderId
    );

    $response = [
        'success' => true,
        'message' => 'Past visit saved.',
        'billing_id' => $billingId,
        // Same shape as the appointments built in includes/patients_module.php, so the open
        // patient chart can show the visit without reloading the page.
        'appointment' => [
            'appointment_id' => $appointmentId,
            'date' => $visitDate,
            'status' => 'completed',
            'is_online' => false,
            'is_historical' => true,
            'reason' => $reason,
            'notes' => $apptNotes,
            'doctor' => $doctorName,
            'start' => null,
            'end' => null,
            'record_id' => $clinicalRecordId,
            'record_status' => $recordStatus,
            'services' => [],
        ],
    ];
    idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
    api_respond($response);
} catch (Throwable $e) {
    $conn->rollback();
    idempotency_clear_processing($idempotencyScope, $idempotencyKey);
    error_log('create_past_visit error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_respond(['success' => false, 'message' => 'The system encountered an issue while saving. Please try again.'], 500);
}
