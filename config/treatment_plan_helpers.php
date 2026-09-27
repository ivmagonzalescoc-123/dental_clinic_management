<?php
/**
 * Patient treatment plans: proposed procedures (service + tooth + phase + estimate)
 * that the patient accepts or declines, and that the doctor marks completed on the
 * visit they're actually done. Completing an item adds its service to that visit's
 * "Services Performed", which is what billing is built from — the plan itself is an
 * estimate and is never billed directly.
 *
 * Deliberately a separate table from the legacy `treatment_plans`: that one is written
 * by the secretary record flow to store the follow-up interval/visit-count JSON, and
 * mixing plan items into it would break follow-up auto-booking.
 */

if (!function_exists('treatment_plan_ensure_table')) {
    function treatment_plan_ensure_table(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $conn->query("
            CREATE TABLE IF NOT EXISTS patient_treatment_plan_items (
                item_id INT AUTO_INCREMENT PRIMARY KEY,
                patient_id INT NOT NULL,
                service_id INT NOT NULL,
                tooth_number VARCHAR(4) NULL,
                phase TINYINT NOT NULL DEFAULT 1,
                estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                notes VARCHAR(255) NULL,
                status ENUM('proposed','accepted','completed','declined') NOT NULL DEFAULT 'proposed',
                source_appointment_id INT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_at DATETIME NULL,
                completed_appointment_id INT NULL,
                completed_at DATETIME NULL,
                updated_at DATETIME NULL,
                INDEX idx_tp_patient (patient_id, status),
                INDEX idx_tp_completed_appt (completed_appointment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    }
}

if (!function_exists('treatment_plan_phase_label')) {
    function treatment_plan_phase_label(int $phase): string
    {
        switch ($phase) {
            case 1: return 'Phase 1 — Urgent';
            case 2: return 'Phase 2 — Restorative';
            case 3: return 'Phase 3 — Prosthetic / Maintenance';
            default: return 'Phase ' . $phase;
        }
    }
}

if (!function_exists('treatment_plan_valid_tooth')) {
    /** FDI notation: permanent 11-48, primary 51-85. Empty means whole-mouth (e.g. cleaning). */
    function treatment_plan_valid_tooth(string $tooth): bool
    {
        if ($tooth === '') return true;
        if (!preg_match('/^([1-8])([1-8])$/', $tooth, $m)) return false;
        $quadrant = (int)$m[1];
        $pos = (int)$m[2];
        return $quadrant <= 4 ? true : $pos <= 5;
    }
}

if (!function_exists('treatment_plan_list')) {
    function treatment_plan_list(mysqli $conn, int $patientId): array
    {
        treatment_plan_ensure_table($conn);
        $stmt = $conn->prepare("
            SELECT i.item_id, i.patient_id, i.service_id, s.service_name, i.tooth_number, i.phase,
                   i.estimated_cost, i.notes, i.status, i.source_appointment_id, i.created_at,
                   i.accepted_at, i.completed_appointment_id, i.completed_at,
                   ca.appointment_date AS completed_appointment_date
            FROM patient_treatment_plan_items i
            LEFT JOIN services s ON s.service_id = i.service_id
            LEFT JOIN appointments ca ON ca.appointment_id = i.completed_appointment_id
            WHERE i.patient_id = ?
            ORDER BY i.phase ASC, FIELD(i.status, 'accepted', 'proposed', 'completed', 'declined'), i.item_id ASC
        ");
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['item_id'] = (int)$row['item_id'];
            $row['service_id'] = (int)$row['service_id'];
            $row['phase'] = (int)$row['phase'];
            $row['estimated_cost'] = (float)$row['estimated_cost'];
            $row['completed_appointment_id'] = $row['completed_appointment_id'] !== null ? (int)$row['completed_appointment_id'] : null;
            $items[] = $row;
        }
        $stmt->close();
        return $items;
    }
}

if (!function_exists('treatment_plan_totals')) {
    /** Declined items are excluded from every total — they're not part of the plan anymore. */
    function treatment_plan_totals(array $items): array
    {
        $t = ['plan' => 0.0, 'accepted' => 0.0, 'completed' => 0.0, 'remaining' => 0.0];
        foreach ($items as $i) {
            $cost = (float)$i['estimated_cost'];
            if ($i['status'] === 'declined') continue;
            $t['plan'] += $cost;
            if ($i['status'] === 'completed') $t['completed'] += $cost;
            if ($i['status'] === 'accepted') $t['accepted'] += $cost;
            if ($i['status'] !== 'completed') $t['remaining'] += $cost;
        }
        return $t;
    }
}
