<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
header('Content-Type: application/json');

check_session(['doctor']);
capabilities_require_json('clinical.save');
$doctor_id = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$findingsRaw = $_POST['clinical_findings'] ?? '{}';
$findingsDecoded = json_decode($findingsRaw, true);
$findings = filter_visit_clinical_data(is_array($findingsDecoded) ? $findingsDecoded : []);

if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare('SELECT patient_id FROM appointments WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appt) throw new Exception('Appointment not found');
    $patient_id = (int)$appt['patient_id'];

    $stmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $record_id = (int)$existing['record_id'];
    } else {
        // Doctor is entering findings before ever having saved a chart/notes for this
        // appointment yet — create the clinical_records row now so there's a record_id
        // to attach the findings to (mirrors the upsert save_clinical_record.php does).
        $ins = $conn->prepare("INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, status) VALUES (?, ?, ?, 'in_progress')");
        $ins->bind_param('iii', $appointment_id, $doctor_id, $patient_id);
        $ins->execute();
        $record_id = (int)$conn->insert_id;
        $ins->close();
    }

    save_clinical_findings($conn, $record_id, $patient_id, $findings);

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Clinical findings saved', 'record_id' => $record_id]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
