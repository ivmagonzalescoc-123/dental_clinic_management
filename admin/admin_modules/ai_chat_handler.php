<?php
// Included by admin.php when action=ai_chat — session/auth/db already set up there.
ob_start();

// ── DEBUG ── admin.php?action=ai_chat&debug=1
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    ob_end_clean();
    $groqKeyRaw = env('GROQ_API_KEY', '');
    $groqKeyOk  = !empty($groqKeyRaw) && $groqKeyRaw !== 'your_groq_api_key_here';

    $curlTest = ['supported' => false, 'reachable' => false, 'error' => null, 'http_code' => null];
    if (function_exists('curl_init')) {
        $curlTest['supported'] = true;
        $ch = curl_init('https://api.groq.com/openai/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $groqKeyOk ? ['Authorization: Bearer ' . $groqKeyRaw] : [],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $curlTest['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlTest['error']     = curl_error($ch) ?: null;
        $curlTest['reachable'] = ($curlTest['http_code'] >= 200 && $curlTest['http_code'] < 500);
        curl_close($ch);
    }

    echo json_encode([
        'debug'           => true,
        'php_version'     => PHP_VERSION,
        'https'           => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'session'         => [
            'status'      => session_status(),
            'has_user_id' => isset($_SESSION['user_id']),
            'user_id'     => $_SESSION['user_id'] ?? null,
            'role'        => $_SESSION['role'] ?? null,
        ],
        'groq_key_loaded' => $groqKeyOk,
        'groq_key_prefix' => $groqKeyOk ? substr($groqKeyRaw, 0, 8) . '...' : null,
        'curl'            => $curlTest,
        'env_file_exists' => file_exists(dirname(dirname(__DIR__)) . '/.env'),
    ], JSON_PRETTY_PRINT);
    exit;
}

$groqKey = env('GROQ_API_KEY', '');
if (!$groqKey || $groqKey === 'your_groq_api_key_here') {
    ob_end_clean();
    echo json_encode(['error' => 'The analytics assistant is not configured yet. Please contact the administrator.']);
    exit;
}

$todayDate = date('Y-m-d');

$input       = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
if ($userMessage === '') {
    ob_end_clean();
    echo json_encode(['error' => 'Please type a message before sending.']);
    exit;
}

$systemPrompt = <<<'PROMPT'
You are a MySQL SELECT query generator for Azucena's Dental Clinic admin dashboard.

TODAY'S DATE: {$todayDate}

TASK: Given a question, output ONLY a JSON object with two keys:
- "sql": a valid MySQL SELECT query
- "answer": a 1-sentence label describing what the query measures (NOT repeating the user's question)

ABSOLUTE SQL RULES:
1. ALWAYS assign a short alias to every table in FROM and JOIN.
2. ALWAYS prefix EVERY column with its table alias. No bare column names ever.
   CORRECT: FROM appointments a WHERE a.appointment_date = CURDATE()
   WRONG:   FROM appointments WHERE appointment_date = CURDATE()
3. ONLY SELECT statements. Never INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE.
4. Limit result sets: add LIMIT 10 for list queries unless a specific number is requested.

CRITICAL DISTINCTION — BILLING vs PAYMENTS:
- billing table = invoices (what was billed). Use b.total_amount, b.amount_paid, b.balance, b.status, b.created_at.
- payments table = actual payment transactions (when money was received). Use py.amount, py.payment_method, py.paid_at.
- When the user asks about PAYMENTS MADE (collected, received, paid) on a date, you MUST query the payments table using py.paid_at as the date filter. NEVER use billing.created_at or a.appointment_date for payment date questions.
- When the user asks about BILLING or INVOICES (what was billed, total billed, outstanding), query the billing table.

COLUMN → TABLE MAP (use these exact aliases):
  appointments a   → a.appointment_id, a.appointment_date, a.status, a.patient_id, a.user_id, a.is_emergency
  patients p       → p.patient_id, p.first_name, p.last_name, p.created_at, p.gender, p.phone_number
  users u          → u.user_id, u.first_name, u.last_name, u.email, u.role_id, u.is_active
  role r           → r.role_id, r.role_name
  billing b        → b.billing_id, b.appointment_id, b.total_amount, b.amount_paid, b.balance, b.status, b.created_at
  services s       → s.service_id, s.service_name, s.initial_price
  selected_services ss → ss.selected_service_id, ss.appointment_id, ss.service_id
  payments py      → py.payment_id, py.billing_id, py.amount, py.payment_method, py.paid_at, py.or_number
  clinical_records cr  → cr.record_id, cr.appointment_id, cr.doctor_id, cr.patient_id
  billing_line_items bli → bli.item_id, bli.billing_id, bli.appointment_id, bli.service_id, bli.description, bli.amount, bli.item_type

DATE PATTERNS (always qualify with table alias):
  APPOINTMENTS:
    Today        : a.appointment_date = CURDATE()
    This week    : YEARWEEK(a.appointment_date, 1) = YEARWEEK(CURDATE(), 1)
    This month   : MONTH(a.appointment_date) = MONTH(CURDATE()) AND YEAR(a.appointment_date) = YEAR(CURDATE())
    Last 7 days  : a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    Last 30 days : a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
  PAYMENTS (use py.paid_at for when money was actually received):
    Today        : DATE(py.paid_at) = CURDATE()
    This week    : YEARWEEK(py.paid_at, 1) = YEARWEEK(CURDATE(), 1)
    This month   : MONTH(py.paid_at) = MONTH(CURDATE()) AND YEAR(py.paid_at) = YEAR(CURDATE())
    Last 7 days  : py.paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  PATIENTS:
    New patients : DATE(p.created_at) (patients table has no appointment_date)
  BILLING:
    Billed this month : MONTH(b.created_at) = MONTH(CURDATE()) AND YEAR(b.created_at) = YEAR(CURDATE())

ANSWER RULES:
- The "answer" describes what was measured.
- NEVER echo or rephrase the user's question as the answer.
- NEVER say "you asked" or reference the question.
- Use ₱ for currency amounts.
- If the query might return 0 rows, phrase the answer neutrally.

RESPONSE CATEGORIES:
1. GREETINGS (hi, hello, hey, good morning, etc.): Respond with a brief, friendly greeting. No SQL needed.
   → {"sql": null, "answer": "Hello! I'm your clinic analytics assistant. Ask me about appointments, patients, services, billing, or doctor activity."}

2. CAPABILITIES (what can you do, help, what do you know): Explain what you can help with. No SQL needed.
   → {"sql": null, "answer": "I can answer questions about: appointments (schedules, counts, no-shows), patients (demographics, new registrations), services (popular services, bookings), billing (revenue, outstanding, payments), and doctor activity. Just ask!"}

3. THANKS / POLITE (thanks, thank you, nice, great): Acknowledge briefly. No SQL needed.
   → {"sql": null, "answer": "You're welcome! Let me know if you have more questions about your clinic data."}

4. CLINIC DATA QUESTIONS: Generate SQL and answer.

5. UNRELATED (jokes, weather, sports, politics, random topics): Politely redirect.
   → {"sql": null, "answer": "I'm focused on Azucena's Dental Clinic data. Try asking about appointments, patients, billing, or services instead!"}

OFF-TOPIC: For any question that is NOT a greeting AND NOT about clinic data → {"sql": null, "answer": "I'm focused on Azucena's Dental Clinic data. Try asking about appointments, patients, billing, or services instead!"}

EXAMPLES:

Q: How many appointments today?
A: {"sql":"SELECT COUNT(*) AS total FROM appointments a WHERE a.appointment_date = CURDATE()","answer":"Total appointments scheduled for today."}

Q: Total payments made today?
A: {"sql":"SELECT COALESCE(SUM(py.amount),0) AS total_paid, COUNT(py.payment_id) AS payment_count FROM payments py WHERE DATE(py.paid_at) = CURDATE()","answer":"Total payments collected today from actual payment transactions."}

Q: How much was paid yesterday?
A: {"sql":"SELECT COALESCE(SUM(py.amount),0) AS total_paid FROM payments py WHERE DATE(py.paid_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)","answer":"Total amount received in payments yesterday."}

Q: Payments this week?
A: {"sql":"SELECT COALESCE(SUM(py.amount),0) AS total_paid, COUNT(py.payment_id) AS transactions FROM payments py WHERE YEARWEEK(py.paid_at, 1) = YEARWEEK(CURDATE(), 1)","answer":"Total payments collected this week."}

Q: Payments this month?
A: {"sql":"SELECT COALESCE(SUM(py.amount),0) AS total_paid, COUNT(py.payment_id) AS transactions FROM payments py WHERE MONTH(py.paid_at) = MONTH(CURDATE()) AND YEAR(py.paid_at) = YEAR(CURDATE())","answer":"Total payments collected this month."}

Q: What payment methods were used today?
A: {"sql":"SELECT py.payment_method, COUNT(*) AS count, COALESCE(SUM(py.amount),0) AS total FROM payments py WHERE DATE(py.paid_at) = CURDATE() GROUP BY py.payment_method ORDER BY total DESC","answer":"Payment methods used in today's transactions."}

Q: Total billed this month?
A: {"sql":"SELECT COALESCE(SUM(b.total_amount),0) AS total_billed, COUNT(*) AS invoice_count FROM billing b WHERE MONTH(b.created_at)=MONTH(CURDATE()) AND YEAR(b.created_at)=YEAR(CURDATE())","answer":"Total amount billed across all invoices this month."}

Q: Outstanding balance?
A: {"sql":"SELECT COALESCE(SUM(b.balance),0) AS total_outstanding, COUNT(*) AS unpaid_count FROM billing b WHERE b.balance > 0","answer":"Total outstanding balance across all unpaid invoices."}

Q: Revenue this month?
A: {"sql":"SELECT COALESCE(SUM(py.amount),0) AS collected FROM payments py WHERE MONTH(py.paid_at)=MONTH(CURDATE()) AND YEAR(py.paid_at)=YEAR(CURDATE())","answer":"Total revenue collected from payments this month."}

Q: Top services availed this month?
A: {"sql":"SELECT s.service_name, COUNT(ss.selected_service_id) AS bookings FROM selected_services ss INNER JOIN appointments a ON a.appointment_id = ss.appointment_id INNER JOIN services s ON s.service_id = ss.service_id WHERE MONTH(a.appointment_date)=MONTH(CURDATE()) AND YEAR(a.appointment_date)=YEAR(CURDATE()) GROUP BY s.service_id, s.service_name ORDER BY bookings DESC LIMIT 10","answer":"Services availed this month with booking counts."}

Q: Busiest doctor this week?
A: {"sql":"SELECT u.first_name, u.last_name, COUNT(a.appointment_id) AS total FROM appointments a INNER JOIN users u ON u.user_id = a.user_id WHERE YEARWEEK(a.appointment_date,1)=YEARWEEK(CURDATE(),1) GROUP BY u.user_id ORDER BY total DESC LIMIT 1","answer":"Doctor with the most appointments this week."}

Q: New patients this month?
A: {"sql":"SELECT COUNT(*) AS new_patients FROM patients p WHERE MONTH(DATE(p.created_at))=MONTH(CURDATE()) AND YEAR(DATE(p.created_at))=YEAR(CURDATE())","answer":"New patients registered this month."}

Q: No-shows last 30 days?
A: {"sql":"SELECT COUNT(*) AS no_shows FROM appointments a WHERE a.status = 'no_show' AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)","answer":"No-show appointments in the last 30 days."}

Q: Patient demographics?
A: {"sql":"SELECT p.gender, COUNT(*) AS count FROM patients p GROUP BY p.gender ORDER BY count DESC","answer":"Patient count by gender."}

OUTPUT: Strict JSON only. No markdown. No code fences. No text outside the JSON object.
PROMPT;

$schema = <<<'SCHEMA'
FULL SCHEMA REFERENCE:
patients(patient_id, first_name, last_name, middle_name, date_of_birth, gender, phone_number, email, address, created_at)
appointments(appointment_id, appointment_date, status[scheduled|confirmed|checked_in|in_progress|completed_by_doctor|awaiting_secretary_records|records_complete|completed|cancelled|no_show|rescheduled], patient_id, user_id, is_emergency, created_at)
users(user_id, first_name, last_name, email, role_id, is_active)
role(role_id, role_name[admin|doctor|secretary|patient])
payments(payment_id, billing_id, amount, payment_method, or_number, notes, recorded_by, paid_at)
  → paid_at is when the payment was RECEIVED. Use this for "payments today/this week/this month" questions.
  → Do NOT use billing.created_at or appointment_date for payment date queries.
services(service_id, service_name, initial_price, is_active)
selected_services(selected_service_id, appointment_id, service_id)
billing(billing_id, appointment_id, total_amount, amount_paid, balance, status[unpaid|partial|paid])
clinical_records(record_id, appointment_id, doctor_id, patient_id, status[in_progress|submitted|finalized])
SCHEMA;

$payload = [
    'model'    => 'llama-3.3-70b-versatile',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt . "\n\n" . $schema],
        ['role' => 'user',   'content' => $userMessage],
    ],
    'temperature' => 0.05,
    'max_tokens'  => 600,
];

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $groqKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    ob_end_clean();
    echo json_encode(['error' => 'The system is currently busy. Please try again in a moment.']);
    exit;
}

if ($httpCode !== 200) {
    $errData = json_decode($response, true);
    $msg = $errData['error']['message'] ?? 'The system encountered an issue.';
    if (stripos($msg, 'rate limit') !== false || $httpCode === 429) {
        $msg = 'The system is busy. Please wait a moment and try again.';
    } elseif ($httpCode === 401 || $httpCode === 403) {
        $msg = 'The analytics assistant needs configuration. Please contact the administrator.';
    } elseif ($httpCode === 504 || stripos($msg, 'timeout') !== false) {
        $msg = 'The request took too long. Please try a simpler question.';
    }
    ob_end_clean();
    echo json_encode(['error' => $msg]);
    exit;
}

$aiResponse = json_decode($response, true);
$content    = $aiResponse['choices'][0]['message']['content'] ?? '';

$clean = trim($content);
$clean = preg_replace('/```json\s*/i', '', $clean);
$clean = preg_replace('/```\s*/', '', $clean);
$clean = trim($clean);

if (preg_match('/\{.*\}/s', $clean, $m)) {
    $clean = $m[0];
}

$parsed = json_decode($clean, true);
if (!is_array($parsed) || !array_key_exists('answer', $parsed)) {
    ob_end_clean();
    echo json_encode(['error' => 'Sorry, I could not understand that. Please try rephrasing your question.']);
    exit;
}

$answer = trim((string)($parsed['answer'] ?? ''));
$sql    = isset($parsed['sql']) ? trim((string)$parsed['sql']) : '';

// Skip similarity check for non-data responses (greetings, capabilities, unrelated)
$isDataResponse = $sql !== '' && strtolower($sql) !== 'null';
if ($isDataResponse && $answer !== '' && strlen($answer) > 4) {
    similar_text(strtolower($answer), strtolower($userMessage), $pct);
    if ($pct > 85) {
        ob_end_clean();
        echo json_encode(['error' => 'Sorry, I could not generate a proper answer. Please try rephrasing your question.']);
        exit;
    }
}

if ($sql === '' || strtolower($sql) === 'null') {
    ob_end_clean();
    echo json_encode(['answer' => $answer, 'rows' => [], 'row_count' => 0]);
    exit;
}

$upperSql = strtoupper(ltrim($sql));
if (strpos($upperSql, 'SELECT') !== 0 || preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE)\b/i', $sql)) {
    ob_end_clean();
    echo json_encode(['error' => 'I can only run read-only queries. Please try a different question.']);
    exit;
}

$result = $conn->query($sql);
if (!$result) {
    ob_end_clean();
    echo json_encode([
        'answer'    => $answer,
        'rows'      => [],
        'row_count' => 0,
        'sql_error' => $conn->error,
    ]);
    exit;
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$result->close();

ob_end_clean();
echo json_encode([
    'answer'    => $answer,
    'rows'      => $rows,
    'row_count' => count($rows),
]);
