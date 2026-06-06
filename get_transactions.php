<?php
include_once __DIR__ . '/config/session_check.php';
header('Content-Type: application/json');

// This is a patient-only endpoint
if (!isset($_SESSION['patient_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'patient') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once __DIR__ . '/config/database.php';
include_once __DIR__ . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$patient_id = $_SESSION['patient_id'];
$response_data = [];

try {
    // Main query to get completed appointments with their billing and clinical records
    $stmt = $conn->prepare("
        SELECT
            a.appointment_id,
            a.appointment_date,
            cr.recommended_services,
            cr.materials_used,
            cr.odontogram,
            b.billing_id,
            b.total_amount,
            b.amount_paid,
            b.balance,
            b.status as billing_status
        FROM appointments a
        LEFT JOIN clinical_records cr ON a.appointment_id = cr.appointment_id
        LEFT JOIN billing b ON a.appointment_id = b.appointment_id
        WHERE a.patient_id = ? AND cr.record_id IS NOT NULL
        ORDER BY a.appointment_date DESC, a.appointment_id DESC
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $appointments_data = [];
    while ($row = $result->fetch_assoc()) {
        $appointments_data[] = $row;
    }
    $stmt->close();

    // Pre-fetch all service details to avoid querying in a loop
    $all_service_details = [];
    $s_stmt = $conn->query("SELECT service_id, service_name, initial_price, description FROM services");
    if ($s_stmt) {
        while ($s_row = $s_stmt->fetch_assoc()) {
            $all_service_details[$s_row['service_id']] = $s_row;
        }
        $s_stmt->close();
    }

    foreach ($appointments_data as $appt) {
        $appointment_id = $appt['appointment_id'];
        $billing_id = $appt['billing_id'];
        $total_charge = (float)($appt['total_amount'] ?? 0.0);
        if ($billing_id) {
            billing_sync_from_payments($conn, (int)$billing_id);
            $b_stmt = $conn->prepare('SELECT amount_paid, balance FROM billing WHERE billing_id = ? LIMIT 1');
            if ($b_stmt) {
                $b_stmt->bind_param('i', $billing_id);
                $b_stmt->execute();
                $b_row = $b_stmt->get_result()->fetch_assoc();
                $b_stmt->close();
                $total_paid = (float)($b_row['amount_paid'] ?? 0);
                $balance = (float)($b_row['balance'] ?? max(0, $total_charge - $total_paid));
            } else {
                $total_paid = 0.0;
                $balance = $total_charge;
            }
        } else {
            $total_paid = 0.0;
            $balance = $total_charge;
        }

        $line_items = [];
        $services = json_decode($appt['recommended_services'] ?? '[]', true);
        if (is_array($services)) {
            foreach ($services as $service_id) {
                $service = $all_service_details[$service_id] ?? null;
                if ($service) {
                    $line_items[] = [
                        'type' => 'Service',
                        'description' => $service['service_name'],
                        'tooth_no' => 'N/A',
                        'remark' => $service['description'] ?: 'Service Rendered',
                        'charge' => (float)$service['initial_price'],
                    ];
                }
            }
        }

        $materials = json_decode($appt['materials_used'] ?? '[]', true);
        if (is_array($materials)) {
            foreach ($materials as $material) {
                $line_items[] = [
                    'type' => 'Material',
                    'description' => $material['name'] ?? 'Unknown Material',
                    'tooth_no' => 'N/A',
                    'remark' => 'Quantity: ' . ($material['qty'] ?? 1),
                    'charge' => (float)($material['qty'] ?? 1) * (float)($material['price'] ?? 0),
                ];
            }
        }

        $odontogram_findings = [];
        $odontogram = json_decode($appt['odontogram'] ?? '{}', true);
        if (is_array($odontogram)) {
            foreach ($odontogram as $tooth_no => $conditions) {
                 $condition_text = is_array($conditions) ? implode(', ', $conditions) : $conditions;
                 if (!empty($condition_text)) {
                    $odontogram_findings[] = [
                        'tooth_no' => (string)$tooth_no,
                        'remark' => $condition_text,
                    ];
                 }
            }
        }

        $response_data[] = [
            'appointment_id' => $appointment_id,
            'date' => $appt['appointment_date'],
            'line_items' => $line_items,
            'odontogram_findings' => $odontogram_findings,
            'billing' => [
                'total_charge' => $total_charge,
                'total_paid' => $total_paid,
                'balance' => $balance,
                'status' => $appt['billing_status']
            ]
        ];
    }

    echo json_encode(['success' => true, 'transactions' => $response_data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}