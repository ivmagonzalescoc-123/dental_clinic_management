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
            KEY idx_bit_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        billing_migrate_installment_term_reminders($conn);

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
        $termChk = $conn->prepare("SELECT term_id FROM billing_installment_terms WHERE installment_id = ? AND status = 'pending' AND due_date < CURDATE() LIMIT 1");
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

        $markPaid = $conn->prepare("UPDATE billing_installment_terms SET status = 'paid', paid_at = COALESCE(paid_at, NOW()) WHERE term_id = ?");
        $markPending = $conn->prepare("UPDATE billing_installment_terms SET status = 'pending', paid_at = NULL WHERE term_id = ?");
        if (!$markPaid || !$markPending) {
            return false;
        }

        foreach ($terms as $term) {
            $termId = (int)$term['term_id'];
            $amount = (float)($term['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            if ($remainingPaid + 0.001 >= $amount) {
                $markPaid->bind_param('i', $termId);
                $markPaid->execute();
                $remainingPaid = round($remainingPaid - $amount, 2);
            } else {
                $markPending->bind_param('i', $termId);
                $markPending->execute();
            }
        }

        $markPaid->close();
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
                        $patientId,
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
            $patientId,
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
            return false;
        }
        $ins->bind_param('idddi', $billingId, $totalAmount, $downPaymentPercent, $downPaymentAmount, $numTerms);
        $ins->execute();
        $installmentId = (int)$conn->insert_id;
        $ins->close();

        $termIns = $conn->prepare('
            INSERT INTO billing_installment_terms (installment_id, term_number, due_date, amount, status)
            VALUES (?, ?, ?, ?, ?)
        ');
        if (!$termIns) {
            return false;
        }

        $pending = 'pending';
        $termNumber = 0;
        $dueDate = date('Y-m-d');
        $termIns->bind_param('iisds', $installmentId, $termNumber, $dueDate, $downPaymentAmount, $pending);
        $termIns->execute();

        $distributed = $downPaymentAmount;
        for ($i = 1; $i <= $numTerms; $i++) {
            $amount = ($i === $numTerms)
                ? round($remaining - ($termAmount * ($numTerms - 1)), 2)
                : $termAmount;
            $distributed += $amount;
            $due = date('Y-m-d', strtotime('+' . $i . ' month'));
            $termIns->bind_param('iisds', $installmentId, $i, $due, $amount, $pending);
            $termIns->execute();
        }
        $termIns->close();

        return $installmentId;
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
                'sql' => "SELECT bit.term_id, bit.term_number, bit.due_date, bit.amount,
                                 p.email, p.first_name, p.last_name, b.billing_id
                          FROM billing_installment_terms bit
                          INNER JOIN billing_installments bi ON bi.installment_id = bit.installment_id
                          INNER JOIN billing b ON b.billing_id = bi.billing_id
                          INNER JOIN appointments a ON a.appointment_id = b.appointment_id
                          INNER JOIN patients p ON p.patient_id = a.patient_id
                          WHERE bit.status = 'pending'
                            AND bit.term_number > 0
                            AND bit.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                            AND bit.due_soon_reminder_sent_at IS NULL",
                'template' => 'due_soon',
                'mark_column' => 'due_soon_reminder_sent_at',
            ],
            [
                'sql' => "SELECT bit.term_id, bit.term_number, bit.due_date, bit.amount,
                                 p.email, p.first_name, p.last_name, b.billing_id
                          FROM billing_installment_terms bit
                          INNER JOIN billing_installments bi ON bi.installment_id = bit.installment_id
                          INNER JOIN billing b ON b.billing_id = bi.billing_id
                          INNER JOIN appointments a ON a.appointment_id = b.appointment_id
                          INNER JOIN patients p ON p.patient_id = a.patient_id
                          WHERE bit.status = 'pending'
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
