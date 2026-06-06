<?php
include_once __DIR__ . '/config/session_check.php';
header('Content-Type: application/json');

if (!isset($_SESSION['patient_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'patient') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once __DIR__ . '/config/database.php';
include_once __DIR__ . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$patient_id = (int)$_SESSION['patient_id'];
$response_data = [];

if (!function_exists('transactions_format_scalar_list')) {
    function transactions_format_scalar_list($values): string
    {
        if (!is_array($values)) {
            return trim((string)$values);
        }
        $parts = [];
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                if ($encoded !== false && $encoded !== 'null') {
                    $parts[] = $encoded;
                }
            } elseif ($value !== null && $value !== '') {
                $parts[] = (string)$value;
            }
        }
        return trim(implode(', ', $parts));
    }
}

if (!function_exists('transactions_parse_odontogram_findings')) {
    function transactions_parse_odontogram_findings($raw): array
    {
        $findings = [];
        $odontogram = json_decode((string)$raw, true);
        if (!is_array($odontogram)) {
            return $findings;
        }

        foreach ($odontogram as $toothNo => $entry) {
            $remark = '';
            if (is_array($entry)) {
                if (isset($entry['statuses']) || isset($entry['comment'])) {
                    $parts = [];
                    if (!empty($entry['statuses'])) {
                        $parts[] = transactions_format_scalar_list($entry['statuses']);
                    }
                    if (!empty($entry['comment'])) {
                        $parts[] = trim((string)$entry['comment']);
                    }
                    $remark = trim(implode(' — ', array_filter($parts, static fn($p) => $p !== '')));
                } else {
                    $remark = transactions_format_scalar_list($entry);
                }
            } else {
                $remark = trim((string)$entry);
            }

            if ($remark !== '') {
                $findings[] = [
                    'tooth_no' => (string)$toothNo,
                    'remark' => $remark,
                ];
            }
        }

        return $findings;
    }
}

try {
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
            $b_stmt = $conn->prepare('SELECT total_amount, amount_paid, balance, status FROM billing WHERE billing_id = ? LIMIT 1');
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
                    'tooth_no' => 'N/A',
                    'remark' => ucfirst((string)($li_row['item_type'] ?? 'service')),
                    'charge' => (float)($li_row['amount'] ?? 0),
                ];
            }
        }

        if (empty($line_items)) {
            $services = json_decode($appt['recommended_services'] ?? '[]', true);
            if (is_array($services)) {
                foreach ($services as $service_id) {
                    if (!is_numeric($service_id)) {
                        continue;
                    }
                    $service = $all_service_details[(int)$service_id] ?? null;
                    if ($service) {
                        $line_items[] = [
                            'type' => 'Service',
                            'description' => (string)$service['service_name'],
                            'tooth_no' => 'N/A',
                            'remark' => (string)($service['description'] ?: 'Service Rendered'),
                            'charge' => (float)$service['initial_price'],
                        ];
                    }
                }
            }

            $materials = json_decode($appt['materials_used'] ?? '[]', true);
            if (is_array($materials)) {
                foreach ($materials as $material) {
                    if (!is_array($material)) {
                        continue;
                    }
                    $line_items[] = [
                        'type' => 'Material',
                        'description' => (string)($material['name'] ?? 'Unknown Material'),
                        'tooth_no' => 'N/A',
                        'remark' => 'Quantity: ' . (string)($material['qty'] ?? 1),
                        'charge' => (float)($material['qty'] ?? 1) * (float)($material['price'] ?? 0),
                    ];
                }
            }
        }

        $odontogram_findings = transactions_parse_odontogram_findings($appt['odontogram'] ?? '{}');

        $response_data[] = [
            'appointment_id' => $appointment_id,
            'date' => $appt['appointment_date'],
            'line_items' => $line_items,
            'odontogram_findings' => $odontogram_findings,
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
