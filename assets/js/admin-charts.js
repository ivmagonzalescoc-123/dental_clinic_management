(function () {
    'use strict';

    var palette = {
        sky: ['#0ea5e9', '#38bdf8', '#0284c7', '#0369a1'],
        teal: ['#1b7aa3', '#3a9fc0', '#6bbad6', '#9dd2e6'],
        categorical: ['#0ea5e9', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b', '#ec4899'],
        outcome: ['#0ea5e9', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#94a3b8'],
        money: ['#059669', '#ef4444']
    };

    var registry = {};
    var modalChart = null;
    var modalEl = null;
    var modalTitleEl = null;
    var modalHostEl = null;

    function deepMerge(target, source) {
        var out = Object.assign({}, target);
        Object.keys(source || {}).forEach(function (key) {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                out[key] = deepMerge(out[key] || {}, source[key]);
            } else {
                out[key] = source[key];
            }
        });
        return out;
    }

    function isDarkTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function baseConfig(height) {
        var dark = isDarkTheme();
        return {
            chart: {
                height: height,
                toolbar: { show: false },
                fontFamily: 'inherit',
                foreColor: dark ? '#94a3b8' : '#64748b',
                animations: {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 600
                }
            },
            grid: {
                borderColor: dark ? '#2f3d52' : '#e8eef5',
                strokeDashArray: 4,
                padding: { left: 8, right: 12 }
            },
            tooltip: {
                theme: dark ? 'dark' : 'light',
                style: { fontSize: '12px' }
            },
            legend: {
                fontSize: '12px',
                fontWeight: 500,
                labels: { colors: dark ? '#cbd5e1' : '#475569' }
            }
        };
    }

    /** Whole numbers for counts (appointments, patients, bookings). */
    function formatCount(val) {
        if (val == null || isNaN(val)) return '';
        return Math.round(Number(val)).toLocaleString();
    }

    /** Largest value across a series list; stacked charts are sized by their totals. */
    function seriesMax(series, stacked) {
        if (!Array.isArray(series) || !series.length) return 0;
        if (typeof series[0] === 'number' || series[0] == null) {
            return Math.max.apply(null, series.map(function (v) { return Number(v) || 0; }).concat([0]));
        }
        var lists = series.map(function (s) { return (s && s.data) || []; });
        var len = Math.max.apply(null, lists.map(function (d) { return d.length; }).concat([0]));
        var max = 0;
        for (var i = 0; i < len; i++) {
            if (stacked) {
                var sum = 0;
                lists.forEach(function (d) { sum += Number(d[i]) || 0; });
                max = Math.max(max, sum);
            } else {
                lists.forEach(function (d) { max = Math.max(max, Number(d[i]) || 0); });
            }
        }
        return max;
    }

    /**
     * Axis settings for count data: ticks always land on whole numbers (1, 2, 5, 10...
     * steps) with no duplicates. Rounding the labels alone isn't enough - with a max of 2
     * ApexCharts steps by 0.5 and the rounded labels repeat ("0, 0, 1, 1, 2").
     * Use for yaxis on column/line charts and xaxis on horizontal bars.
     */
    function countAxis(series, opts) {
        opts = opts || {};
        var max = Math.max(0, Math.ceil(seriesMax(series, !!opts.stacked)));
        var top, ticks;
        if (max <= 5) {
            top = Math.max(max, 1);
            ticks = top;
        } else {
            var raw = max / 5;
            var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
            var norm = raw / mag;
            var step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
            top = Math.ceil(max / step) * step;
            ticks = Math.round(top / step);
        }
        return {
            min: 0,
            max: top,
            tickAmount: ticks,
            forceNiceScale: false,
            decimalsInFloat: 0,
            labels: { formatter: formatCount }
        };
    }

    function barGradient(colorFrom, colorTo) {
        return {
            type: 'gradient',
            gradient: {
                shade: 'light',
                type: 'vertical',
                shadeIntensity: 0.25,
                gradientToColors: [colorTo || colorFrom],
                opacityFrom: 0.95,
                opacityTo: 0.75,
                stops: [0, 100]
            }
        };
    }

    function enhanceOptions(options, height) {
        var merged = deepMerge(baseConfig(height), options || {});
        if (!merged.chart) merged.chart = {};
        merged.chart.height = height;
        return merged;
    }

    function buildVerticalBarOptions(labels, values, seriesName, colorPair) {
        var from = (colorPair && colorPair[0]) || palette.sky[0];
        var to = (colorPair && colorPair[1]) || palette.sky[1];
        return enhanceOptions({
            chart: { type: 'bar' },
            series: [{ name: seriesName, data: values }],
            xaxis: {
                categories: labels,
                labels: { rotate: -45, style: { fontSize: '11px' } },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: countAxis(values),
            tooltip: { y: { formatter: formatCount } },
            plotOptions: {
                bar: {
                    borderRadius: 8,
                    columnWidth: '52%',
                    dataLabels: { position: 'top' }
                }
            },
            dataLabels: { enabled: false },
            colors: [from],
            fill: barGradient(from, to),
            stroke: { show: true, width: 2, colors: ['transparent'] }
        }, 240);
    }

    function buildGroupedBarOptions(labels, bookedValues, completedValues) {
        return enhanceOptions({
            chart: { type: 'bar' },
            series: [
                { name: 'Booked', data: bookedValues },
                { name: 'Completed', data: completedValues }
            ],
            xaxis: {
                categories: labels,
                labels: { rotate: -45, style: { fontSize: '11px' } },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: countAxis([{ data: bookedValues }, { data: completedValues }]),
            tooltip: { shared: true, intersect: false, y: { formatter: formatCount } },
            colors: ['#94a3b8', '#1b7aa3'],
            plotOptions: {
                bar: {
                    horizontal: false,
                    borderRadius: 6,
                    columnWidth: '55%'
                }
            },
            dataLabels: { enabled: false },
            legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px' }
        }, 240);
    }

    function formatMoney(val) {
        return '₱' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function buildHorizontalBarOptions(labels, values, seriesName, opts) {
        opts = opts || {};
        var isMoney = !!opts.money;
        var colors = labels.map(function (_, i) {
            return palette.categorical[i % palette.categorical.length];
        });
        return enhanceOptions({
            chart: { type: 'bar' },
            series: [{ name: seriesName, data: values }],
            // Horizontal bars: the xaxis is the value axis. Counts get whole-number
            // ticks; money gets compact peso ticks.
            xaxis: Object.assign(
                { categories: labels },
                isMoney ? { labels: { style: { fontSize: '11px' }, formatter: formatCompactMoney } }
                        : Object.assign(countAxis(values), { labels: { style: { fontSize: '11px' }, formatter: formatCount } })
            ),
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 8,
                    barHeight: '62%',
                    distributed: true,
                    dataLabels: { position: 'left' }
                }
            },
            dataLabels: {
                enabled: true,
                formatter: isMoney ? formatMoney : formatCount,
                style: { fontSize: '11px', fontWeight: 600, colors: ['#fff'] },
                background: { enabled: false }
            },
            tooltip: {
                y: { formatter: isMoney ? formatMoney : formatCount }
            },
            colors: colors,
            legend: { show: false },
            grid: { padding: { right: 24 } },
            // Long service names + full "₱1,000.00" in-bar labels don't fit a
            // phone-width card: the amounts got clipped ("₱1,0") and names
            // truncated. Cap label width, compact the axis, and drop the in-bar
            // labels there (the tooltip still shows the exact figure on tap).
            responsive: [{
                breakpoint: 576,
                options: {
                    dataLabels: { enabled: false },
                    yaxis: { labels: { maxWidth: 110, style: { fontSize: '10px' } } },
                    xaxis: {
                        labels: {
                            style: { fontSize: '10px' },
                            formatter: isMoney ? formatCompactMoney : formatCount
                        }
                    },
                    grid: { padding: { right: 8 } }
                }
            }]
        }, 240);
    }

    function formatCompactMoney(val) {
        var n = Number(val);
        if (Math.abs(n) >= 1000000) return '₱' + (n / 1000000).toFixed(n % 1000000 === 0 ? 0 : 1) + 'M';
        if (Math.abs(n) >= 1000) return '₱' + (n / 1000).toFixed(n % 1000 === 0 ? 0 : 1) + 'k';
        return '₱' + Math.round(n);
    }

    function buildDonutOptions(labels, values, opts) {
        opts = opts || {};
        var isMoney = !!opts.money;
        return enhanceOptions({
            chart: { type: 'donut' },
            series: values,
            labels: labels,
            colors: opts.colors || palette.outcome,
            legend: { position: 'bottom' },
            dataLabels: {
                enabled: true,
                formatter: function (val) { return Math.round(val) + '%'; },
                style: { fontSize: '11px', fontWeight: 600 }
            },
            tooltip: {
                y: { formatter: isMoney ? formatMoney : formatCount }
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '62%',
                        labels: {
                            show: true,
                            name: { fontSize: '12px' },
                            value: {
                                fontSize: '18px',
                                fontWeight: 700,
                                color: isDarkTheme() ? '#e2e8f0' : '#1e293b',
                                formatter: function (val) { return isMoney ? formatMoney(val) : formatCount(val); }
                            },
                            total: {
                                show: true,
                                label: 'Total',
                                fontSize: '12px',
                                formatter: function (w) {
                                    var sum = w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
                                    return isMoney ? formatMoney(sum) : formatCount(sum);
                                }
                            }
                        }
                    }
                }
            },
            stroke: { width: 2, colors: ['#fff'] }
        }, 240);
    }

    function buildMoneyBarOptions(collected, outstanding) {
        return enhanceOptions({
            chart: { type: 'bar' },
            series: [{ name: 'Amount (PHP)', data: [collected, outstanding] }],
            xaxis: {
                categories: ['Collected', 'Outstanding'],
                labels: { style: { fontSize: '12px', fontWeight: 600 } }
            },
            plotOptions: {
                bar: {
                    borderRadius: 10,
                    columnWidth: '42%',
                    distributed: true,
                    dataLabels: { position: 'top' }
                }
            },
            dataLabels: {
                enabled: true,
                offsetY: -6,
                style: { fontSize: '11px', fontWeight: 700, colors: [isDarkTheme() ? '#e2e8f0' : '#1e293b'] },
                formatter: function (val) {
                    return '₱' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            },
            colors: palette.money,
            fill: {
                type: 'gradient',
                gradient: {
                    shade: 'light',
                    type: 'vertical',
                    shadeIntensity: 0.3,
                    gradientToColors: ['#34d399', '#f87171'],
                    stops: [0, 100]
                }
            },
            legend: { show: false },
            yaxis: {
                labels: {
                    formatter: formatCompactMoney
                }
            }
        }, 240);
    }

    function renderNoData(id, label) {
        var el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = '<div class="dental-chart-empty"><i class="bi bi-bar-chart"></i><span>No ' + label + ' for this period.</span></div>';
        var card = el.closest('.dental-chart-card');
        if (card) {
            var btn = card.querySelector('[data-chart-expand]');
            if (btn) btn.style.display = 'none';
        }
    }

    function mount(id, options, title) {
        var el = document.getElementById(id);
        if (!el || typeof ApexCharts === 'undefined') return null;
        el.innerHTML = '';
        var card = el.closest('.dental-chart-card');
        if (card) {
            var btn = card.querySelector('[data-chart-expand]');
            if (btn) btn.style.display = '';
        }
        var chart = new ApexCharts(el, options);
        chart.render();
        registry[id] = { options: options, title: title || '', chart: chart };
        return chart;
    }

    function ensureModal() {
        if (modalEl) return;
        modalEl = document.getElementById('adminChartExpandModal');
        modalTitleEl = document.getElementById('adminChartExpandTitle');
        modalHostEl = document.getElementById('adminChartExpandHost');
        if (!modalEl || !modalHostEl) return;

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (modalChart) {
                modalChart.destroy();
                modalChart = null;
            }
            modalHostEl.innerHTML = '';
        });
    }

    function getEntry(chartId) {
        return registry[chartId] || (window.AdminCharts._registry && window.AdminCharts._registry[chartId]) || null;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function formatCellValue(val) {
        if (typeof val !== 'number' || isNaN(val)) return escapeHtml(val);
        return escapeHtml(val.toLocaleString(undefined, { maximumFractionDigits: 2 }));
    }

    function buildPrintTableHtml(entry) {
        var opts = entry.options || {};
        var categories = (opts.xaxis && opts.xaxis.categories) || opts.labels || [];
        var isDonut = opts.chart && (opts.chart.type === 'donut' || opts.chart.type === 'pie');
        var html = '<table class="table table-sm mb-0"><thead><tr>';

        if (isDonut) {
            var values = opts.series || [];
            html += '<th>' + escapeHtml(entry.title || 'Label') + '</th><th class="text-end">Value</th></tr></thead><tbody>';
            if (!categories.length) {
                html += '<tr><td colspan="2" class="text-muted text-center py-2">No data for this period.</td></tr>';
            }
            categories.forEach(function (label, i) {
                html += '<tr><td>' + escapeHtml(label) + '</td><td class="text-end">' + formatCellValue(values[i]) + '</td></tr>';
            });
            html += '</tbody></table>';
            return html;
        }

        var seriesList = opts.series || [];
        html += '<th>' + escapeHtml(entry.title || 'Category') + '</th>';
        seriesList.forEach(function (s) {
            html += '<th class="text-end">' + escapeHtml(s.name || 'Value') + '</th>';
        });
        html += '</tr></thead><tbody>';
        if (!categories.length) {
            html += '<tr><td colspan="' + (seriesList.length + 1) + '" class="text-muted text-center py-2">No data for this period.</td></tr>';
        }
        categories.forEach(function (cat, i) {
            html += '<tr><td>' + escapeHtml(cat) + '</td>';
            seriesList.forEach(function (s) {
                var v = (s.data && s.data[i] !== undefined) ? s.data[i] : '';
                html += '<td class="text-end">' + formatCellValue(v) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderPrintTables() {
        document.querySelectorAll('.clinic-simple-chart').forEach(function (el) {
            var frame = el.closest('.dental-chart-frame');
            if (!frame) return;
            var existing = frame.querySelector('.dental-chart-print-table');
            if (existing) existing.remove();
            var entry = getEntry(el.id);
            if (!entry) return;
            var wrap = document.createElement('div');
            wrap.className = 'dental-chart-print-table';
            wrap.innerHTML = buildPrintTableHtml(entry);
            frame.appendChild(wrap);
        });
    }

    window.addEventListener('beforeprint', renderPrintTables);

    function openExpanded(chartId) {
        var entry = getEntry(chartId);
        if (!entry || typeof ApexCharts === 'undefined') return;
        ensureModal();
        if (!modalEl || !modalHostEl) return;

        if (modalTitleEl) {
            modalTitleEl.textContent = entry.title || 'Chart';
        }
        modalHostEl.innerHTML = '';

        var expandedOptions = enhanceOptions(JSON.parse(JSON.stringify(entry.options)), 420);
        modalChart = new ApexCharts(modalHostEl, expandedOptions);
        modalChart.render();

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
        }
    }

    function bindExpandButtons() {
        document.querySelectorAll('[data-chart-expand]').forEach(function (btn) {
            if (btn.dataset.chartExpandBound === '1') return;
            btn.dataset.chartExpandBound = '1';
            btn.addEventListener('click', function () {
                openExpanded(btn.getAttribute('data-chart-expand'));
            });
        });
    }

    window.AdminCharts = {
        palette: palette,
        enhanceOptions: enhanceOptions,
        buildVerticalBarOptions: buildVerticalBarOptions,
        buildGroupedBarOptions: buildGroupedBarOptions,
        buildHorizontalBarOptions: buildHorizontalBarOptions,
        buildDonutOptions: buildDonutOptions,
        buildMoneyBarOptions: buildMoneyBarOptions,
        countAxis: countAxis,
        formatCount: formatCount,
        formatMoney: formatMoney,
        formatCompactMoney: formatCompactMoney,
        renderNoData: renderNoData,
        mount: mount,
        bindExpandButtons: bindExpandButtons,
        getEntry: getEntry,
        renderPrintTables: renderPrintTables
    };
})();
