<?php

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
            KEY idx_bit_installment (installment_id),
            KEY idx_bit_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            billing_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) DEFAULT 'cash',
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
        }
        if (!isset($cols['balance'])) {
            @$conn->query('ALTER TABLE billing ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount_paid');
        }
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

        return (bool)$ok;
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
    function billing_create_or_update($conn, $appointmentId, $patientId, $totalAmount, $initialStatus = 'unpaid')
    {
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
                $upd = $conn->prepare('UPDATE billing SET total_amount = ?, user_id = ?, amount_paid = ?, balance = ? WHERE billing_id = ?');
                if ($upd) {
                    $upd->bind_param('diddi', $totalAmount, $patientId, $paid, $balance, $billingId);
                    $upd->execute();
                    $upd->close();
                }
                billing_sync_from_payments($conn, $billingId);
                return $billingId;
            }
        }

        $balance = round((float)$totalAmount, 2);
        $zeroPaid = 0.0;
        $ins = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, amount_paid, balance, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        if (!$ins) {
            return 0;
        }
        $ins->bind_param('iiddds', $appointmentId, $patientId, $totalAmount, $zeroPaid, $balance, $initialStatus);
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
    function billing_record_payment($conn, $billingId, $amount, $method, $notes, $recordedBy)
    {
        $billingId = (int)$billingId;
        $amount = (float)$amount;
        if ($billingId <= 0 || $amount <= 0) {
            return false;
        }

        $ins = $conn->prepare('INSERT INTO payments (billing_id, amount, payment_method, notes, recorded_by) VALUES (?, ?, ?, ?, ?)');
        if (!$ins) {
            return false;
        }
        $ins->bind_param('idssi', $billingId, $amount, $method, $notes, $recordedBy);
        $ok = $ins->execute();
        $ins->close();
        if (!$ok) {
            return false;
        }

        return billing_sync_from_payments($conn, $billingId, $recordedBy);
    }
}
