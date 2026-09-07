<?php
/**
 * Analytics Chat Endpoint - Uses Groq API directly
 */
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/config/env.php';
require_once dirname(dirname(__DIR__)) . '/config/security.php';
require_once dirname(dirname(__DIR__)) . '/config/database.php';

security_start_session();

// ── DEBUG MODE ──
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
        'debug' => true,
        'php_version' => PHP_VERSION,
        'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'session' => [
            'status' => session_status(),
            'id' => session_id() ?: null,
            'has_user_id' => isset($_SESSION['user_id']),
            'user_id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role'] ?? null,
        ],
        'groq_key_loaded' => $groqKeyOk,
        'groq_key_prefix' => $groqKeyOk ? substr($groqKeyRaw, 0, 8) . '...' : null,
        'curl'            => $curlTest,
        'env_file_exists' => file_exists(dirname(dirname(__DIR__)) . '/.env'),
    ], JSON_PRETTY_PRINT);
    exit;
}

// Admin only
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    ob_end_clean();
    http_response_code(403);
    echo json_encode([
        'error' => 'Unauthorized',
        'debug_hint' => 'Session is empty or role is not admin. Visit with ?debug=1 to diagnose.',
        'session_keys' => array_keys($_SESSION),
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
if ($userMessage === '') {
    ob_end_clean();
    echo json_encode(['error' => 'Message is required']);
    exit;
}

$groqKey = env('GROQ_API_KEY', '');
if (!$groqKey || $groqKey === 'your_groq_api_key_here') {
    ob_end_clean();
    echo json_encode(['error' => 'Groq API key not configured. Add GROQ_API_KEY to .env']);
    exit;
}

$systemPrompt = <<<'PROMPT'
You are a MySQL SELECT query generator for Azucena's Dental Clinic admin dashboard.

TASK: Given a question, output ONLY a JSON object with two keys:
- "sql": a valid MySQL SELECT query
- "answer": a 1-sentence label describing what the query measures (NOT repeating the user's question)

ABSOLUTE SQL RULES:
1. ALWAYS assign a short alias to every table in FROM and JOIN.
2. ALWAYS prefix EVERY column with its table alias. No bare column names ever.
   CORRECT: FROM appointments a WHERE a.appointment_date = CURDATE()
   WRONG:   FROM appointments WHERE appointment_date = CURDATE()
3. appointment_date exists ONLY in the appointments table. When querying billing or patients by date, JOIN appointments first.
4. ONLY SELECT statements. Never INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE.
5. Limit result sets: add LIMIT 10 for list queries unless a specific number is requested.

TIME-BASED QUERIES (critical for "busiest hour", "peak hours", etc.):
- The timeslot table has start_time and end_time columns. Use these for hourly analysis.
- To find busiest hours: JOIN appointments a ON a.timeslot_id = t.timeslot_id, then GROUP BY t.start_time.
- To extract hour from a time column: HOUR(t.start_time) or DATE_FORMAT(t.start_time, '%H:00').
- For "busiest hour today": filter by a.appointment_date = CURDATE() AND a.status NOT IN ('cancelled','no_show').
- For "busiest hour this week": filter by YEARWEEK(a.appointment_date,1) = YEARWEEK(CURDATE(),1) AND a.status NOT IN ('cancelled','no_show').
- Always exclude cancelled and no_show appointments from busy-hour analysis.
- Return start_time and count, ORDER BY count DESC, LIMIT 5.

COLUMN -> TABLE MAP (memorize - use these exact aliases):
  appointments a   -> a.appointment_id, a.appointment_date, a.status, a.patient_id, a.user_id, a.is_emergency, a.timeslot_id
  patients p       -> p.patient_id, p.first_name, p.last_name, p.created_at, p.gender, p.phone_number
  users u          -> u.user_id, u.first_name, u.last_name, u.email, u.role_id, u.is_active
  role r           -> r.role_id, r.role_name
  billing b        -> b.billing_id, b.appointment_id, b.total_amount, b.amount_paid, b.balance, b.status
  services s       -> s.service_id, s.service_name, s.initial_price
  selected_services ss -> ss.selected_service_id, ss.appointment_id, ss.service_id
  payments py      -> py.payment_id, py.billing_id, py.amount, py.payment_method, py.paid_at
  clinical_records cr  -> cr.record_id, cr.appointment_id, cr.doctor_id, cr.patient_id
  timeslot t       -> t.timeslot_id, t.doctor_id, t.slot_date, t.start_time, t.end_time, t.status

DATE PATTERNS (always qualify with table alias):
  Today        : a.appointment_date = CURDATE()
  This week    : YEARWEEK(a.appointment_date, 1) = YEARWEEK(CURDATE(), 1)
  This month   : MONTH(a.appointment_date) = MONTH(CURDATE()) AND YEAR(a.appointment_date) = YEAR(CURDATE())
  Last 7 days  : a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  Last 30 days : a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
  New patients : DATE(p.created_at) (NOT appointment_date - patients table has no appointment_date)

ANSWER RULES:
- The "answer" describes what was measured, e.g. "Total appointments scheduled for today." or "Top services booked this month."
- NEVER echo or rephrase the user's question as the answer.
- NEVER say "you asked" or reference the question.
- Use ₱ for currency amounts.

OFF-TOPIC: If not about clinic data -> {"sql": null, "answer": "I can only answer questions about clinic appointments, patients, services, billing, and doctor activity."}

EXAMPLES:
Q: How many appointments today?
A: {"sql":"SELECT COUNT(*) AS total FROM appointments a WHERE a.appointment_date = CURDATE()","answer":"Total appointments scheduled for today."}

Q: What is the busiest hour today?
A: {"sql":"SELECT t.start_time AS hour, COUNT(a.appointment_id) AS appointment_count FROM appointments a INNER JOIN timeslot t ON t.timeslot_id = a.timeslot_id WHERE a.appointment_date = CURDATE() AND a.status NOT IN ('cancelled','no_show') GROUP BY t.start_time ORDER BY appointment_count DESC LIMIT 5","answer":"Peak appointment hours today by number of bookings."}

Q: Busiest hours this week?
A: {"sql":"SELECT DATE_FORMAT(t.start_time, '%H:00') AS hour, COUNT(a.appointment_id) AS appointment_count FROM appointments a INNER JOIN timeslot t ON t.timeslot_id = a.timeslot_id WHERE YEARWEEK(a.appointment_date,1) = YEARWEEK(CURDATE(),1) AND a.status NOT IN ('cancelled','no_show') GROUP BY DATE_FORMAT(t.start_time, '%H:00') ORDER BY appointment_count DESC LIMIT 5","answer":"Most active hours this week based on confirmed appointments."}

Q: Revenue this month?
A: {"sql":"SELECT COALESCE(SUM(b.amount_paid),0) AS collected FROM billing b INNER JOIN appointments a ON a.appointment_id = b.appointment_id WHERE MONTH(a.appointment_date)=MONTH(CURDATE()) AND YEAR(a.appointment_date)=YEAR(CURDATE())","answer":"Total revenue collected this month."}

Q: Top services this month?
A: {"sql":"SELECT s.service_name, COUNT(ss.selected_service_id) AS bookings FROM selected_services ss INNER JOIN appointments a ON a.appointment_id = ss.appointment_id INNER JOIN services s ON s.service_id = ss.service_id WHERE MONTH(a.appointment_date)=MONTH(CURDATE()) AND YEAR(a.appointment_date)=YEAR(CURDATE()) GROUP BY s.service_id ORDER BY bookings DESC LIMIT 5","answer":"Top 5 most booked services this month."}

Q: Busiest doctor this week?
A: {"sql":"SELECT u.first_name, u.last_name, COUNT(a.appointment_id) AS total FROM appointments a INNER JOIN users u ON u.user_id = a.user_id WHERE YEARWEEK(a.appointment_date,1)=YEARWEEK(CURDATE(),1) GROUP BY u.user_id ORDER BY total DESC LIMIT 1","answer":"Doctor with the most appointments this week."}

Q: New patients this month?
A: {"sql":"SELECT COUNT(*) AS new_patients FROM patients p WHERE MONTH(DATE(p.created_at))=MONTH(CURDATE()) AND YEAR(DATE(p.created_at))=YEAR(CURDATE())","answer":"New patients registered this month."}

Q: No-shows last 30 days?
A: {"sql":"SELECT COUNT(*) AS no_shows FROM appointments a WHERE a.status = 'no_show' AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)","answer":"No-show appointments in the last 30 days."}

OUTPUT: Strict JSON only. No markdown. No code fences. No text outside the JSON object.
PROMPT;

$schema = <<<'SCHEMA'
FULL SCHEMA REFERENCE:
patients(patient_id, first_name, last_name, middle_name, date_of_birth, gender, phone_number, email, address, created_at)
appointments(appointment_id, appointment_date, status[scheduled|confirmed|checked_in|in_progress|completed_by_doctor|awaiting_secretary_records|records_complete|completed|cancelled|no_show|rescheduled], patient_id, user_id, is_emergency, created_at)
users(user_id, first_name, last_name, email, role_id, is_active)
role(role_id, role_name[admin|doctor|secretary|patient])
services(service_id, service_name, initial_price, is_active)
selected_services(selected_service_id, appointment_id, service_id)
billing(billing_id, appointment_id, total_amount, amount_paid, balance, status[unpaid|partial|paid])
payments(payment_id, billing_id, amount, payment_method, paid_at)
clinical_records(record_id, appointment_id, doctor_id, patient_id, status[in_progress|submitted|finalized])
timeslot(timeslot_id, doctor_id, slot_date, start_time, end_time, status)
SCHEMA;

// ── STEP 1: Generate SQL via Groq ──
$payload = [
    'model' => 'openai/gpt-oss-120b',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt . "\n\n" . $schema],
        ['role' => 'user',   'content' => $userMessage],
    ],
    'temperature' => 0.05,
    'max_tokens'  => 600,
    'reasoning_effort' => 'low',
];

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST          => true,
    CURLOPT_HTTPHEADER    => [
        'Authorization: Bearer ' . $groqKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS    => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT       => 20,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log('analytics_chat: Groq request failed: ' . $curlError);
    ob_end_clean();
    echo json_encode(['error' => 'The system is currently busy. Please try again in a moment.']);
    exit;
}

if ($httpCode !== 200) {
    $errData = json_decode($response, true);
    $rawMsg = $errData['error']['message'] ?? ('Groq API error (HTTP ' . $httpCode . ')');
    error_log('analytics_chat: Groq API error: ' . $rawMsg);
    $msg = 'The analytics assistant is temporarily unavailable. Please try again shortly.';
    if (stripos($rawMsg, 'rate limit') !== false || $httpCode === 429) {
        $msg = 'The system is busy. Please wait a moment and try again.';
    } elseif ($httpCode === 401 || $httpCode === 403) {
        $msg = 'The analytics assistant needs configuration. Please contact the administrator.';
    } elseif ($httpCode === 504 || stripos($rawMsg, 'timeout') !== false) {
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
    echo json_encode(['error' => 'Could not parse AI response. Please try again.']);
    exit;
}

$answer = trim((string)($parsed['answer'] ?? ''));
$sql    = isset($parsed['sql']) ? trim((string)$parsed['sql']) : '';

if ($answer !== '' && strlen($answer) > 4) {
    similar_text(strtolower($answer), strtolower($userMessage), $pct);
    if ($pct > 72) {
        ob_end_clean();
        echo json_encode(['error' => 'Could not generate a proper answer. Please try again.']);
        exit;
    }
}

// No SQL - text-only answer (off-topic or non-data questions)
if ($sql === '' || strtolower($sql) === 'null') {
    if (!isset($_SESSION['ai_chat_history'])) $_SESSION['ai_chat_history'] = [];
    $_SESSION['ai_chat_history'][] = ['role' => 'user', 'text' => $userMessage];
    $_SESSION['ai_chat_history'][] = ['role' => 'bot',  'text' => $answer];
    if (count($_SESSION['ai_chat_history']) > 60) {
        $_SESSION['ai_chat_history'] = array_slice($_SESSION['ai_chat_history'], -60);
    }
    ob_end_clean();
    echo json_encode(['answer' => $answer, 'rows' => [], 'row_count' => 0]);
    exit;
}

// Safety: only allow SELECT
$upperSql = strtoupper(ltrim($sql));
if (strpos($upperSql, 'SELECT') !== 0 || preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE)\b/i', $sql)) {
    ob_end_clean();
    echo json_encode(['error' => 'Only SELECT queries are allowed.']);
    exit;
}

$result = $conn->query($sql);
if (!$result) {
    error_log('analytics_chat: SQL error: ' . $conn->error . ' | SQL: ' . $sql);
    ob_end_clean();
    echo json_encode([
        'error' => 'Sorry, I could not run that query. Please try rephrasing your question.',
    ]);
    exit;
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$result->close();

// ── STEP 2: Send query results back to AI for a natural language answer ──
if (count($rows) > 0) {
    $dataJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
    if (strlen($dataJson) > 3000) {
        $rows = array_slice($rows, 0, 20);
        $dataJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    $interpretPrompt = <<<PROMPT
You are a clinic analytics assistant. The user asked a question and here is the data from the database.

USER QUESTION: {$userMessage}
QUERY RESULTS (JSON): {$dataJson}

TASK: Write a clear, concise answer in a natural sentence or two. State the actual numbers and findings from the data. Do NOT just describe what the query measured - give the real answer with the values.

RULES:
- Use the actual values from the results (counts, names, amounts, etc.)
- Use ₱ for currency
- Be conversational but factual
- If results are empty, say "No data found for that period."
- If there is only one row with one value, state it directly
- For lists/rankings, mention the top results by name
- Keep it under 3 sentences
PROMPT;

    $interpretPayload = [
        'model' => 'openai/gpt-oss-120b',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a friendly dental clinic analytics assistant. Answer with actual data values in natural sentences.'],
            ['role' => 'user',   'content' => $interpretPrompt],
        ],
        'temperature' => 0.3,
        'max_tokens'  => 300,
        'reasoning_effort' => 'low',
    ];

    $ch2 = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch2, [
        CURLOPT_POST          => true,
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $groqKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS    => json_encode($interpretPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 15,
    ]);

    $response2  = curl_exec($ch2);
    $httpCode2  = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $curlError2 = curl_error($ch2);
    curl_close($ch2);

    if (!$curlError2 && $httpCode2 === 200) {
        $aiResponse2 = json_decode($response2, true);
        $naturalAnswer = trim($aiResponse2['choices'][0]['message']['content'] ?? '');
        if ($naturalAnswer !== '') {
            $answer = $naturalAnswer;
        }
    }
}

// Persist to session
if (!isset($_SESSION['ai_chat_history'])) $_SESSION['ai_chat_history'] = [];
$_SESSION['ai_chat_history'][] = ['role' => 'user', 'text' => $userMessage];
$_SESSION['ai_chat_history'][] = ['role' => 'bot',  'text' => $answer];
if (count($_SESSION['ai_chat_history']) > 60) {
    $_SESSION['ai_chat_history'] = array_slice($_SESSION['ai_chat_history'], -60);
}

ob_end_clean();
echo json_encode([
    'answer'    => $answer,
    'rows'      => $rows,
    'row_count' => count($rows),
]);