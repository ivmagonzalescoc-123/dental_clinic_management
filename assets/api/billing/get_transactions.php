<?php
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';

security_start_session();

if (!isset($_SESSION['patient_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'patient') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

billing_ensure_tables($conn);

$patient_id = (int)$_SESSION['patient_id'];
$response_data = [];

try {
    $stmt = $conn->prepare("
        SELECT
            a.appointment_id,
            a.appointment_date,
            cr.record_id,
            b.billing_id,
            b.total_amount,
            b.amount_paid,
            (b.total_amount - b.amount_paid) AS balance,
            b.status AS billing_status
        FROM appointments a
        LEFT JOIN clinical_records cr ON a.appointment_id = cr.appointment_id
        LEFT JOIN billing b ON a.appointment_id = b.appointment_id
        WHERE a.patient_id = ?
          AND (b.billing_id IS NOT NULL OR cr.record_id IS NOT NULL)
        ORDER BY a.appointment_date DESC, a.appointment_id DESC
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare transaction query.');
    }
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $appointments_data = [];
    while ($row = $result->fetch_assoc()) {
        $appointments_data[] = $row;
    }
    $stmt->close();

    $all_service_details = [];
    $s_stmt = $conn->query('SELECT service_id, service_name, initial_price, description FROM services');
    if ($s_stmt) {
        while ($s_row = $s_stmt->fetch_assoc()) {
            $all_service_details[(int)$s_row['service_id']] = $s_row;
        }
        $s_stmt->close();
    }

    $lineItemsStmt = $conn->prepare('
        SELECT description, amount, item_type
        FROM billing_line_items
        WHERE appointment_id = ?
        ORDER BY item_id ASC
    ');

    foreach ($appointments_data as $appt) {
        $appointment_id = (int)$appt['appointment_id'];
        $billing_id = (int)($appt['billing_id'] ?? 0);
        $total_charge = (float)($appt['total_amount'] ?? 0.0);
        $total_paid = (float)($appt['amount_paid'] ?? 0.0);
        $balance = (float)($appt['balance'] ?? 0.0);

        if ($billing_id > 0) {
            billing_sync_from_payments($conn, $billing_id);
            $b_stmt = $conn->prepare('SELECT total_amount, amount_paid, (total_amount - amount_paid) AS balance, status FROM billing WHERE billing_id = ? LIMIT 1');
            if ($b_stmt) {
                $b_stmt->bind_param('i', $billing_id);
                $b_stmt->execute();
                $b_row = $b_stmt->get_result()->fetch_assoc();
                $b_stmt->close();
                if ($b_row) {
                    $total_charge = (float)($b_row['total_amount'] ?? $total_charge);
                    $total_paid = (float)($b_row['amount_paid'] ?? $total_paid);
                    $balance = (float)($b_row['balance'] ?? $balance);
                    $appt['billing_status'] = $b_row['status'] ?? $appt['billing_status'];
                }
            }
        }

        $line_items = [];
        if ($lineItemsStmt) {
            $lineItemsStmt->bind_param('i', $appointment_id);
            $lineItemsStmt->execute();
            $li_res = $lineItemsStmt->get_result();
            while ($li_row = $li_res->fetch_assoc()) {
                $line_items[] = [
                    'type' => (string)($li_row['item_type'] ?? 'service'),
                    'description' => (string)($li_row['description'] ?? 'Charge'),
                    'remark' => ucfirst((string)($li_row['item_type'] ?? 'service')),
                    'charge' => (float)($li_row['amount'] ?? 0),
                ];
            }
        }

        if (empty($line_items)) {
            $recordId = (int)($appt['record_id'] ?? 0);
            if ($recordId > 0) {
                $svcStmt = $conn->prepare('SELECT s.service_id, s.service_name, s.initial_price, s.description FROM clinical_record_services crs LEFT JOIN services s ON s.service_id = crs.service_id WHERE crs.record_id = ?');
                if ($svcStmt) {
                    $svcStmt->bind_param('i', $recordId);
                    $svcStmt->execute();
                    $svcRes = $svcStmt->get_result();
                    while ($svcRow = $svcRes->fetch_assoc()) {
                        $line_items[] = [
                            'type' => 'Service',
                            'description' => (string)$svcRow['service_name'],
                            'remark' => (string)($svcRow['description'] ?: 'Service Rendered'),
                            'charge' => (float)$svcRow['initial_price'],
                        ];
                    }
                    $svcStmt->close();
                }

                $matStmt = $conn->prepare('SELECT material_name, quantity, cost FROM treatment_materials WHERE record_id = ?');
                if ($matStmt) {
                    $matStmt->bind_param('i', $recordId);
                    $matStmt->execute();
                    $matRes = $matStmt->get_result();
                    while ($matRow = $matRes->fetch_assoc()) {
                        $line_items[] = [
                            'type' => 'Material',
                            'description' => (string)$matRow['material_name'],
                            'remark' => 'Quantity: ' . (string)$matRow['quantity'],
                            'charge' => (float)$matRow['quantity'] * (float)$matRow['cost'],
                        ];
                    }
                    $matStmt->close();
                }
            }
        }

        $response_data[] = [
            'appointment_id' => $appointment_id,
            'date' => $appt['appointment_date'],
            'line_items' => $line_items,
            'billing' => [
                'total_charge' => $total_charge,
                'total_paid' => $total_paid,
                'balance' => $balance,
                'status' => $appt['billing_status'] ?? null,
            ],
        ];
    }

    if ($lineItemsStmt) {
        $lineItemsStmt->close();
    }

    echo json_encode(['success' => true, 'transactions' => $response_data]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while loading transactions.']);
}
