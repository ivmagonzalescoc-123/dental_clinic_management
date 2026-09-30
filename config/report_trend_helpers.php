<?php
/**
 * Appointment-based trend & forecast helpers for the admin "Trend & Predictive" report.
 *
 * Everything here works from appointment counts only (the clinic has no inventory data).
 * Forecasts use COMPLETE months only: the month in progress is partial and would read
 * as a sudden drop, so it is reported separately as "this month's pace".
 */

if (!function_exists('trend_visit_statuses')) {
    /** Appointments where the visit actually happened (doctor finished the session). */
    function trend_visit_statuses(): array
    {
        return ['completed', 'records_complete', 'completed_by_doctor', 'awaiting_secretary_records'];
    }

    /** Still upcoming / not yet resolved. */
    function trend_open_statuses(): array
    {
        return ['scheduled', 'confirmed', 'arrived', 'checked_in', 'in_progress'];
    }

    function trend_median(array $values): float
    {
        $values = array_values($values);
        $n = count($values);
        if ($n === 0) return 0.0;
        sort($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float)$values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    function trend_sql_list(array $values): string
    {
        return "'" . implode("','", $values) . "'";
    }

    /**
     * 'Y-m' keys for the last $count COMPLETE months before $today, oldest first.
     */
    function trend_complete_month_keys(int $count, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $firstOfThisMonth = $today->modify('first day of this month');
        $keys = [];
        for ($i = $count; $i >= 1; $i--) {
            $keys[] = $firstOfThisMonth->modify("-{$i} month")->format('Y-m');
        }
        return $keys;
    }

    /** Values for each key in $keys (missing months count as 0). */
    function trend_fill(array $keys, array $byKey): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[] = (int)($byKey[$k] ?? 0);
        }
        return $out;
    }

    /**
     * Forecast the next value of a monthly series.
     *
     * Leading zero months are dropped (the clinic may have started using the system
     * part-way through the window). With 3+ months: least-squares line over the last
     * up-to-6 months, blended 60/40 with the median of the last 3 months so a single
     * unusual month can't swing it much while real growth still shows.
     * With 1-2 months: their mean. The range is +/- the typical miss of that line
     * (RMSE), at least 10% of the estimate.
     *
     * @return array{value:int, low:int, high:int, months:int, method:string}|null
     *         null when there is no history at all
     */
    function trend_forecast(array $series): ?array
    {
        while ($series && (int)reset($series) === 0) {
            array_shift($series);
        }
        $series = array_slice(array_values(array_map('intval', $series)), -6);
        $n = count($series);
        if ($n === 0) {
            return null;
        }
        $mean = array_sum($series) / $n;

        if ($n < 3) {
            $value = $mean;
            $spread = max(1.0, $mean * 0.25);
            $method = 'average';
        } else {
            $xMean = ($n - 1) / 2;
            $num = 0.0;
            $den = 0.0;
            foreach ($series as $x => $y) {
                $num += ($x - $xMean) * ($y - $mean);
                $den += ($x - $xMean) ** 2;
            }
            $slope = $den > 0 ? $num / $den : 0.0;
            $intercept = $mean - $slope * $xMean;
            $lineNext = $intercept + $slope * $n;
            $value = 0.6 * $lineNext + 0.4 * trend_median(array_slice($series, -3));

            $sq = 0.0;
            foreach ($series as $x => $y) {
                $sq += ($y - ($intercept + $slope * $x)) ** 2;
            }
            $spread = max(sqrt($sq / $n), $value * 0.10, 1.0);
            $method = 'trend';
        }

        $value = max(0.0, $value);
        return [
            'value' => (int)round($value),
            'low' => (int)max(0, floor($value - $spread)),
            'high' => (int)ceil($value + $spread),
            'months' => $n,
            'method' => $method,
        ];
    }

    /**
     * Direction of a monthly series: median of the last 3 months vs the median of the 3
     * before (medians, so one unusual month doesn't read as a trend). Small counts need
     * a real difference (>= 2 bookings a month), not just a %.
     *
     * @return array{label:string, change:float|null, base:float}  label: rising|falling|stable|new;
     *         base = the earlier monthly level the change is measured from
     */
    function trend_direction(array $series): array
    {
        $series = array_values(array_map('intval', $series));
        $recent = array_slice($series, -3);
        $before = array_slice($series, -6, max(0, count($series) - 3));
        $before = array_slice($before, -3);
        $recentMid = trend_median($recent);
        $beforeMid = trend_median($before);

        if ($beforeMid <= 0) {
            return ['label' => array_sum($recent) > 0 ? 'new' : 'stable', 'change' => null, 'base' => 0.0];
        }
        $change = ($recentMid - $beforeMid) / $beforeMid;
        $diff = $recentMid - $beforeMid;
        if ($change >= 0.15 && $diff >= 2) {
            $label = 'rising';
        } elseif ($change <= -0.15 && $diff <= -2) {
            $label = 'falling';
        } else {
            $label = 'stable';
        }
        return ['label' => $label, 'change' => round($change * 100, 1), 'base' => $beforeMid];
    }

    /** Clinic days (Monday to Saturday) in the month containing $date. */
    function trend_clinic_days_in_month(DateTimeImmutable $date): int
    {
        $d = $date->modify('first day of this month');
        $end = $date->modify('last day of this month');
        $days = 0;
        while ($d <= $end) {
            if ((int)$d->format('N') !== 7) {
                $days++;
            }
            $d = $d->modify('+1 day');
        }
        return $days;
    }

    /** Share as a whole percent, or null when there's nothing to divide by. */
    function trend_rate(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int)round($part / $whole * 100) : null;
    }
}
