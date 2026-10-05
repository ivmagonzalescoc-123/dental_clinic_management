<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include dirname(dirname(dirname(__DIR__))) . '/config/capabilities.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/idempotency_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!in_array($_SESSION['role'] ?? '', ['secretary', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!capabilities_can('billing.record')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!security_validate_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$billingId = (int)($_POST['billing_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$amountTendered = (float)($_POST['payment_amount_tendered'] ?? 0);
$method = trim((string)($_POST['payment_method'] ?? 'cash'));
$orNumber = trim((string)($_POST['or_number'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$recordedBy = (int)($_SESSION['user_id'] ?? 0);
$idempotencyKey = $_POST['idempotency_key'] ?? '';

if ($billingId <= 0 || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid billing ID and amount required']);
    exit;
}

if ($amountTendered <= 0) {
    echo json_encode(['success' => false, 'message' => 'Enter an amount tendered to record a payment.']);
    exit;
}

if ($amountTendered < $amount) {
    echo json_encode(['success' => false, 'message' => 'Amount tendered cannot be less than the payment amount.']);
    exit;
}

$changeDue = round($amountTendered - $amount, 2);
$notes = trim($notes . sprintf(' (Tendered: ₱%s, Change: ₱%s)', number_format($amountTendered, 2), number_format($changeDue, 2)));

if ($orNumber === '') {
    echo json_encode(['success' => false, 'message' => 'OR number is required']);
    exit;
}

if (strlen($orNumber) > 50) {
    echo json_encode(['success' => false, 'message' => 'OR number must be 50 characters or less']);
    exit;
}

billing_ensure_tables($conn);
billing_migrate_billing_columns($conn);

$idempotencyScope = 'billing.record_payment.' . $billingId;
$cached = idempotency_handle_request($idempotencyScope, $idempotencyKey);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare('SELECT total_amount, (total_amount - amount_paid) AS balance, status FROM billing WHERE billing_id = ? LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        throw new Exception('Unable to load billing record');
    }
    $stmt->bind_param('i', $billingId);
    $stmt->execute();
    $billing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$billing) {
        $conn->rollback();
        idempotency_clear_processing($idempotencyScope, $idempotencyKey);
        echo json_encode(['success' => false, 'message' => 'Billing record not found']);
        exit;
    }

    billing_sync_from_payments($conn, $billingId, $recordedBy ?: null);

    $stmt = $conn->prepare('SELECT (total_amount - amount_paid) AS balance, status, amount_paid FROM billing WHERE billing_id = ? LIMIT 1');
    $stmt->bind_param('i', $billingId);
    $stmt->execute();
    $billing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $balance = (float)($billing['balance'] ?? 0);
    $status = (string)($billing['status'] ?? 'unpaid');

    if ($status === 'paid' || $balance <= 0) {
        $conn->rollback();
        idempotency_clear_processing($idempotencyScope, $idempotencyKey);
        echo json_encode(['success' => false, 'message' => 'This bill is already fully paid.']);
        exit;
    }

    if ($amount > $balance + 0.001) {
        $conn->rollback();
        idempotency_clear_processing($idempotencyScope, $idempotencyKey);
        echo json_encode(['success' => false, 'message' => 'Payment amount exceeds remaining balance of ₱' . number_format($balance, 2)]);
        exit;
    }

    $duplicate = billing_find_recent_duplicate_payment($conn, $billingId, $amount, $method, $recordedBy);
    if ($duplicate) {
        $conn->rollback();
        $response = [
            'success' => true,
            'message' => 'Payment already recorded',
            'billing_id' => $billingId,
            'status' => $status,
            'amount_paid' => (float)($billing['amount_paid'] ?? 0),
            'balance' => $balance,
            'duplicate_replay' => true,
        ];
        idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
        echo json_encode($response);
        exit;
    }

    $ok = billing_record_payment($conn, $billingId, $amount, $method, $orNumber, $notes, $recordedBy);
    if (!$ok) {
        throw new Exception('Failed to record payment');
    }
    $conn->commit();

    $stmt = $conn->prepare('SELECT amount_paid, (total_amount - amount_paid) AS balance, status FROM billing WHERE billing_id = ? LIMIT 1');
    $stmt->bind_param('i', $billingId);
    $stmt->execute();
    $updated = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (function_exists('log_system_event')) {
        log_system_event(
            $conn,
            'billing.record_payment',
            'billing',
            $billingId,
            'Recorded payment of ' . $amount . '; balance=' . ($updated['balance'] ?? 0),
            $recordedBy
        );
    }

    $response = [
        'success' => true,
        'message' => 'Payment recorded successfully',
        'billing_id' => $billingId,
        'status' => $updated['status'] ?? 'partial',
        'amount_paid' => (float)($updated['amount_paid'] ?? 0),
        'balance' => (float)($updated['balance'] ?? 0),
    ];
    idempotency_store_response($idempotencyScope, $idempotencyKey, $response);
    echo json_encode($response);
} catch (Exception $e) {
    $conn->rollback();
    idempotency_clear_processing($idempotencyScope, $idempotencyKey);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
