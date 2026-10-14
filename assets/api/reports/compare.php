<?php
/**
 * Clinic Reports — compare two date ranges side by side.
 * GET report_type, a_start, a_end, b_start, b_end, [doctor_id], [format=json|csv]
 * JSON feeds the Compare window on the reports page; CSV is the same table as a download.
 */

include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
check_session(['admin']);

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/report_compare_helpers.php';

billing_ensure_tables($conn);

$format = ($_GET['format'] ?? 'json') === 'csv' ? 'csv' : 'json';
$fail = function (string $message) use ($format) {
    http_response_code(400);
    if ($format === 'csv') {
        header('Content-Type: text/plain; charset=utf-8');
        exit($message);
    }
    header('Content-Type: application/json');
    exit(json_encode(['success' => false, 'message' => $message]));
};

$types = report_compare_types();
$reportType = (string)($_GET['report_type'] ?? 'sales');
if (!isset($types[$reportType])) {
    $fail('Unknown report type.');
}

$periods = [];
foreach (['a', 'b'] as $key) {
    $start = (string)($_GET[$key . '_start'] ?? '');
    $end = (string)($_GET[$key . '_end'] ?? '');
    foreach ([$start, $end] as $date) {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            $fail('Choose a From and To date for both periods.');
        }
    }
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }
    $periods[$key] = [
        'start' => $start,
        'end' => $end,
        'label' => date('M d, Y', strtotime($start)) . ' to ' . date('M d, Y', strtotime($end)),
    ];
}

// The Trend & Predictive figures are clinic-wide, like the tab itself.
$doctorId = $reportType === 'trend' ? 0 : (int)($_GET['doctor_id'] ?? 0);
$doctorName = 'All doctors';
if ($doctorId > 0) {
    $stmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $doctorId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$doc) {
        $fail('Doctor not found.');
    }
    $doctorName = trim($doc['first_name'] . ' ' . $doc['last_name']);
}

$sections = report_compare_build(
    $conn,
    $reportType,
    [$periods['a']['start'], $periods['a']['end']],
    [$periods['b']['start'], $periods['b']['end']],
    $doctorId
);

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'report_type' => $reportType,
        'report_label' => $types[$reportType],
        'doctor' => $doctorName,
        'a' => $periods['a'],
        'b' => $periods['b'],
        'sections' => $sections,
    ]);
    exit;
}

$cell = function ($value, string $fmt = '') {
    if ($value === null) {
        return '';
    }
    if ($fmt === 'money') {
        return number_format((float)$value, 2, '.', '');
    }
    if ($fmt === 'pct') {
        return $value . '%';
    }
    return (string)$value;
};

$fileName = sprintf(
    'comparison_%s_%s_%s_vs_%s_%s.csv',
    $reportType,
    $periods['a']['start'],
    $periods['a']['end'],
    $periods['b']['start'],
    $periods['b']['end']
);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
fputcsv($out, ["Azucena's Dental Clinic - " . $types[$reportType] . ' - Comparison']);
fputcsv($out, ['Period A', $periods['a']['label']]);
fputcsv($out, ['Period B', $periods['b']['label']]);
fputcsv($out, ['Doctor', $doctorName]);
fputcsv($out, ['Generated', date('Y-m-d H:i')]);
fputcsv($out, ['Amounts are in pesos. Change = Period B minus Period A (percentage points for rates).']);
fputcsv($out, []);
fputcsv($out, ['Section', 'Metric', 'Period A (' . $periods['a']['label'] . ')', 'Period B (' . $periods['b']['label'] . ')', 'Change', '% Change']);
foreach ($sections as $section) {
    foreach ($section['rows'] as $row) {
        fputcsv($out, [
            $section['title'],
            $row['label'],
            $cell($row['a'], $row['format']),
            $cell($row['b'], $row['format']),
            $cell($row['change'], $row['format'] === 'pct' ? '' : $row['format']),
            $row['pct_change'] === null ? '' : $row['pct_change'] . '%',
        ]);
    }
}
fclose($out);
