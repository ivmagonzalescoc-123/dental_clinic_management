<?php
/**
 * Clinic Reports — comparison page (admin.php?page=reports&view=compare).
 * Included by reports.php in place of the normal report when view=compare: two date
 * ranges side by side for the chosen report type, with a CSV export of the same table.
 */
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/report_compare_helpers.php';

billing_ensure_tables($conn);

$rcTypes = [
    'sales' => ['icon' => 'bi-currency-dollar', 'label' => 'Sales Reports'],
    'appointments' => ['icon' => 'bi-calendar-check', 'label' => 'Appointment Reports'],
    'demographic' => ['icon' => 'bi-people', 'label' => 'Demographic Reports'],
    'trend' => ['icon' => 'bi-graph-up', 'label' => 'Trend & Predictive'],
];
$rcType = isset($rcTypes[$_GET['report_type'] ?? '']) ? (string)$_GET['report_type'] : 'sales';
$rcDoctorId = (int)($_GET['doctor_id'] ?? 0);

$rcDate = function ($value): ?string {
    $parsed = DateTime::createFromFormat('!Y-m-d', (string)$value);
    return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
};
$rcToday = date('Y-m-d');

// What to compare: one choice, like the Period filter on the reports. The first period
// (A) is the earlier one and the second (B) the later one; only "Custom dates" asks for dates.
$rcYear = (int)date('Y');
$rcLastMonth = strtotime('first day of last month');
$rcChoices = [
    'year' => ['label' => 'This year vs last year', 'a' => [($rcYear - 1) . '-01-01', ($rcYear - 1) . '-12-31'], 'b' => [$rcYear . '-01-01', $rcYear . '-12-31']],
    'month' => ['label' => 'This month vs last month', 'a' => [date('Y-m-01', $rcLastMonth), date('Y-m-t', $rcLastMonth)], 'b' => [date('Y-m-01'), date('Y-m-t')]],
    '30d' => ['label' => 'Last 30 days vs the 30 days before', 'a' => [date('Y-m-d', strtotime('-59 days')), date('Y-m-d', strtotime('-30 days'))], 'b' => [date('Y-m-d', strtotime('-29 days')), $rcToday]],
    'custom' => ['label' => 'Custom dates'],
];
$rcChoice = isset($rcChoices[$_GET['compare'] ?? '']) ? (string)$_GET['compare'] : 'year';
if ($rcChoice === 'custom') {
    // Dates not filled in yet start as this year vs last year.
    $aStart = $rcDate($_GET['a_start'] ?? '') ?? $rcChoices['year']['a'][0];
    $aEnd = $rcDate($_GET['a_end'] ?? '') ?? $rcChoices['year']['a'][1];
    $bStart = $rcDate($_GET['b_start'] ?? '') ?? $rcChoices['year']['b'][0];
    $bEnd = $rcDate($_GET['b_end'] ?? '') ?? $rcChoices['year']['b'][1];
    if ($aStart > $aEnd) {
        [$aStart, $aEnd] = [$aEnd, $aStart];
    }
    if ($bStart > $bEnd) {
        [$bStart, $bEnd] = [$bEnd, $bStart];
    }
} else {
    [$aStart, $aEnd] = $rcChoices[$rcChoice]['a'];
    [$bStart, $bEnd] = $rcChoices[$rcChoice]['b'];
}

$rcDoctors = [];
$rcDoctorName = 'All doctors';
$rcDoctorRes = $conn->query("
    SELECT u.user_id, u.first_name, u.last_name
    FROM users u
    INNER JOIN role r ON r.role_id = u.role_id
    WHERE r.role_name = 'doctor'
    ORDER BY u.first_name ASC
");
if ($rcDoctorRes) {
    while ($row = $rcDoctorRes->fetch_assoc()) {
        $rcDoctors[] = $row;
        if ((int)$row['user_id'] === $rcDoctorId) {
            $rcDoctorName = trim($row['first_name'] . ' ' . $row['last_name']);
        }
    }
}
if ($rcDoctorName === 'All doctors') {
    $rcDoctorId = 0;
}
// The Trend & Predictive figures are clinic-wide, like the tab itself.
$rcAppliedDoctorId = $rcType === 'trend' ? 0 : $rcDoctorId;

$rcSections = report_compare_build($conn, $rcType, [$aStart, $aEnd], [$bStart, $bEnd], $rcAppliedDoctorId);
$rcSummary = [];
$rcBreakdowns = [];
foreach ($rcSections as $section) {
    if ($section['title'] === 'Summary') {
        $rcSummary = $section['rows'];
    } else {
        $rcBreakdowns[] = $section;
    }
}

// Column names say what the period is, as shortly as the dates allow: "2025" for a whole
// year, "Sep 2026" for a whole month, otherwise the two dates.
$rcLabel = function (string $start, string $end): string {
    $s = strtotime($start);
    $e = strtotime($end);
    if (date('Y', $s) === date('Y', $e)) {
        if (date('m-d', $s) === '01-01' && date('m-d', $e) === '12-31') return date('Y', $s);
        if (date('Y-m', $s) === date('Y-m', $e) && date('d', $s) === '01' && date('d', $e) === date('t', $e)) return date('M Y', $s);
        return date('M j', $s) . ' – ' . date('M j, Y', $e);
    }
    return date('M j, Y', $s) . ' – ' . date('M j, Y', $e);
};
$rcLabelA = $rcLabel($aStart, $aEnd);
$rcLabelB = $rcLabel($bStart, $bEnd);

$rcPeriodQuery = ['a_start' => $aStart, 'a_end' => $aEnd, 'b_start' => $bStart, 'b_end' => $bEnd];
// What the report tabs carry over: the choice, plus the dates when they were typed in.
$rcStateQuery = ['compare' => $rcChoice] + ($rcChoice === 'custom' ? $rcPeriodQuery : []);
$rcDoctorQuery = $rcDoctorId > 0 ? ['doctor_id' => $rcDoctorId] : [];
$rcUrl = fn(array $query) => 'admin.php?' . http_build_query(array_merge(['page' => 'reports', 'view' => 'compare', 'report_type' => $rcType], $rcDoctorQuery, $query));

// Back to the report the admin came from, with the period they were looking at.
$rcBackQuery = ['page' => 'reports', 'report_type' => $rcType] + $rcDoctorQuery;
$rcBackRange = (string)($_GET['range'] ?? '');
if (in_array($rcBackRange, ['7d', '30d', 'month'], true)) {
    $rcBackQuery['range'] = $rcBackRange;
} elseif ($rcBackRange === 'custom' && $rcDate($_GET['start_date'] ?? '') && $rcDate($_GET['end_date'] ?? '')) {
    $rcBackQuery += ['range' => 'custom', 'start_date' => $_GET['start_date'], 'end_date' => $_GET['end_date']];
}
$rcBackUrl = 'admin.php?' . http_build_query($rcBackQuery);
// Carried along so the back button still works after the periods are changed.
$rcReturn = array_intersect_key($rcBackQuery, array_flip(['range', 'start_date', 'end_date']));

$rcCsvUrl = '../assets/api/reports/compare.php?' . http_build_query(
    ['report_type' => $rcType, 'format' => 'csv'] + $rcPeriodQuery + ($rcAppliedDoctorId > 0 ? ['doctor_id' => $rcAppliedDoctorId] : [])
);

// For these, a higher number is the worse result — so an increase is shown in red.
$rcLowerIsBetter = ['Outstanding', 'No-Shows', 'No Show', 'Cancelled', 'Rescheduled', 'No-Show Rate', 'Cancellation Rate'];

$rcValue = function ($value, string $format): string {
    if ($value === null) return '—';
    if ($format === 'money') return '₱' . number_format((float)$value, 2);
    if ($format === 'pct') return $value . '%';
    return number_format((float)$value);
};
$rcChange = function (array $row) use ($rcValue): string {
    if ($row['change'] === null) return '—';
    $sign = $row['change'] > 0 ? '+' : ($row['change'] < 0 ? '−' : '');
    if ($row['format'] === 'pct') return $sign . abs($row['change']) . ' pts';
    return $sign . $rcValue(abs($row['change']), $row['format']);
};
$rcPct = fn(array $row): string => $row['pct_change'] === null ? '' : ($row['pct_change'] > 0 ? '+' : '') . $row['pct_change'] . '%';
$rcTone = function (array $row) use ($rcLowerIsBetter): string {
    if ($row['change'] === null || (float)$row['change'] == 0.0) return 'flat';
    $up = $row['change'] > 0;
    return ($up xor in_array($row['label'], $rcLowerIsBetter, true)) ? 'good' : 'bad';
};
$rcArrow = fn(array $row): string => $row['change'] === null || (float)$row['change'] == 0.0 ? 'bi-dash' : ($row['change'] > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short');
$rcHasData = false;
foreach ($rcSections as $section) {
    foreach ($section['rows'] as $row) {
        if ((float)$row['a'] != 0.0 || (float)$row['b'] != 0.0) {
            $rcHasData = true;
            break 2;
        }
    }
}
?>

<?php include __DIR__ . '/reports_styles.php'; ?>
<style>
/* A bar for each period (earlier one grey), on the same track the other reports use. */
.rc-bars { min-width: 140px; width: 28%; }
.rc-bars .clinic-report-bar-track { height: 6px; }
.rc-bars .clinic-report-bar-track + .clinic-report-bar-track { margin-top: 3px; }
#main .rc-bars .clinic-report-bar-fill.is-period-a { background: #94a3b8; }
@media (max-width: 767.98px) {
    .rc-bars { display: none; }
}
@media print {
    .rc-bars { display: none; }
}
</style>

<div class="dental-dashboard-shell clinic-reports-shell" id="clinicReportPrintArea">
    <div class="dental-dashboard-hero module-page-header">
        <div>
            <h4 class="mb-0">
                <span class="module-page-header__crumb">Clinic Reports</span> <span class="module-page-header__sep">&gt;</span>
                <span class="module-page-header__crumb"><?php echo htmlspecialchars($rcTypes[$rcType]['label']); ?></span> <span class="module-page-header__sep">&gt;</span>
                Compare
            </h4>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 clinic-report-no-print">
            <?php // Same Back button as the patient chart (.dp-back, assets/css/doctor-patients.css). ?>
            <a href="<?php echo htmlspecialchars($rcBackUrl); ?>" class="dp-back text-decoration-none" aria-label="Back to <?php echo htmlspecialchars($rcTypes[$rcType]['label']); ?>" title="Back to <?php echo htmlspecialchars($rcTypes[$rcType]['label']); ?>">
                <span class="dp-back__icon" aria-hidden="true"><i class="bi bi-arrow-left"></i></span>
                <span class="dp-back__text">Back</span>
            </a>
            <?php // "download" keeps the portal's in-page navigation (assets/js/main.js) from treating this file as a page. ?>
            <a href="<?php echo htmlspecialchars($rcCsvUrl); ?>" download class="btn btn-sm btn-secondary">
                <i class="bi bi-filetype-csv"></i> Export CSV
            </a>
            <button type="button" class="btn btn-sm btn-secondary clinic-report-print-btn" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>

    <div class="clinic-report-print-meta">
        <strong><?php echo htmlspecialchars(function_exists('email_clinic_name') ? email_clinic_name() : "Azucena's Dental Clinic"); ?></strong>
        — <?php echo htmlspecialchars($rcTypes[$rcType]['label']); ?> comparison
        &nbsp;|&nbsp; <?php echo htmlspecialchars($rcLabelA); ?> compared with <?php echo htmlspecialchars($rcLabelB); ?>
        &nbsp;|&nbsp; Doctor: <?php echo htmlspecialchars($rcAppliedDoctorId > 0 ? $rcDoctorName : 'All doctors'); ?>
        &nbsp;|&nbsp; Generated: <?php echo date('Y-m-d H:i'); ?>
    </div>

    <nav class="module-section-nav clinic-report-no-print" aria-label="Report to compare">
        <div class="module-section-nav__track">
            <ul class="nav nav-pills mb-0">
                <?php foreach ($rcTypes as $key => $info): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $rcType === $key ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($rcUrl(['report_type' => $key] + $rcStateQuery + $rcReturn)); ?>">
                            <i class="bi <?php echo $info['icon']; ?>"></i> <?php echo htmlspecialchars($info['label']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </nav>

    <form method="GET" action="admin.php" class="clinic-reports-filter card border-0 mb-3 clinic-report-no-print">
        <div class="card-body">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="view" value="compare">
            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($rcType); ?>">
            <?php foreach ($rcReturn as $name => $value): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars((string)$value); ?>">
            <?php endforeach; ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="rcChoice">Compare</label>
                    <select id="rcChoice" name="compare" class="form-select form-select-sm">
                        <?php foreach ($rcChoices as $choiceKey => $choice): ?>
                            <option value="<?php echo $choiceKey; ?>" <?php echo $rcChoice === $choiceKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($choice['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="rcDoctor">Doctor</label>
                    <select id="rcDoctor" name="doctor_id" class="form-select form-select-sm" <?php echo $rcType === 'trend' ? 'disabled title="Trend figures cover the whole clinic"' : ''; ?>>
                        <option value="">All doctors</option>
                        <?php foreach ($rcDoctors as $doc): ?>
                            <option value="<?php echo (int)$doc['user_id']; ?>" <?php echo $rcAppliedDoctorId === (int)$doc['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim($doc['first_name'] . ' ' . $doc['last_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
                </div>
            </div>
            <?php // Only "Custom dates" shows the date boxes: one line per period, earlier one first. ?>
            <div class="row g-2 align-items-end mt-1" id="rcCustomDates" <?php echo $rcChoice !== 'custom' ? 'hidden' : ''; ?>>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="rcAStart">First period</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="date" id="rcAStart" name="a_start" class="form-control form-control-sm" value="<?php echo htmlspecialchars($aStart); ?>" aria-label="First period from" required>
                        <span class="small text-muted">to</span>
                        <input type="date" name="a_end" class="form-control form-control-sm" value="<?php echo htmlspecialchars($aEnd); ?>" aria-label="First period to" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="rcBStart">Compared with</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="date" id="rcBStart" name="b_start" class="form-control form-control-sm" value="<?php echo htmlspecialchars($bStart); ?>" aria-label="Second period from" required>
                        <span class="small text-muted">to</span>
                        <input type="date" name="b_end" class="form-control form-control-sm" value="<?php echo htmlspecialchars($bEnd); ?>" aria-label="Second period to" required>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <script>
    (function () {
        var choice = document.getElementById('rcChoice');
        var dates = document.getElementById('rcCustomDates');
        if (!choice || !dates) return;
        // Ready-made choices need no dates: hide the boxes and keep them out of the address.
        function sync() {
            var custom = choice.value === 'custom';
            dates.hidden = !custom;
            dates.querySelectorAll('input').forEach(function (input) { input.disabled = !custom; });
        }
        choice.addEventListener('change', function () {
            sync();
            // requestSubmit (not submit) so the portal's in-page navigation handles it like Apply.
            if (choice.value !== 'custom') choice.form.requestSubmit();
        });
        sync();
    })();
    </script>

    <?php if (!$rcHasData): ?>
        <div class="alert alert-info">There are no records in either period. Try a different comparison.</div>
    <?php endif; ?>

    <?php
    $rcToneClass = ['good' => 'text-success', 'bad' => 'text-danger', 'flat' => 'text-muted'];
    $rcChangeText = function (array $row, string $flat) use ($rcTone, $rcChange, $rcPct): string {
        if ($row['change'] === null) return $row['a'] === null && $row['b'] === null ? '' : 'Not comparable';
        if ($rcTone($row) === 'flat') return $flat;
        return $rcChange($row) . ($rcPct($row) !== '' ? ' (' . $rcPct($row) . ')' : '');
    };
    ?>

    <p class="dental-section-eyebrow">Summary</p>
    <div class="row g-3 mb-3">
        <?php foreach ($rcSummary as $row): ?>
            <div class="col-6 col-xl-3">
                <div class="card dental-kpi-card p-3 h-100">
                    <div class="dental-kpi-label"><?php echo htmlspecialchars($row['label']); ?></div>
                    <div class="dental-kpi-value"><?php echo htmlspecialchars($rcValue($row['b'], $row['format'])); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars($rcLabelA); ?>: <?php echo htmlspecialchars($rcValue($row['a'], $row['format'])); ?></div>
                    <div class="small fw-semibold <?php echo $rcToneClass[$rcTone($row)]; ?>">
                        <?php if ($rcTone($row) !== 'flat'): ?><i class="bi <?php echo $rcArrow($row); ?>"></i><?php endif; ?>
                        <?php echo htmlspecialchars($rcChangeText($row, 'No change')); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="dental-section-eyebrow">Breakdown</p>
    <?php foreach ($rcBreakdowns as $section): ?>
        <?php
        $rcMax = 0.0;
        foreach ($section['rows'] as $row) {
            $rcMax = max($rcMax, (float)$row['a'], (float)$row['b']);
        }
        ?>
        <div class="row g-3 mb-3">
            <div class="col-12">
                <div class="card dental-surface-card">
                    <div class="card-body">
                        <h5 class="card-title mb-3"><?php echo htmlspecialchars($section['title']); ?></h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" data-skip-table-search="1">
                                <thead>
                                    <tr>
                                        <th><?php echo htmlspecialchars($section['title']); ?></th>
                                        <th class="text-end"><?php echo htmlspecialchars($rcLabelA); ?></th>
                                        <th class="text-end"><?php echo htmlspecialchars($rcLabelB); ?></th>
                                        <th class="rc-bars"></th>
                                        <th class="text-end">Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($section['rows'])): ?>
                                        <tr><td colspan="5" class="text-muted text-center py-3">No data in either period.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($section['rows'] as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($row['label']); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars($rcValue($row['a'], $row['format'])); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars($rcValue($row['b'], $row['format'])); ?></td>
                                            <td class="rc-bars">
                                                <?php foreach (['a', 'b'] as $side): ?>
                                                    <div class="clinic-report-bar-track">
                                                        <div class="clinic-report-bar-fill <?php echo $side === 'a' ? 'is-period-a' : ''; ?>" style="width: <?php echo $rcMax > 0 ? round((float)$row[$side] / $rcMax * 100, 1) : 0; ?>%"></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="text-end text-nowrap fw-semibold <?php echo $rcToneClass[$rcTone($row)]; ?>"><?php echo htmlspecialchars($rcChangeText($row, '0')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
