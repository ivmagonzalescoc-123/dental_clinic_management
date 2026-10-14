<?php
/**
 * Guard rails for the admin analytics chatbot (admin/admin_modules/ai_chat_handler.php).
 *
 * The chatbot turns a question into a SELECT with an LLM, so the SQL it runs is
 * untrusted. Layers, each enough on its own for the common cases:
 *   1. credential / account questions are refused before the LLM is called;
 *   2. replies that aren't data answers are fixed server-side text (never LLM text),
 *      so it can't be steered off the clinic topic;
 *   3. generated SQL must pass ai_chat_validate_sql(): a single read-only SELECT over
 *      an allowlist of clinic tables/columns, with dangerous functions refused;
 *   4. it runs in a READ ONLY transaction with a statement timeout and a row cap;
 *   5. sensitive-looking columns are stripped from results before they're used.
 */

if (!function_exists('ai_chat_validate_sql')) {

    /** Tables the chatbot may read, and the columns it may use from each. */
    function ai_chat_allowed_tables(): array
    {
        return [
            'appointments' => ['appointment_id', 'appointment_date', 'timeslot_id', 'reason', 'is_online_appointment', 'notes', 'status', 'created_at', 'user_id', 'rescheduled_from', 'patient_id', 'is_emergency', 'completed_by_doctor_at', 'session_started_at', 'secretary_records_completed_at', 'cancellation_reason'],
            'patients' => ['patient_id', 'first_name', 'last_name', 'middle_name', 'date_of_birth', 'gender', 'occupation', 'created_at'],
            'users' => ['user_id', 'first_name', 'last_name', 'middle_name', 'role_id', 'is_active', 'work_type'],
            'role' => ['role_id', 'role_name'],
            'services' => ['service_id', 'service_name', 'initial_price', 'description', 'is_active', 'default_duration_minutes'],
            'selected_services' => ['selected_service_id', 'appointment_id', 'service_id', 'notes', 'created_at'],
            'timeslot' => ['timeslot_id', 'doctor_id', 'slot_date', 'start_time', 'end_time', 'status', 'appointment_id'],
            'billing' => ['billing_id', 'appointment_id', 'total_amount', 'amount_paid', 'balance', 'subtotal_amount', 'discount_type', 'discount_percent', 'created_at', 'status'],
            'billing_line_items' => ['item_id', 'billing_id', 'appointment_id', 'service_id', 'description', 'amount', 'item_type', 'created_at'],
            'billing_installments' => ['installment_id', 'billing_id', 'total_amount', 'down_payment_percent', 'down_payment_amount', 'num_terms', 'created_at'],
            'billing_installment_terms' => ['term_id', 'installment_id', 'term_number', 'due_date', 'amount', 'paid_amount', 'status', 'paid_at'],
            'payments' => ['payment_id', 'billing_id', 'amount', 'payment_method', 'or_number', 'paid_at'],
            'invoices' => ['invoice_id', 'billing_id', 'invoice_number', 'issued_at'],
            'clinical_records' => ['record_id', 'appointment_id', 'doctor_id', 'patient_id', 'summary', 'doctor_notes', 'follow_up_date', 'status', 'submitted_at'],
            'clinical_record_services' => ['id', 'record_id', 'service_id', 'notes'],
            'clinical_findings' => ['finding_id', 'record_id', 'patient_id', 'findings_json', 'created_at'],
            'appointment_doctor_notes' => ['note_id', 'appointment_id', 'doctor_id', 'note_text', 'created_at'],
            'prescriptions' => ['prescription_id', 'appointment_id', 'prescription_date', 'medicine_name', 'dosage', 'medicine_brand', 'duration', 'notes'],
            'patient_treatment_plan_items' => ['item_id', 'patient_id', 'service_id', 'tooth_number', 'phase', 'estimated_cost', 'notes', 'status', 'source_appointment_id', 'created_at', 'accepted_at', 'completed_appointment_id', 'completed_at'],
            'treatment_plans' => ['treatment_id', 'record_id', 'service_id', 'tooth_number', 'description', 'status', 'estimated_cost', 'created_at'],
            'patient_feedback' => ['feedback_id', 'patient_id', 'appointment_id', 'doctor_id', 'rating', 'comment', 'created_at'],
            'doctor_availability' => ['availability_id', 'user_id', 'weekday', 'start_time', 'end_time', 'status'],
            'doctor_leaves' => ['leave_id', 'user_id', 'leave_start_date', 'leave_end_date', 'status'],
            'specialization' => ['specialization_id', 'specialization_name'],
            'dentist_specialization' => ['doctor_specialized_id', 'user_id', 'specialization_id'],
            'tooth_status' => ['tooth_status_id', 'record_id', 'tooth_selected', 'status_type_id', 'tooth_surface', 'status', 'notes'],
            'tooth_status_type' => ['status_type_id', 'label', 'category', 'code'],
        ];
    }

    /** Names that look like credentials, account/login data or contact details. */
    function ai_chat_sensitive_pattern(): string
    {
        return '/\b\w*(password|passwd|_hash|hash_|token|otp|secret|google_id|google_picture|email|phone|address'
            . '|remember|validator|selector|ip_address|user_agent|signature|licenses|api_key|is_hiv|locked_until'
            . '|failed_login|must_change|last_login|login_time|logout_time|session_id)\w*\b/i';
    }

    /** True when the question asks for credentials / account details. */
    function ai_chat_is_credential_request(string $message): bool
    {
        return (bool)preg_match(
            '/\b(pass\s*words?|passcodes?|pwd|credentials?|log\s*-?\s*ins?|sign\s*-?\s*ins?|user\s*names?|e-?mails?'
            . '|e-?mail\s+address(es)?|otps?|one[\s-]*time\s+(pin|code|password)|tokens?|api\s*keys?|secret\s*keys?'
            . '|hash(es|ed)?|pin\s*codes?|security\s+questions?|reset\s+links?|google\s+ids?)\b/i',
            $message
        );
    }

    /** Fixed replies for everything that isn't a data answer. */
    function ai_chat_canned_reply(string $category): string
    {
        $replies = [
            'greeting' => "Hello! I'm the clinic assistant. Ask me about appointments, services, treatment plans, patient visit history and records, billing, or doctor schedules.",
            'capabilities' => 'I can answer questions about appointments (schedules, counts, no-shows), services, treatment plans, patient visit history and clinical records, billing and payments, patient balances and installment plans, and doctor schedules. I only answer questions about the clinic.',
            'thanks' => "You're welcome! Let me know if you have more questions about the clinic.",
            'credentials' => "I can't share passwords, login details, email addresses, OTPs or other account information. I can help with appointments, services, treatment plans, patient records, and billing.",
            'off_topic' => "I can only help with Azucena's Dental Clinic: appointments, services, treatment plans, patient records, billing, and doctor schedules.",
            'unclear' => 'Sorry, I could not understand that. Try asking about appointments, services, treatment plans, patient records, or billing.',
        ];
        return $replies[$category] ?? $replies['off_topic'];
    }

    /**
     * Checks LLM-generated SQL. Returns ['ok' => true, 'sql' => cleaned] or
     * ['ok' => false, 'reason' => 'sensitive'|'unsafe'|'table'|'column'].
     */
    function ai_chat_validate_sql(string $sql): array
    {
        $fail = function (string $reason) { return ['ok' => false, 'reason' => $reason]; };
        $sql = trim($sql);
        $sql = rtrim($sql, "; \t\n\r");
        if ($sql === '' || strlen($sql) > 4000) return $fail('unsafe');

        // Blank out string literals so their contents can't hide or fake keywords.
        $scan = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql);
        $scan = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $scan);
        if ($scan === null) return $fail('unsafe');

        // One statement, no comments / quoting tricks / variables.
        if (preg_match('/;|--|#|\/\*|\*\/|`|\\\\|@/', $scan)) return $fail('unsafe');
        if (!preg_match('/^\s*select\b/i', $scan)) return $fail('unsafe');

        // Anything that writes, reads files, sleeps, locks, or inspects the server.
        $forbidden = '/\b(insert|update|delete|replace|drop|alter|create|truncate|rename|grant|revoke|call|handler'
            . '|lock|unlock|set|into|outfile|dumpfile|load_file|load|sleep|benchmark|get_lock|release_lock'
            . '|is_free_lock|is_used_lock|information_schema|performance_schema|mysql|sys|procedure|analyse'
            . '|extractvalue|updatexml|user|current_user|session_user|system_user|database|schema|version'
            . '|connection_id|last_insert_id|row_count|found_rows)\b/i';
        if (preg_match($forbidden, $scan)) return $fail('unsafe');

        if (preg_match(ai_chat_sensitive_pattern(), $scan)) return $fail('sensitive');

        // No SELECT * / alias.* (would pull every column, including sensitive ones).
        if (preg_match('/\bselect\s+(distinct\s+)?\*|,\s*\*|\.\s*\*/i', $scan)) return $fail('unsafe');

        $allowed = ai_chat_allowed_tables();
        $keywords = ['where', 'join', 'inner', 'left', 'right', 'outer', 'cross', 'natural', 'group', 'order', 'limit', 'having', 'on', 'using', 'union', 'as', 'straight_join'];

        // No implicit comma joins ("FROM a x, b y"): every table must come via FROM/JOIN.
        if (preg_match('/\bfrom\s+[a-z_]\w*(?:\s+(?:as\s+)?(?!(?:' . implode('|', $keywords) . ')\b)[a-z_]\w*)?\s*,/i', $scan)) {
            return $fail('unsafe');
        }

        // Every FROM/JOIN table must be allowlisted; remember aliases.
        $aliases = [];
        preg_match_all('/\b(?:from|join)\s+([a-z_][a-z0-9_]*)(?:\s+(?:as\s+)?([a-z_][a-z0-9_]*))?/i', $scan, $m, PREG_SET_ORDER);
        foreach ($m as $ref) {
            $table = strtolower($ref[1]);
            if (!isset($allowed[$table])) return $fail('table');
            $aliases[$table] = $table;
            $alias = isset($ref[2]) ? strtolower($ref[2]) : '';
            if ($alias !== '' && !in_array($alias, $keywords, true)) {
                $aliases[$alias] = $table;
            }
        }
        if (!$aliases) return $fail('table');

        // Qualified columns must be allowed for their table.
        preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\.\s*([a-z_][a-z0-9_]*)\b/i', $scan, $cols, PREG_SET_ORDER);
        foreach ($cols as $c) {
            $alias = strtolower($c[1]);
            $col = strtolower($c[2]);
            if (isset($aliases[$alias])) {
                if (!in_array($col, $allowed[$aliases[$alias]], true)) return $fail('column');
            }
            // Unknown alias = a derived-table alias; the sensitive-name check above covers it.
        }

        return ['ok' => true, 'sql' => $sql];
    }

    /** Drops any column whose name looks sensitive (defence in depth). */
    function ai_chat_sanitize_rows(array $rows): array
    {
        $pattern = ai_chat_sensitive_pattern();
        return array_map(function ($row) use ($pattern) {
            return array_filter($row, fn($key) => !preg_match($pattern, (string)$key), ARRAY_FILTER_USE_KEY);
        }, $rows);
    }

    /**
     * Runs an already-validated SELECT read-only, with a statement timeout and row cap.
     * Returns the rows, or false on SQL error.
     */
    function ai_chat_run_select(mysqli $conn, string $sql, int $maxRows = 200)
    {
        // One of these applies depending on the server (MariaDB / MySQL); the other is ignored.
        @$conn->query('SET SESSION max_statement_time = 5');
        @$conn->query('SET SESSION max_execution_time = 5000');
        $conn->query('START TRANSACTION READ ONLY');
        $result = $conn->query($sql);
        if (!$result) {
            $conn->query('ROLLBACK');
            return false;
        }
        $rows = [];
        while (count($rows) < $maxRows && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        $result->close();
        $conn->query('ROLLBACK');
        return ai_chat_sanitize_rows($rows);
    }
}
