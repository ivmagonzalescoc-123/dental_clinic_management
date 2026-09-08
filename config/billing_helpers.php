<?php

if (!function_exists('get_clinical_setting')) {
    function get_clinical_setting($conn, $key, $default = null)
    {
        $stmt = $conn->prepare('SELECT setting_value FROM clinic_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) return $default;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row || !isset($row['setting_value'])) return $default;
        $val = $row['setting_value'];
        return is_numeric($val) ? (float)$val : $val;
    }
}

if (!function_exists('billing_table_exists')) {
    function billing_table_exists($conn, $tableName)
    {
        if (function_exists('table_exists')) {
            return table_exists($conn, $tableName);
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
        if ($safe === '') {
            return false;
        }
        $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($safe) . "'");
        return $r && $r->num_rows > 0;
    }
}

if (!function_exists('billing_line_items_columns')) {
    function billing_line_items_columns($conn)
    {
        $cols = [];
        if (!billing_table_exists($conn, 'billing_line_items')) {
            return $cols;
        }
        $r = $conn->query('SHOW COLUMNS FROM billing_line_items');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cols[$row['Field']] = true;
            }
        }
        return $cols;
    }
}

if (!function_exists('billing_migrate_line_items')) {
    function billing_migrate_line_items($conn)
    {
        if (!billing_table_exists($conn, 'billing_line_items')) {
            return;
        }

        $cols = billing_line_items_columns($conn);

        if (!isset($cols['item_id']) && isset($cols['line_id'])) {
            @$conn->query('ALTER TABLE billing_line_items CHANGE line_id item_id INT NOT NULL AUTO_INCREMENT');
            $cols = billing_line_items_columns($conn);
        }

        if (!isset($cols['billing_id'])) {
            @$conn->query('ALTER TABLE billing_line_items ADD COLUMN billing_id INT NULL AFTER item_id');
            $cols = billing_line_items_columns($conn);
        }

        if (!isset($cols['item_type'])) {
            @$conn->query("ALTER TABLE billing_line_items ADD COLUMN item_type ENUM('service','extra') NOT NULL DEFAULT 'service' AFTER amount");
        }

        if (!isset($cols['service_id'])) {
            @$conn->query('ALTER TABLE billing_line_items ADD COLUMN service_id INT NULL AFTER appointment_id');
        }
    }
}

if (!function_exists('billing_ensure_tables')) {
    function billing_ensure_tables($conn)
    {
        $conn->query("CREATE TABLE IF NOT EXISTS billing_line_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            billing_id INT NULL,
            appointment_id INT NOT NULL,
            service_id INT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            item_type ENUM('service','extra') NOT NULL DEFAULT 'service',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bli_appointment (appointment_id),
            KEY idx_bli_billing (billing_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        billing_migrate_line_items($conn);

        $conn->query("CREATE TABLE IF NOT EXISTS patient_notifications (
            patient_notification_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_patient_notifications_patient (patient_id),
            KEY idx_patient_notifications_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS billing_installments (
            installment_id INT AUTO_INCREMENT PRIMARY KEY,
            billing_id INT NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            down_payment_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            down_payment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            num_terms INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_installment_billing (billing_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS billing_installment_terms (
            term_id INT AUTO_INCREMENT PRIMARY KEY,
            installment_id INT NOT NULL,
            term_number INT NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
            paid_at DATETIME NULL,
            due_soon_reminder_sent_at DATETIME NULL,
            overdue_reminder_sent_at DATETIME NULL,
            KEY idx_bit_installment (installment_id),
            KEY idx_bit_due (due_date),
            CONSTRAINT fk_bit_installment FOREIGN KEY (installment_id)
                REFERENCES billing_installments (installment_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        billing_migrate_installment_term_reminders($conn);
        billing_migrate_installment_terms_fk($conn);

        $conn->query("CREATE TABLE IF NOT EXISTS payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            billing_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) DEFAULT 'cash',
            or_number VARCHAR(50) NOT NULL,
            notes VARCHAR(500) DEFAULT NULL,
            recorded_by INT NULL,
            paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payments_billing (billing_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS invoices (
            invoice_id INT AUTO_INCREMENT PRIMARY KEY,
            billing_id INT NOT NULL,
            invoice_number VARCHAR(30) NOT NULL,
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            issued_by INT NULL,
            UNIQUE KEY uq_invoice_billing (billing_id),
            UNIQUE KEY uq_invoice_number (invoice_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        billing_migrate_billing_columns($conn);

        $conn->query("CREATE TABLE IF NOT EXISTS clinic_settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Seed consultation fee if not present
        $checkFee = $conn->query("SELECT setting_key FROM clinic_settings WHERE setting_key = 'consultation_fee'");
        if ($checkFee && $checkFee->num_rows === 0) {
            $conn->query("INSERT INTO clinic_settings (setting_key, setting_value, description) VALUES ('consultation_fee', '500.00', 'Default consultation fee for consultation-only appointments')");
        }
    }
}

if (!function_exists('billing_senior_discount_percent')) {
    function billing_senior_discount_percent(): float
    {
        return 20.0;
    }
}

if (!function_exists('billing_pwd_discount_percent')) {
    function billing_pwd_discount_percent(): float
    {
        return 20.0;
    }
}

if (!function_exists('billing_migrate_billing_columns')) {
    function billing_migrate_billing_columns($conn)
    {
        if (!billing_table_exists($conn, 'billing')) {
            return;
        }

        $cols = [];
        $r = $conn->query('SHOW COLUMNS FROM billing');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cols[$row['Field']] = true;
            }
        }

        if (!isset($cols['amount_paid'])) {
            @$conn->query('ALTER TABLE billing ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount');
            $cols['amount_paid'] = true;
        }
        if (!isset($cols['subtotal_amount'])) {
            @$conn->query('ALTER TABLE billing ADD COLUMN subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount_paid');
        }
        if (!isset($cols['discount_type'])) {
            @$conn->query("ALTER TABLE billing ADD COLUMN discount_type ENUM('none','senior','pwd','custom') NOT NULL DEFAULT 'none' AFTER subtotal_amount");
        }
        if (!isset($cols['discount_percent'])) {
            @$conn->query('ALTER TABLE billing ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_type');
        }
        if (!isset($cols['discount_notes'])) {
            @$conn->query('ALTER TABLE billing ADD COLUMN discount_notes VARCHAR(255) DEFAULT NULL AFTER discount_percent');
        }
        if (!isset($cols['balance'])) {
            @$conn->query('ALTER TABLE billing ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount_paid');
        }
    }
}

if (!function_exists('billing_is_overdue')) {
    /**
     * A billing record is only "overdue" when it has an actual defined due date that has
     * passed — i.e. an installment plan with a pending term whose due_date < today.
     * A bill with no installment plan has no due date at all, so it is never overdue
     * regardless of how long it has been unpaid (that's just "pending"/"unpaid").
     */
    function billing_is_overdue(mysqli $conn, int $billingId, string $status, float $balance): bool
    {
        if (strtolower($status) === 'paid' || $balance <= 0) {
            return false;
        }

        $instChk = $conn->prepare('SELECT installment_id FROM billing_installments WHERE billing_id = ? LIMIT 1');
        if (!$instChk) {
            return false;
        }
        $instChk->bind_param('i', $billingId);
        $instChk->execute();
        $instRow = $instChk->get_result()->fetch_assoc();
        $instChk->close();

        if (!$instRow) {
            return false;
        }

        $installmentId = (int)$instRow['installment_id'];
        $termChk = $conn->prepare("SELECT term_id FROM billing_installment_terms WHERE installment_id = ? AND status IN ('pending','partial') AND due_date < CURDATE() LIMIT 1");
        if (!$termChk) {
            return false;
        }
        $termChk->bind_param('i', $installmentId);
        $termChk->execute();
        $termRow = $termChk->get_result()->fetch_assoc();
        $termChk->close();

        return (bool)$termRow;
    }
}

if (!function_exists('billing_get_patient_outstanding_installments')) {
    /**
     * All pending/partial installment terms across every billing this patient has, regardless
     * of which appointment/visit they're currently checking in for. Installment terms are due
     * on fixed calendar dates independent of whether the patient actually shows up each month —
     * a missed visit does not cancel or merge that term, it just sits there (and goes overdue).
     *
     * This exists so the secretary can be shown "this patient still owes for an earlier missed
     * term" proactively at the point of contact (opening Create Record / check-in), instead of
     * relying on someone remembering to go check the Billing screen separately. Payments are
     * applied oldest-term-first (see billing_sync_installment_terms), so collecting only "this
     * visit's" amount would silently pay off the old term instead of the current one.
     *
     * @return array{total_outstanding: float, has_overdue: bool, terms: array}
     */
    function billing_get_patient_outstanding_installments(mysqli $conn, int $patientId): array
    {
        $result = ['total_outstanding' => 0.0, 'has_overdue' => false, 'terms' => []];
        if ($patientId <= 0 || !billing_table_exists($conn, 'billing_installment_terms')) {
            return $result;
        }

        $stmt = $conn->prepare("
            SELECT bit.term_id, bit.term_number, bit.due_date, bit.amount, bit.paid_amount, bit.status,
                   b.billing_id, a.appointment_id
            FROM billing_installment_terms bit
            INNER JOIN billing_installments bi ON bi.installment_id = bit.installment_id
            INNER JOIN billing b ON b.billing_id = bi.billing_id
            INNER JOIN appointments a ON a.appointment_id = b.appointment_id
            WHERE a.patient_id = ?
              AND bit.status IN ('pending','partial')
            ORDER BY bit.due_date ASC, bit.term_number ASC
        ");
        if (!$stmt) {
            return $result;
        }
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $remaining = round((float)$row['amount'] - (float)$row['paid_amount'], 2);
            if ($remaining <= 0) {
                continue;
            }
            $overdue = strtotime((string)$row['due_date']) < strtotime(date('Y-m-d'));
            $result['terms'][] = [
                'billing_id' => (int)$row['billing_id'],
                'appointment_id' => (int)$row['appointment_id'],
                'term_number' => (int)$row['term_number'],
                'due_date' => (string)$row['due_date'],
                'remaining_amount' => $remaining,
                'status' => (string)$row['status'],
                'overdue' => $overdue,
            ];
            $result['total_outstanding'] = round($result['total_outstanding'] + $remaining, 2);
            if ($overdue) {
                $result['has_overdue'] = true;
            }
        }
        $stmt->close();

        return $result;
    }
}

if (!function_exists('billing_get_installment_plan_for_followup')) {
    /**
     * Resolve whether a given appointment is a follow-up visit (rescheduled_from) of an
     * appointment whose billing has an active installment plan — e.g. one of the 12 monthly
     * braces-adjustment visits auto-booked from the original treatment visit.
     *
     * This exists so create_secretary_record.php can avoid auto-billing a fresh Consultation
     * Fee for a visit that's actually already covered by an existing installment plan — without
     * this check, every monthly adjustment visit with no doctor-selected service would silently
     * generate its own unrelated ₱[consultation fee] charge instead of the real installment
     * amount, which lives on a completely different billing_id tied to the original visit.
     *
     * @return array{source_appointment_id:int, billing_id:int, installment_id:int, pending_terms:array}|null
     */
    function billing_get_installment_plan_for_followup(mysqli $conn, int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            return null;
        }

        $stmt = $conn->prepare('SELECT rescheduled_from FROM appointments WHERE appointment_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $sourceId = (int)($row['rescheduled_from'] ?? 0);
        if ($sourceId <= 0) {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT bi.installment_id, b.billing_id
            FROM billing b
            INNER JOIN billing_installments bi ON bi.billing_id = b.billing_id
            WHERE b.appointment_id = ?
            LIMIT 1
        ');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $planRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$planRow) {
            return null;
        }

        $installmentId = (int)$planRow['installment_id'];
        $billingId = (int)$planRow['billing_id'];

        $terms = [];
        $tStmt = $conn->prepare("
            SELECT term_id, term_number, due_date, amount, paid_amount, status
            FROM billing_installment_terms
            WHERE installment_id = ? AND status IN ('pending','partial')
            ORDER BY due_date ASC
        ");
        if ($tStmt) {
            $tStmt->bind_param('i', $installmentId);
            $tStmt->execute();
            $res = $tStmt->get_result();
            while ($t = $res->fetch_assoc()) {
                $terms[] = [
                    'term_number' => (int)$t['term_number'],
                    'due_date' => (string)$t['due_date'],
                    'remaining_amount' => round((float)$t['amount'] - (float)$t['paid_amount'], 2),
                    'status' => (string)$t['status'],
                ];
            }
            $tStmt->close();
        }

        return [
            'source_appointment_id' => $sourceId,
            'billing_id' => $billingId,
            'installment_id' => $installmentId,
            'pending_terms' => $terms,
        ];
    }
}

if (!function_exists('billing_normalize_discount_type')) {
    function billing_normalize_discount_type($type): string
    {
        $type = strtolower(trim((string)$type));
        return in_array($type, ['senior', 'pwd', 'custom'], true) ? $type : 'none';
    }
}

if (!function_exists('billing_apply_discount')) {
    /**
     * @return array{subtotal_amount:float,discount_type:string,discount_percent:float,discount_amount:float,total_amount:float}
     */
    function billing_apply_discount($subtotal, $discountType = 'none', $customPercent = null): array
    {
        $subtotal = round(max(0, (float)$subtotal), 2);
        $type = billing_normalize_discount_type($discountType);
        $percent = 0.0;

        if ($type === 'senior') {
            $percent = billing_senior_discount_percent();
        } elseif ($type === 'pwd') {
            $percent = billing_pwd_discount_percent();
        } elseif ($type === 'custom') {
            $percent = max(0, min(100, (float)$customPercent));
        }

        $discountAmount = round($subtotal * ($percent / 100), 2);
        $total = round(max(0, $subtotal - $discountAmount), 2);

        return [
            'subtotal_amount' => $subtotal,
            'discount_type' => $type,
            'discount_percent' => round($percent, 2),
            'discount_amount' => $discountAmount,
            'total_amount' => $total,
        ];
    }
}

if (!function_exists('billing_discount_label')) {
    function billing_discount_label($discountType, $discountPercent = 0.0): string
    {
        $type = billing_normalize_discount_type($discountType);
        $percent = round((float)$discountPercent, 2);

        if ($type === 'senior') {
            return 'Senior Citizen Discount (' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%)';
        }
        if ($type === 'pwd') {
            return 'PWD Discount (' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%)';
        }
        if ($type === 'custom') {
            return 'Custom Discount (' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%)';
        }

        return 'Discount';
    }
}

if (!function_exists('billing_get_amount_paid')) {
    function billing_get_amount_paid($conn, $billingId)
    {
        if (!billing_table_exists($conn, 'payments')) {
            return 0.0;
        }
        $stmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE billing_id = ?');
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('i', $billingId);
        $stmt->execute();
        $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
        $stmt->close();
        return $paid;
    }
}

if (!function_exists('billing_sync_from_payments')) {
    function billing_sync_from_payments($conn, $billingId, $processedBy = null)
    {
        $billingId = (int)$billingId;
        if ($billingId <= 0) {
            return false;
        }

        billing_migrate_billing_columns($conn);

        $stmt = $conn->prepare('SELECT total_amount FROM billing WHERE billing_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $billingId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }

        $total = (float)($row['total_amount'] ?? 0);
        $paid = billing_get_amount_paid($conn, $billingId);
        $balance = max(0, round($total - $paid, 2));

        $status = 'unpaid';
        if ($balance <= 0 && $total > 0) {
            $status = 'paid';
        } elseif ($paid > 0 && $balance > 0) {
            $status = 'partial';
        }

        $upd = $conn->prepare('UPDATE billing SET amount_paid = ?, balance = ?, status = ?, processed_by = ? WHERE billing_id = ?');
        if (!$upd) {
            return false;
        }
        $upd->bind_param('ddsii', $paid, $balance, $status, $processedBy, $billingId);
        $ok = $upd->execute();
        $upd->close();

        if ($ok) {
            billing_sync_installment_terms($conn, $billingId);
        }

        return (bool)$ok;
    }
}

if (!function_exists('billing_sync_installment_terms')) {
    /**
     * Align installment term statuses with recorded payments (cumulative by term order).
     */
    function billing_sync_installment_terms($conn, $billingId)
    {
        $billingId = (int)$billingId;
        if ($billingId <= 0 || !billing_table_exists($conn, 'billing_installments')) {
            return false;
        }

        $inst = $conn->prepare('SELECT installment_id FROM billing_installments WHERE billing_id = ? LIMIT 1');
        if (!$inst) {
            return false;
        }
        $inst->bind_param('i', $billingId);
        $inst->execute();
        $instRow = $inst->get_result()->fetch_assoc();
        $inst->close();
        if (!$instRow) {
            return true;
        }

        $installmentId = (int)$instRow['installment_id'];
        $remainingPaid = billing_get_amount_paid($conn, $billingId);

        $tstmt = $conn->prepare('SELECT term_id, amount FROM billing_installment_terms WHERE installment_id = ? ORDER BY term_number ASC');
        if (!$tstmt) {
            return false;
        }
        $tstmt->bind_param('i', $installmentId);
        $tstmt->execute();
        $terms = $tstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $tstmt->close();

        // Allocate the running total paid across terms in order. Each term keeps whatever
        // portion of the payments it actually absorbs (paid_amount), so a term that received
        // a partial payment shows a reduced remaining balance instead of appearing as if
        // nothing had been paid toward it at all.
        $markPaid = $conn->prepare("UPDATE billing_installment_terms SET status = 'paid', paid_amount = ?, paid_at = COALESCE(paid_at, NOW()) WHERE term_id = ?");
        $markPartial = $conn->prepare("UPDATE billing_installment_terms SET status = 'partial', paid_amount = ?, paid_at = NULL WHERE term_id = ?");
        $markPending = $conn->prepare("UPDATE billing_installment_terms SET status = 'pending', paid_amount = 0, paid_at = NULL WHERE term_id = ?");
        if (!$markPaid || !$markPartial || !$markPending) {
            return false;
        }

        foreach ($terms as $term) {
            $termId = (int)$term['term_id'];
            $amount = (float)($term['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $applied = max(0, min($remainingPaid, $amount));
            $remainingPaid = round($remainingPaid - $applied, 2);

            if ($applied + 0.001 >= $amount) {
                $markPaid->bind_param('di', $amount, $termId);
                $markPaid->execute();
            } elseif ($applied > 0) {
                $markPartial->bind_param('di', $applied, $termId);
                $markPartial->execute();
            } else {
                $markPending->bind_param('i', $termId);
                $markPending->execute();
            }
        }

        $markPaid->close();
        $markPartial->close();
        $markPending->close();

        return true;
    }
}

if (!function_exists('billing_sync_all')) {
    function billing_sync_all($conn)
    {
        if (!billing_table_exists($conn, 'billing')) {
            return;
        }
        $res = $conn->query('SELECT billing_id FROM billing');
        if (!$res) {
            return;
        }
        while ($row = $res->fetch_assoc()) {
            billing_sync_from_payments($conn, (int)$row['billing_id']);
        }
    }
}

if (!function_exists('billing_sum_appointment_services')) {
    function billing_sum_appointment_services($conn, $appointmentId)
    {
        $total = 0.0;
        $items = [];
        $stmt = $conn->prepare('
            SELECT ss.service_id, s.service_name, s.initial_price
            FROM selected_services ss
            INNER JOIN services s ON s.service_id = ss.service_id
            WHERE ss.appointment_id = ?
        ');
        if (!$stmt) {
            return [$total, $items];
        }
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $price = (float)($row['initial_price'] ?? 0);
            $total += $price;
            $items[] = [
                'service_id' => (int)$row['service_id'],
                'description' => (string)$row['service_name'],
                'amount' => $price,
                'item_type' => 'service',
            ];
        }
        $stmt->close();
        return [$total, $items];
    }
}

if (!function_exists('billing_create_or_update')) {
    function billing_create_or_update($conn, $appointmentId, $patientId, $subtotalAmount, $initialStatus = 'unpaid', array $discountOptions = [])
    {
        billing_migrate_billing_columns($conn);

        // billing.user_id has an FK to users(user_id) — it records which staff member
        // (the assigned doctor) the billing is attributed to, NOT the patient.
        // $patientId doesn't exist in the users table, so binding it here fails the FK
        // constraint. Look up the appointment's actual assigned doctor instead.
        $billingUserId = null;
        $docLookup = $conn->prepare('SELECT user_id FROM appointments WHERE appointment_id = ? LIMIT 1');
        if ($docLookup) {
            $docLookup->bind_param('i', $appointmentId);
            $docLookup->execute();
            $docRow = $docLookup->get_result()->fetch_assoc();
            $docLookup->close();
            $billingUserId = $docRow && !empty($docRow['user_id']) ? (int)$docRow['user_id'] : null;
        }

        $discountType = billing_normalize_discount_type($discountOptions['discount_type'] ?? 'none');
        $customPercent = $discountOptions['discount_percent'] ?? null;
        $discountNotes = trim((string)($discountOptions['discount_notes'] ?? ''));

        $totals = billing_apply_discount($subtotalAmount, $discountType, $customPercent);
        $subtotalAmount = $totals['subtotal_amount'];
        $discountPercent = $totals['discount_percent'];
        $discountAmount = $totals['discount_amount'];
        $totalAmount = $totals['total_amount'];

        $billingId = 0;
        $check = $conn->prepare('SELECT billing_id, status FROM billing WHERE appointment_id = ? LIMIT 1');
        if ($check) {
            $check->bind_param('i', $appointmentId);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if ($row) {
                $billingId = (int)$row['billing_id'];
                $paid = billing_get_amount_paid($conn, $billingId);
                $balance = max(0, round($totalAmount - $paid, 2));
                $upd = $conn->prepare('
                    UPDATE billing
                    SET total_amount = ?, user_id = ?, amount_paid = ?, balance = ?,
                        subtotal_amount = ?, discount_type = ?, discount_percent = ?, discount_notes = ?
                    WHERE billing_id = ?
                ');
                if ($upd) {
                    $upd->bind_param(
                        'didddsdsi',
                        $totalAmount,
                        $billingUserId,
                        $paid,
                        $balance,
                        $subtotalAmount,
                        $discountType,
                        $discountPercent,
                        $discountNotes,
                        $billingId
                    );
                    $upd->execute();
                    $upd->close();
                }
                billing_sync_from_payments($conn, $billingId);
                return $billingId;
            }
        }

        $zeroPaid = 0.0;
        $initialBalance = max(0, round($totalAmount - $zeroPaid, 2));
        $ins = $conn->prepare('
            INSERT INTO billing (
                appointment_id, user_id, total_amount, amount_paid, balance, status,
                subtotal_amount, discount_type, discount_percent, discount_notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        if (!$ins) {
            return 0;
        }
        $ins->bind_param(
            'iidddsdsds',
            $appointmentId,
            $billingUserId,
            $totalAmount,
            $zeroPaid,
            $initialBalance,
            $initialStatus,
            $subtotalAmount,
            $discountType,
            $discountPercent,
            $discountNotes
        );
        $ins->execute();
        $billingId = (int)$conn->insert_id;
        $ins->close();
        billing_sync_from_payments($conn, $billingId);
        return $billingId;
    }
}

if (!function_exists('billing_save_line_items')) {
    function billing_save_line_items($conn, $appointmentId, $billingId, array $items)
    {
        $del = $conn->prepare('DELETE FROM billing_line_items WHERE appointment_id = ?');
        if ($del) {
            $del->bind_param('i', $appointmentId);
            $del->execute();
            $del->close();
        }

        if (empty($items)) {
            return;
        }

        $ins = $conn->prepare('
            INSERT INTO billing_line_items (billing_id, appointment_id, service_id, description, amount, item_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if (!$ins) {
            return;
        }

        foreach ($items as $item) {
            $desc = trim((string)($item['description'] ?? ''));
            $amt = (float)($item['amount'] ?? 0);
            $serviceId = isset($item['service_id']) ? (int)$item['service_id'] : null;
            $type = ($item['item_type'] ?? 'extra') === 'service' ? 'service' : 'extra';
            if ($desc === '' && $amt <= 0) {
                continue;
            }
            if ($desc === '') {
                $desc = 'Charge';
            }
            $ins->bind_param('iiisds', $billingId, $appointmentId, $serviceId, $desc, $amt, $type);
            $ins->execute();
        }
        $ins->close();
    }
}

if (!function_exists('billing_create_installment_plan')) {
    function billing_create_installment_plan($conn, $billingId, $totalAmount, $numTerms, $downPaymentPercent = 50.0)
    {
        $billingId = (int)$billingId;
        $numTerms = max(1, (int)$numTerms);
        $downPaymentPercent = max(0, min(100, (float)$downPaymentPercent));
        $downPaymentAmount = round($totalAmount * ($downPaymentPercent / 100), 2);
        $remaining = max(0, round($totalAmount - $downPaymentAmount, 2));
        $termAmount = $numTerms > 0 ? round($remaining / $numTerms, 2) : 0.0;

        // Remove any prior plan (and its term rows) for this billing before creating a fresh one,
        // so regenerating a plan never leaves orphaned terms behind (which used to duplicate
        // the schedule shown on the invoice).
        $oldInstallmentId = null;
        $find = $conn->prepare('SELECT installment_id FROM billing_installments WHERE billing_id = ?');
        if ($find) {
            $find->bind_param('i', $billingId);
            $find->execute();
            $res = $find->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $oldInstallmentId = (int)$row['installment_id'];
            }
            $find->close();
        }

        // This function may be called from inside a caller's own transaction (e.g.
        // create_secretary_record.php) or stand-alone. Calling begin_transaction() while one
        // is already open would implicitly commit the caller's outer transaction early (MySQL
        // has no true nested transactions), silently breaking its atomicity. Detect which case
        // we're in and use a SAVEPOINT for the nested case so this is safe either way.
        $inTransactionResult = $conn->query('SELECT @@in_transaction AS t');
        $alreadyInTransaction = $inTransactionResult && (int)($inTransactionResult->fetch_assoc()['t'] ?? 0) === 1;

        if ($alreadyInTransaction) {
            $conn->query('SAVEPOINT sp_installment_plan');
        } else {
            $conn->begin_transaction();
        }

        try {
            if ($oldInstallmentId !== null) {
                $delTerms = $conn->prepare('DELETE FROM billing_installment_terms WHERE installment_id = ?');
                if ($delTerms) {
                    $delTerms->bind_param('i', $oldInstallmentId);
                    $delTerms->execute();
                    $delTerms->close();
                }
            }

            $del = $conn->prepare('DELETE FROM billing_installments WHERE billing_id = ?');
            if ($del) {
                $del->bind_param('i', $billingId);
                $del->execute();
                $del->close();
            }

            $ins = $conn->prepare('
                INSERT INTO billing_installments (billing_id, total_amount, down_payment_percent, down_payment_amount, num_terms)
                VALUES (?, ?, ?, ?, ?)
            ');
            if (!$ins) {
                throw new Exception('Failed to prepare billing_installments insert');
            }
            $ins->bind_param('idddi', $billingId, $totalAmount, $downPaymentPercent, $downPaymentAmount, $numTerms);
            if (!$ins->execute()) {
                $ins->close();
                throw new Exception('Failed to insert billing_installments row');
            }
            $installmentId = (int)$conn->insert_id;
            $ins->close();

            $termIns = $conn->prepare('
                INSERT INTO billing_installment_terms (installment_id, term_number, due_date, amount, status)
                VALUES (?, ?, ?, ?, ?)
            ');
            if (!$termIns) {
                throw new Exception('Failed to prepare billing_installment_terms insert');
            }

            $pending = 'pending';
            $termNumber = 0;
            $dueDate = date('Y-m-d');
            $termIns->bind_param('iisds', $installmentId, $termNumber, $dueDate, $downPaymentAmount, $pending);
            if (!$termIns->execute()) {
                $termIns->close();
                throw new Exception('Failed to insert down payment term');
            }

            $distributed = $downPaymentAmount;
            for ($i = 1; $i <= $numTerms; $i++) {
                $amount = ($i === $numTerms)
                    ? round($remaining - ($termAmount * ($numTerms - 1)), 2)
                    : $termAmount;
                $distributed += $amount;
                $due = date('Y-m-d', strtotime('+' . $i . ' month'));
                $termIns->bind_param('iisds', $installmentId, $i, $due, $amount, $pending);
                if (!$termIns->execute()) {
                    $termIns->close();
                    throw new Exception('Failed to insert installment term ' . $i);
                }
            }
            $termIns->close();

            if ($alreadyInTransaction) {
                $conn->query('RELEASE SAVEPOINT sp_installment_plan');
            } else {
                $conn->commit();
            }
            return $installmentId;
        } catch (Exception $e) {
            if ($alreadyInTransaction) {
                $conn->query('ROLLBACK TO SAVEPOINT sp_installment_plan');
            } else {
                $conn->rollback();
            }
            error_log('billing_create_installment_plan failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('billing_migrate_installment_terms_fk')) {
    function billing_migrate_installment_terms_fk($conn)
    {
        if (!billing_table_exists($conn, 'billing_installment_terms') || !billing_table_exists($conn, 'billing_installments')) {
            return;
        }

        $exists = $conn->query("
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'billing_installment_terms'
              AND CONSTRAINT_NAME = 'fk_bit_installment'
        ");
        if ($exists && $exists->num_rows > 0) {
            return;
        }

        // Only add the safety-net FK once any orphaned term rows (installment_id with no
        // matching parent) have been cleared; otherwise the ALTER would fail.
        @$conn->query('
            DELETE bit FROM billing_installment_terms bit
            LEFT JOIN billing_installments bi ON bi.installment_id = bit.installment_id
            WHERE bi.installment_id IS NULL
        ');

        @$conn->query('
            ALTER TABLE billing_installment_terms
            ADD CONSTRAINT fk_bit_installment FOREIGN KEY (installment_id)
                REFERENCES billing_installments (installment_id) ON DELETE CASCADE
        ');
    }
}

if (!function_exists('billing_migrate_installment_term_reminders')) {
    function billing_migrate_installment_term_reminders($conn)
    {
        if (!billing_table_exists($conn, 'billing_installment_terms')) {
            return;
        }

        $cols = [];
        $r = $conn->query('SHOW COLUMNS FROM billing_installment_terms');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cols[$row['Field']] = true;
            }
        }

        if (!isset($cols['due_soon_reminder_sent_at'])) {
            @$conn->query('ALTER TABLE billing_installment_terms ADD COLUMN due_soon_reminder_sent_at DATETIME NULL AFTER paid_at');
        }
        if (!isset($cols['overdue_reminder_sent_at'])) {
            @$conn->query('ALTER TABLE billing_installment_terms ADD COLUMN overdue_reminder_sent_at DATETIME NULL AFTER due_soon_reminder_sent_at');
        }
        if (!isset($cols['paid_amount'])) {
            @$conn->query('ALTER TABLE billing_installment_terms ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount');
            // Backfill: anything already marked 'paid' is fully covered by its own amount.
            @$conn->query("UPDATE billing_installment_terms SET paid_amount = amount WHERE status = 'paid'");
        }
        // Widen the status enum to allow a 'partial' state for terms with a partial payment
        // applied (previously a term with any payment less than its full amount showed as
        // plain 'pending' with no credit for what had already been paid toward it).
        @$conn->query("ALTER TABLE billing_installment_terms MODIFY COLUMN status ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending'");
    }
}

if (!function_exists('billing_send_installment_reminders')) {
    /**
     * Email reminders for installment terms:
     * - due tomorrow (day before due date)
     * - overdue (day after due date)
     */
    function billing_send_installment_reminders($conn): int
    {
        billing_ensure_tables($conn);

        if (!function_exists('reminder_send_email')) {
            include_once __DIR__ . '/reminders.php';
        }

        $sentCount = 0;
        $queries = [
            [
                'sql' => "SELECT bit.term_id, bit.term_number, bit.due_date, (bit.amount - bit.paid_amount) AS amount,
                                 p.email, p.first_name, p.last_name, b.billing_id
                          FROM billing_installment_terms bit
                          INNER JOIN billing_installments bi ON bi.installment_id = bit.installment_id
                          INNER JOIN billing b ON b.billing_id = bi.billing_id
                          INNER JOIN appointments a ON a.appointment_id = b.appointment_id
                          INNER JOIN patients p ON p.patient_id = a.patient_id
                          WHERE bit.status IN ('pending','partial')
                            AND bit.term_number > 0
                            AND bit.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                            AND bit.due_soon_reminder_sent_at IS NULL",
                'template' => 'due_soon',
                'mark_column' => 'due_soon_reminder_sent_at',
            ],
            [
                'sql' => "SELECT bit.term_id, bit.term_number, bit.due_date, (bit.amount - bit.paid_amount) AS amount,
                                 p.email, p.first_name, p.last_name, b.billing_id
                          FROM billing_installment_terms bit
                          INNER JOIN billing_installments bi ON bi.installment_id = bit.installment_id
                          INNER JOIN billing b ON b.billing_id = bi.billing_id
                          INNER JOIN appointments a ON a.appointment_id = b.appointment_id
                          INNER JOIN patients p ON p.patient_id = a.patient_id
                          WHERE bit.status IN ('pending','partial')
                            AND bit.term_number > 0
                            AND bit.due_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            AND bit.overdue_reminder_sent_at IS NULL",
                'template' => 'overdue',
                'mark_column' => 'overdue_reminder_sent_at',
            ],
        ];

        foreach ($queries as $job) {
            $res = $conn->query($job['sql']);
            if (!$res) {
                error_log('billing_send_installment_reminders query failed: ' . $conn->error);
                continue;
            }

            while ($row = $res->fetch_assoc()) {
                $email = trim((string)($row['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $patientName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                if ($patientName === '') {
                    $patientName = 'Patient';
                }

                $dueDate = (string)($row['due_date'] ?? '');
                $amount = number_format((float)($row['amount'] ?? 0), 2);
                $termNo = (int)($row['term_number'] ?? 0);
                $billingId = (int)($row['billing_id'] ?? 0);

                if ($job['template'] === 'due_soon') {
                    $subject = 'Installment payment due tomorrow';
                    $message = "Hello {$patientName},\n\nThis is a reminder that installment term #{$termNo} for billing #{$billingId} is due tomorrow ({$dueDate}). Amount due: PHP {$amount}.\n\nPlease contact the clinic if you need assistance.\n\nAzucena's Dental Clinic";
                } else {
                    $subject = 'Installment payment overdue';
                    $message = "Hello {$patientName},\n\nInstallment term #{$termNo} for billing #{$billingId} was due on {$dueDate} and is now overdue. Amount due: PHP {$amount}.\n\nPlease contact the clinic to settle your balance.\n\nAzucena's Dental Clinic";
                }

                if (!reminder_send_email($email, $subject, $message)) {
                    error_log('billing_send_installment_reminders: email failed for term_id=' . (int)$row['term_id']);
                    continue;
                }

                $termId = (int)$row['term_id'];
                $markCol = $job['mark_column'];
                $markSql = "UPDATE billing_installment_terms SET {$markCol} = NOW() WHERE term_id = ? LIMIT 1";
                $markStmt = $conn->prepare($markSql);
                if ($markStmt) {
                    $markStmt->bind_param('i', $termId);
                    $markStmt->execute();
                    $markStmt->close();
                }

                $sentCount++;
            }
        }

        return $sentCount;
    }
}

if (!function_exists('billing_create_invoice')) {
    function billing_create_invoice($conn, $billingId, $issuedBy = null)
    {
        $billingId = (int)$billingId;
        $invoiceNumber = 'INV-' . str_pad((string)$billingId, 6, '0', STR_PAD_LEFT);

        $check = $conn->prepare('SELECT invoice_id FROM invoices WHERE billing_id = ? LIMIT 1');
        if ($check) {
            $check->bind_param('i', $billingId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();
            if ($existing) {
                return (int)$existing['invoice_id'];
            }
        }

        $ins = $conn->prepare('INSERT INTO invoices (billing_id, invoice_number, issued_by) VALUES (?, ?, ?)');
        if (!$ins) {
            return 0;
        }
        $ins->bind_param('isi', $billingId, $invoiceNumber, $issuedBy);
        $ins->execute();
        $invoiceId = (int)$conn->insert_id;
        $ins->close();
        return $invoiceId;
    }
}

if (!function_exists('billing_record_payment')) {
    function billing_record_payment($conn, $billingId, $amount, $method, $orNumber, $notes, $recordedBy)
    {
        $billingId = (int)$billingId;
        $amount = (float)$amount;
        $orNumber = trim((string)$orNumber);
        if ($billingId <= 0 || $amount <= 0 || $orNumber === '') {
            return false;
        }

        $ins = $conn->prepare('INSERT INTO payments (billing_id, amount, payment_method, or_number, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$ins) {
            return false;
        }
        $ins->bind_param('idsssi', $billingId, $amount, $method, $orNumber, $notes, $recordedBy);
        $ok = $ins->execute();
        $ins->close();
        if (!$ok) {
            return false;
        }

        return billing_sync_from_payments($conn, $billingId, $recordedBy);
    }
}
