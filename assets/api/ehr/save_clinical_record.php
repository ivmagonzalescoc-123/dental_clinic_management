<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

// Only doctors may save clinical records
check_session(['doctor']);
$doctor_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$recommended_services = isset($_POST['recommended_services']) ? json_decode($_POST['recommended_services'], true) : null;
$treatment_plan = isset($_POST['treatment_plan']) ? json_decode($_POST['treatment_plan'], true) : null;
$materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
$odontogram = isset($_POST['odontogram']) ? json_decode($_POST['odontogram'], true) : null;
$doctor_notes = $_POST['doctor_notes'] ?? '';
$follow_up_date = $_POST['follow_up_date'] ?? null;
$submit_and_finalize = isset($_POST['finalize']) && $_POST['finalize'] == '1';

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

    // Upsert clinical_records
    $json_recommended = $recommended_services ? json_encode($recommended_services, JSON_UNESCAPED_UNICODE) : null;
    $json_treatment = $treatment_plan ? json_encode($treatment_plan, JSON_UNESCAPED_UNICODE) : null;
    $json_materials = $materials_used ? json_encode($materials_used, JSON_UNESCAPED_UNICODE) : null;
    $json_odontogram = $odontogram ? json_encode($odontogram, JSON_UNESCAPED_UNICODE) : null;

    // Check if record exists
    $stmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare('UPDATE clinical_records SET doctor_id=?, patient_id=?, recommended_services=?, treatment_plan=?, materials_used=?, odontogram=?, doctor_notes=?, follow_up_date=?, status=?, submitted_by=?, submitted_at=IF(?=1,NOW(),submitted_at) WHERE appointment_id=?');
        $status = $submit_and_finalize ? 'submitted' : 'in_progress';
        $submitted_flag = $submit_and_finalize ? 1 : 0;
        $stmt->bind_param('iisssssssiii', $doctor_id, $patient_id, $json_recommended, $json_treatment, $json_materials, $json_odontogram, $doctor_notes, $follow_up_date, $status, $doctor_id, $submitted_flag, $appointment_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, recommended_services, treatment_plan, materials_used, odontogram, doctor_notes, follow_up_date, status, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?=1,NOW(),NULL))');
        $status = $submit_and_finalize ? 'submitted' : 'in_progress';
        $submitted_flag = $submit_and_finalize ? 1 : 0;
        $stmt->bind_param('iiisssssssii', $appointment_id, $doctor_id, $patient_id, $json_recommended, $json_treatment, $json_materials, $json_odontogram, $doctor_notes, $follow_up_date, $status, $doctor_id, $submitted_flag);
        $stmt->execute();
        $stmt->close();
    }

    // If finalized/submitted, update appointment status and queue, and create billing
    if ($submit_and_finalize) {
        $status = 'completed';
        $ust = $conn->prepare("UPDATE appointments SET status = ?, notes = CONCAT(IFNULL(notes,''), ?) WHERE appointment_id = ?");
        $note_append = "\n[Clinical record submitted by doctor {$doctor_id}]";
        $ust->bind_param('ssi', $status, $note_append, $appointment_id);
        $ust->execute();
        $ust->close();

        $qst = $conn->prepare('UPDATE check_in_queue SET status = ?, served_time = NOW(), completed_time = NOW() WHERE appointment_id = ?');
        $qstatus = 'served';
        $qst->bind_param('si', $qstatus, $appointment_id);
        $qst->execute();
        $qst->close();

        // Calculate billing: sum of selected service prices + materials total
        $total = 0.0;
        if ($recommended_services && is_array($recommended_services)) {
            // recommended_services expected as array of service_ids
            $placeholders = implode(',', array_fill(0, count($recommended_services), '?'));
            // build dynamic query safely using prepared statement by looping
            $prices = [];
            foreach ($recommended_services as $sid) {
                $sstmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
                $sstmt->bind_param('i', $sid);
                $sstmt->execute();
                $res = $sstmt->get_result();
                if ($res && ($r = $res->fetch_assoc())) $total += (float)$r['initial_price'];
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

        // Insert billing record using existing billing schema (billing: billing_id, appointment_id, user_id, total_amount, created_at, status, processed_by)
        $bstmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, created_at, status) VALUES (?, ?, ?, NOW(), ?)');
        $billing_status = 'unpaid';
        $bstmt->bind_param('iids', $appointment_id, $patient_id, $total, $billing_status);
        $bstmt->execute();
        $bstmt->close();
    }

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Clinical record saved', 'finalized' => $submit_and_finalize, 'billing_amount' => isset($total) ? $total : null]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
