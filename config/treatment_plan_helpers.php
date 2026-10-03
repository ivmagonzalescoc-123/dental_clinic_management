<?php
/**
 * Patient treatment plans: proposed procedures (service + tooth + phase + estimate)
 * that the patient accepts or declines, and that the doctor marks completed on the
 * visit they're actually done. Completing an item adds its service to that visit's
 * "Services Performed", which is what billing is built from — the plan itself is an
 * estimate and is never billed directly.
 *
 * Each item remembers the visit it was planned on (source_appointment_id) and the
 * visit it was done on (completed_appointment_id), so every clinic record can show
 * "treatments done this visit" and "planned for next visits" separately from the
 * billed services.
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
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

if (!function_exists('treatment_plan_for_appointment')) {
    /**
     * Treatments tied to one visit, for the clinic record / history views:
     *  - done:    items completed on this visit
     *  - planned: items planned on this visit but not done on it (with their current
     *             status, so a later completion shows when it happened)
     */
    function treatment_plan_for_appointment(mysqli $conn, int $appointmentId): array
    {
        $out = ['done' => [], 'planned' => []];
        if ($appointmentId <= 0) return $out;
        treatment_plan_ensure_table($conn);
        $stmt = $conn->prepare("
            SELECT i.item_id, i.service_id, s.service_name, i.tooth_number, i.phase, i.estimated_cost,
                   i.notes, i.status, i.source_appointment_id, i.completed_appointment_id,
                   ca.appointment_date AS completed_appointment_date
            FROM patient_treatment_plan_items i
            LEFT JOIN services s ON s.service_id = i.service_id
            LEFT JOIN appointments ca ON ca.appointment_id = i.completed_appointment_id
            WHERE i.completed_appointment_id = ? OR i.source_appointment_id = ?
            ORDER BY i.phase ASC, i.item_id ASC
        ");
        $stmt->bind_param('ii', $appointmentId, $appointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $item = [
                'item_id' => (int)$row['item_id'],
                'service_name' => (string)($row['service_name'] ?? ''),
                'tooth_number' => $row['tooth_number'],
                'phase' => (int)$row['phase'],
                'phase_label' => treatment_plan_phase_label((int)$row['phase']),
                'estimated_cost' => (float)$row['estimated_cost'],
                'notes' => $row['notes'],
                'status' => (string)$row['status'],
                'completed_appointment_date' => $row['completed_appointment_date'],
            ];
            if ((int)$row['completed_appointment_id'] === $appointmentId) {
                $out['done'][] = $item;
            } else {
                $out['planned'][] = $item;
            }
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('treatment_plan_services')) {
    /** Active services for the plan's add/edit form. */
    function treatment_plan_services(mysqli $conn): array
    {
        $rows = [];
        $res = $conn->query('SELECT service_id, service_name, initial_price FROM services WHERE is_active = 1 ORDER BY service_name ASC');
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = ['service_id' => (int)$r['service_id'], 'name' => (string)$r['service_name'], 'price' => (float)$r['initial_price']];
        }
        return $rows;
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
