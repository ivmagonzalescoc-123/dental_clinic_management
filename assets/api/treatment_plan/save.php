<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/treatment_plan_helpers.php';
header('Content-Type: application/json');

function tp_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($role, ['doctor', 'secretary', 'admin'], true) || $userId <= 0) {
    tp_respond(['success' => false, 'message' => 'Unauthorized'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tp_respond(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!security_validate_csrf_token($_POST['csrf_token'] ?? '')) {
    tp_respond(['success' => false, 'message' => 'Please refresh the page and try again.'], 403);
}

treatment_plan_ensure_table($conn);

$action = (string)($_POST['action'] ?? '');
$patientId = (int)($_POST['patient_id'] ?? 0);
if ($patientId <= 0) {
    tp_respond(['success' => false, 'message' => 'Missing patient.'], 400);
}

// Both the doctor and the secretary can plan treatments. Only the doctor marks what
// was actually done at chairside; only the secretary edits existing items (she owns
// the plan's details and cost after discussing it with the patient).
$doctorOnly = ['complete', 'uncomplete'];
if (in_array($action, $doctorOnly, true) && $role !== 'doctor') {
    tp_respond(['success' => false, 'message' => 'Only the doctor can do that.'], 403);
}
if ($action === 'edit' && !in_array($role, ['secretary', 'admin'], true)) {
    tp_respond(['success' => false, 'message' => 'Only the secretary can edit plan items.'], 403);
}

function tp_load_item(mysqli $conn, int $itemId, int $patientId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM patient_treatment_plan_items WHERE item_id = ? AND patient_id = ? LIMIT 1');
    $stmt->bind_param('ii', $itemId, $patientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** The appointment must belong to this patient and still be open (not already finalized). */
function tp_load_open_appointment(mysqli $conn, int $appointmentId, int $patientId): ?array
{
    $stmt = $conn->prepare('SELECT appointment_id, status FROM appointments WHERE appointment_id = ? AND patient_id = ? LIMIT 1');
    $stmt->bind_param('ii', $appointmentId, $patientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    if (in_array($row['status'], ['completed', 'records_complete', 'cancelled', 'no_show'], true)) return null;
    return $row;
}

/** Validates the add/edit form fields shared by both actions. */
function tp_read_item_fields(mysqli $conn): array
{
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $tooth = treatment_plan_normalize_teeth((string)($_POST['tooth_number'] ?? ''));
    $phase = (int)($_POST['phase'] ?? 1);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($phase < 1 || $phase > 3) $phase = 1;
    if ($tooth === null) {
        tp_respond(['success' => false, 'message' => 'Each tooth must be a 2-digit FDI number (11–48, or 51–85 for baby teeth), separated by commas (e.g. 11, 12, 21). Leave blank for the whole mouth.'], 422);
    }
    if (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

    $stmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('i', $serviceId);
    $stmt->execute();
    $svc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$svc) {
        tp_respond(['success' => false, 'message' => 'Select a valid service.'], 422);
    }

    $costRaw = trim((string)($_POST['estimated_cost'] ?? ''));
    $cost = $costRaw === '' ? (float)$svc['initial_price'] : (float)$costRaw;
    if ($cost < 0 || $cost > 10000000) {
        tp_respond(['success' => false, 'message' => 'Enter a valid estimated cost.'], 422);
    }

    return [$serviceId, $tooth === '' ? null : $tooth, $phase, $cost, $notes === '' ? null : $notes];
}

$itemId = (int)($_POST['item_id'] ?? 0);

switch ($action) {
    case 'add': {
        [$serviceId, $toothDb, $phase, $cost, $notesDb] = tp_read_item_fields($conn);
        $sourceAppt = (int)($_POST['appointment_id'] ?? 0);
        $sourceDb = $sourceAppt > 0 ? $sourceAppt : null;
        $stmt = $conn->prepare('INSERT INTO patient_treatment_plan_items (patient_id, service_id, tooth_number, phase, estimated_cost, notes, source_appointment_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisidsii', $patientId, $serviceId, $toothDb, $phase, $cost, $notesDb, $sourceDb, $userId);
        $stmt->execute();
        $stmt->close();
        break;
    }

    case 'edit': {
        $item = tp_load_item($conn, $itemId, $patientId);
        if (!$item) tp_respond(['success' => false, 'message' => 'Plan item not found.'], 404);
        if ($item['status'] === 'completed') {
            tp_respond(['success' => false, 'message' => 'Completed treatments are part of the visit record and cannot be edited.'], 422);
        }
        [$serviceId, $toothDb, $phase, $cost, $notesDb] = tp_read_item_fields($conn);
        $stmt = $conn->prepare('UPDATE patient_treatment_plan_items SET service_id = ?, tooth_number = ?, phase = ?, estimated_cost = ?, notes = ?, updated_at = NOW() WHERE item_id = ?');
        $stmt->bind_param('isidsi', $serviceId, $toothDb, $phase, $cost, $notesDb, $itemId);
        $stmt->execute();
        $stmt->close();
        break;
    }

    case 'status': {
        $item = tp_load_item($conn, $itemId, $patientId);
        if (!$item) tp_respond(['success' => false, 'message' => 'Plan item not found.'], 404);
        if ($item['status'] === 'completed') {
            tp_respond(['success' => false, 'message' => 'Completed items can\'t be changed here.'], 422);
        }
        $new = (string)($_POST['status'] ?? '');
        if (!in_array($new, ['proposed', 'accepted', 'declined'], true)) {
            tp_respond(['success' => false, 'message' => 'Invalid status.'], 422);
        }
        $stmt = $conn->prepare("UPDATE patient_treatment_plan_items SET status = ?, accepted_at = IF(? = 'accepted', NOW(), NULL), updated_at = NOW() WHERE item_id = ?");
        $stmt->bind_param('ssi', $new, $new, $itemId);
        $stmt->execute();
        $stmt->close();
        break;
    }

    case 'delete': {
        $item = tp_load_item($conn, $itemId, $patientId);
        if (!$item) tp_respond(['success' => false, 'message' => 'Plan item not found.'], 404);
        if (!in_array($item['status'], ['proposed', 'declined'], true)) {
            tp_respond(['success' => false, 'message' => 'Only proposed or declined items can be removed. Mark it declined instead.'], 422);
        }
        $stmt = $conn->prepare('DELETE FROM patient_treatment_plan_items WHERE item_id = ?');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        break;
    }

    case 'complete': {
        $item = tp_load_item($conn, $itemId, $patientId);
        if (!$item) tp_respond(['success' => false, 'message' => 'Plan item not found.'], 404);
        if (!in_array($item['status'], ['proposed', 'accepted'], true)) {
            tp_respond(['success' => false, 'message' => 'This item is not open.'], 422);
        }
        $apptId = (int)($_POST['appointment_id'] ?? 0);
        if (!tp_load_open_appointment($conn, $apptId, $patientId)) {
            tp_respond(['success' => false, 'message' => 'Select this patient\'s current appointment first.'], 422);
        }
        // Doing a proposed item implies the patient agreed to it at chairside.
        $stmt = $conn->prepare("UPDATE patient_treatment_plan_items SET status = 'completed', accepted_at = IFNULL(accepted_at, NOW()), completed_appointment_id = ?, completed_at = NOW(), updated_at = NOW() WHERE item_id = ?");
        $stmt->bind_param('ii', $apptId, $itemId);
        $stmt->execute();
        $stmt->close();
        break;
    }

    case 'uncomplete': {
        $item = tp_load_item($conn, $itemId, $patientId);
        if (!$item) tp_respond(['success' => false, 'message' => 'Plan item not found.'], 404);
        $apptId = (int)($_POST['appointment_id'] ?? 0);
        if ($item['status'] !== 'completed' || (int)$item['completed_appointment_id'] !== $apptId || !tp_load_open_appointment($conn, $apptId, $patientId)) {
            tp_respond(['success' => false, 'message' => 'Only items completed on this still-open visit can be undone.'], 422);
        }
        $stmt = $conn->prepare("UPDATE patient_treatment_plan_items SET status = 'accepted', completed_appointment_id = NULL, completed_at = NULL, updated_at = NOW() WHERE item_id = ?");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        break;
    }

    default:
        tp_respond(['success' => false, 'message' => 'Unknown action.'], 400);
}

$items = treatment_plan_list($conn, $patientId);
tp_respond(['success' => true, 'items' => $items, 'totals' => treatment_plan_totals($items)]);
