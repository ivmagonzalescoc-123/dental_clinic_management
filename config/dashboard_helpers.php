<?php
/**
 * Shared helpers for role dashboards (patient, doctor, secretary, admin).
 */

if (!function_exists('dashboard_scalar_int')) {
    function dashboard_scalar_int(mysqli $conn, string $sql, ?string $types = null, array $params = []): int
    {
        if ($types === null) {
            $res = $conn->query($sql);
            if ($res && ($row = $res->fetch_row())) {
                return (int)($row[0] ?? 0);
            }
            return 0;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $val = 0;
        if ($res && ($row = $res->fetch_row())) {
            $val = (int)($row[0] ?? 0);
        }
        $stmt->close();
        return $val;
    }
}

if (!function_exists('dashboard_appt_status_badge_class')) {
    function dashboard_appt_status_badge_class(string $status): string
    {
        switch ($status) {
            case 'completed':
            case 'records_complete':
                return 'success';
            case 'cancelled':
            case 'no_show':
                return 'danger';
            case 'arrived':
                return 'success';
            case 'checked_in':
                return 'primary';
            case 'confirmed':
                return 'info';
            case 'in_progress':
                return 'warning';
            case 'awaiting_secretary_records':
            case 'completed_by_doctor':
                return 'warning text-dark';
            case 'scheduled':
            case 'rescheduled':
            default:
                return 'secondary';
        }
    }
}

if (!function_exists('dashboard_format_status_label')) {
    function dashboard_format_status_label(string $status): string
    {
        $labels = [
            'arrived' => 'Arrived',
            'checked_in' => 'Checked In',
            'in_progress' => 'In Progress',
            'awaiting_secretary_records' => 'Awaiting Records',
            'completed_by_doctor' => 'Doctor Finished',
            'records_complete' => 'Records Complete',
            'no_show' => 'No Show',
        ];
        if (isset($labels[$status])) {
            return $labels[$status];
        }
        return ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('dashboard_format_time')) {
    function dashboard_format_time(?string $time): string
    {
        if (!$time) {
            return '';
        }
        $ts = strtotime($time);
        if (!$ts) {
            return '';
        }
        return date('h:i A', $ts);
    }
}

if (!function_exists('dashboard_last7_series')) {
    /**
     * @return array{0: string[], 1: int[], 2: string[]}
     */
    function dashboard_last7_series(mysqli $conn, ?int $userId = null): array
    {
        $today = date('Y-m-d');
        $last7Start = date('Y-m-d', strtotime('-6 days'));
        $seriesLabels = [];
        $chartLabels = [];
        $seriesValues = [];

        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days'));
            $seriesLabels[] = $d;
            $chartLabels[] = date('M d', strtotime($d));
            $seriesValues[] = 0;
        }

        $sql = 'SELECT appointment_date AS dt, COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ?';
        $types = 'ss';
        $params = [$last7Start, $today];
        if ($userId) {
            $sql .= ' AND user_id = ?';
            $types .= 'i';
            $params[] = $userId;
        }
        $sql .= ' GROUP BY appointment_date ORDER BY appointment_date ASC';

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $map = [];
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $map[$r['dt']] = (int)($r['c'] ?? 0);
                }
            }
            foreach ($seriesLabels as $idx => $d) {
                if (isset($map[$d])) {
                    $seriesValues[$idx] = $map[$d];
                }
            }
            $stmt->close();
        }

        return [$seriesLabels, $seriesValues, $chartLabels];
    }
}

if (!function_exists('dashboard_render_appt_chart_script')) {
    function dashboard_render_appt_chart_script(string $elementId, array $chartLabels, array $seriesValues, string $color = '#4154f1', string $chartType = 'area'): void
    {
        $safeId = htmlspecialchars($elementId, ENT_QUOTES, 'UTF-8');
        $labelsJson = json_encode($chartLabels);
        $valuesJson = json_encode($seriesValues);
        $colorJson = json_encode($color);
        echo <<<HTML
<script>
(function () {
    function renderChart() {
        if (typeof ApexCharts === 'undefined') return;
        var el = document.getElementById('{$safeId}');
        if (!el) return;
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        var textColor = isDark ? '#94a3b8' : '#6c757d';
        var gridColor = isDark ? '#2f3d52' : '#e9ecef';
        var opts = {
            chart: { type: 'area', height: 260, toolbar: { show: false }, foreColor: textColor },
            series: [{ name: 'Appointments', data: {$valuesJson} }],
            legend: { show: true, position: 'top', horizontalAlign: 'right', fontSize: '12px', labels: { colors: textColor } },
            xaxis: { categories: {$labelsJson}, axisBorder: { show: true }, axisTicks: { show: true } },
            stroke: { curve: 'smooth', width: 3 },
            colors: [{$colorJson}],
            fill: { type: 'solid', opacity: 0 },
            dataLabels: { enabled: false },
            yaxis: { min: 0, forceNiceScale: true, show: true },
            grid: { show: true, strokeDashArray: 4, borderColor: gridColor }
        };
        new ApexCharts(el, opts).render();
    }
    // This script tag is emitted inline wherever the caller's page includes it — often
    // well before the apexcharts.min.js <script src> that loads near the footer, so
    // ApexCharts isn't defined yet at parse time. Deferring to DOMContentLoaded lets the
    // later, still-synchronous library script finish executing first.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderChart);
    } else {
        renderChart();
    }
})();
</script>
HTML;
    }
}

if (!function_exists('dashboard_render_kpi_card')) {
    function dashboard_render_kpi_card(string $label, string $value, string $icon, string $tone = ''): void
    {
        $toneClass = $tone !== '' ? ' dental-kpi-card--' . preg_replace('/[^a-z]/', '', strtolower($tone)) : '';
        $valueClass = '';
        if (preg_match('/^₱/', $value)) {
            $valueClass = ' is-compact';
        } elseif (preg_match('/[A-Za-z]/', $value)) {
            $valueClass = ' is-text';
        }
        echo '<div class="card dental-kpi-card h-100' . htmlspecialchars($toneClass, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="dental-kpi-card__inner">';
        echo '<div class="dental-kpi-icon" aria-hidden="true"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></div>';
        echo '<div class="dental-kpi-card__meta">';
        echo '<h6 class="dental-kpi-card__label">' . htmlspecialchars($label) . '</h6>';
        echo '<div class="dental-kpi-card__value' . $valueClass . '">' . $value . '</div>';
        echo '</div></div></div>';
    }
}

if (!function_exists('dashboard_render_empty_state')) {
    function dashboard_render_empty_state(string $message, string $icon = 'bi-inbox', ?string $ctaLabel = null, ?string $ctaHref = null): void
    {
        echo '<div class="dental-empty-state">';
        echo '<div class="dental-empty-state__icon" aria-hidden="true"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></div>';
        echo '<p class="dental-empty-state__message">' . htmlspecialchars($message) . '</p>';
        if ($ctaLabel !== null && $ctaHref !== null) {
            echo '<a class="dental-empty-state__cta" href="' . htmlspecialchars($ctaHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($ctaLabel) . '</a>';
        }
        echo '</div>';
    }
}
