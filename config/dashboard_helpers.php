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
            'checked_in' => 'Arrived',
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
        $typeJson = json_encode($chartType);
        echo <<<HTML
<script>
(function () {
    if (typeof ApexCharts === 'undefined') return;
    const el = document.getElementById('{$safeId}');
    if (!el) return;
    const opts = {
        chart: { type: {$typeJson}, height: 260, toolbar: { show: false } },
        series: [{ name: 'Appointments', data: {$valuesJson} }],
        xaxis: { categories: {$labelsJson} },
        stroke: { curve: 'smooth', width: 3 },
        colors: [{$colorJson}],
        dataLabels: { enabled: false },
        yaxis: { min: 0, forceNiceScale: true },
        grid: { strokeDashArray: 4 }
    };
    new ApexCharts(el, opts).render();
})();
</script>
HTML;
    }
}
