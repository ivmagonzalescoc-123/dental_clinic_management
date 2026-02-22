<?php

/**
 * Lightweight System Logs helper.
 *
 * Safe to include anywhere after database connection is available.
 * If the `system_logs` table does not exist, all functions no-op.
 */

function system_logs_available(mysqli $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $available = false;
    $res = $conn->query("SHOW TABLES LIKE 'system_logs'");
    if ($res && $res->num_rows > 0) {
        $available = true;
    }

    return $available;
}

/**
 * @param mysqli $conn
 * @param string $action e.g. 'appointment.checkin', 'service.add'
 * @param string|null $entityType e.g. 'appointments', 'services'
 * @param int|null $entityId
 * @param string|null $details
 * @param int|null $userId
 */
function log_system_event(mysqli $conn, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null, ?int $userId = null): void
{
    if (!system_logs_available($conn)) {
        return;
    }

    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }

    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare('INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }

    // bind_param doesn't accept nullable ints directly; use null-safe variables
    $uid = $userId !== null ? $userId : null;
    $etype = $entityType !== null ? $entityType : null;
    $eid = $entityId !== null ? $entityId : null;
    $d = $details !== null ? $details : null;

    $stmt->bind_param('ississs', $uid, $action, $etype, $eid, $d, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();
}
