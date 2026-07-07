(function () {
    'use strict';

    var palette = {
        sky: ['#0ea5e9', '#38bdf8', '#0284c7', '#0369a1'],
        teal: ['#0d9488', '#14b8a6', '#2dd4bf', '#5eead4'],
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

    function baseConfig(height) {
        return {
            chart: {
                height: height,
                toolbar: { show: false },
                fontFamily: 'inherit',
                foreColor: '#64748b',
                animations: {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 600
                }
            },
            grid: {
                borderColor: '#e8eef5',
                strokeDashArray: 4,
                padding: { left: 8, right: 12 }
            },
            tooltip: {
                theme: 'light',
                style: { fontSize: '12px' }
            },
            legend: {
                fontSize: '12px',
                fontWeight: 500,
                labels: { colors: '#475569' }
            }
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
                labels: { rotate: -45, style: { fontSize: '11px', colors: '#64748b' } },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                labels: { formatter: function (v) { return Math.round(v); } }
            },
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
                labels: { rotate: -45, style: { fontSize: '11px', colors: '#64748b' } },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                labels: { formatter: function (v) { return Math.round(v); } }
            },
            colors: ['#94a3b8', '#0d9488'],
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

    function buildHorizontalBarOptions(labels, values, seriesName) {
        var colors = labels.map(function (_, i) {
            return palette.categorical[i % palette.categorical.length];
        });
        return enhanceOptions({
            chart: { type: 'bar' },
            series: [{ name: seriesName, data: values }],
            xaxis: {
                categories: labels,
                labels: { style: { fontSize: '11px', colors: '#64748b' } }
            },
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
                style: { fontSize: '11px', fontWeight: 600, colors: ['#fff'] },
                background: { enabled: false }
            },
            colors: colors,
            legend: { show: false },
            grid: { padding: { right: 24 } }
        }, 240);
    }

    function buildDonutOptions(labels, values) {
        return enhanceOptions({
            chart: { type: 'donut' },
            series: values,
            labels: labels,
            colors: palette.outcome,
            legend: { position: 'bottom' },
            dataLabels: {
                enabled: true,
                formatter: function (val) { return Math.round(val) + '%'; },
                style: { fontSize: '11px', fontWeight: 600 }
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '62%',
                        labels: {
                            show: true,
                            name: { fontSize: '12px' },
                            value: { fontSize: '18px', fontWeight: 700, color: '#1e293b' },
                            total: {
                                show: true,
                                label: 'Total',
                                fontSize: '12px',
                                formatter: function (w) {
                                    return w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
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
                labels: { style: { fontSize: '12px', fontWeight: 600, colors: '#475569' } }
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
                style: { fontSize: '11px', fontWeight: 700, colors: ['#1e293b'] },
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
                    formatter: function (val) {
                        return '₱' + Number(val).toLocaleString();
                    }
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

    function openExpanded(chartId) {
        var entry = registry[chartId] || (window.AdminCharts._registry && window.AdminCharts._registry[chartId]);
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
        renderNoData: renderNoData,
        mount: mount,
        bindExpandButtons: bindExpandButtons
    };
})();
