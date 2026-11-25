const submittedForms = new WeakSet();

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get' || form.target === '_blank') {
        return;
    }

    if (submittedForms.has(form)) {
        event.preventDefault();
        return;
    }

    submittedForms.add(form);
    const submitButton = event.submitter;

    if (submitButton instanceof HTMLElement) {
        submitButton.classList.add('is-loading');
        submitButton.setAttribute('aria-busy', 'true');
    }
});

const appShell = document.querySelector('.app-shell');
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebarCollapsedKey = 'bravado-sidebar-collapsed';

if (appShell && sidebarToggle instanceof HTMLButtonElement) {
    const setSidebarCollapsed = (isCollapsed) => {
        appShell.classList.toggle('is-sidebar-collapsed', isCollapsed);
        sidebarToggle.setAttribute('aria-expanded', String(!isCollapsed));
        sidebarToggle.setAttribute('aria-label', isCollapsed ? 'Expand navigation' : 'Collapse navigation');
        sidebarToggle.title = isCollapsed ? 'Expand navigation' : 'Collapse navigation';

        appShell.querySelectorAll('.sidebar .nav-item').forEach((item) => {
            if (isCollapsed) {
                item.setAttribute('title', item.textContent.trim());
            } else {
                item.removeAttribute('title');
            }
        });
    };

    setSidebarCollapsed(localStorage.getItem(sidebarCollapsedKey) === 'true');
    sidebarToggle.addEventListener('click', () => {
        const isCollapsed = !appShell.classList.contains('is-sidebar-collapsed');
        setSidebarCollapsed(isCollapsed);
        localStorage.setItem(sidebarCollapsedKey, String(isCollapsed));
    });
}

const profileMenu = document.querySelector('[data-profile-menu]');

if (profileMenu instanceof HTMLDetailsElement) {
    document.addEventListener('pointerdown', (event) => {
        if (profileMenu.open && event.target instanceof Node && !profileMenu.contains(event.target)) {
            profileMenu.open = false;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && profileMenu.open) {
            profileMenu.open = false;
            profileMenu.querySelector('summary')?.focus();
        }
    });
}

const dashboardThemeKey = 'bravado-dashboard-theme';
const savedTheme = localStorage.getItem(dashboardThemeKey);
if (savedTheme === 'dark') {
    document.documentElement.dataset.theme = 'dark';
}

const getThemeColors = () => {
    const styles = getComputedStyle(document.documentElement);
    const dark = document.documentElement.dataset.theme === 'dark';

    return {
        dark,
        text: styles.getPropertyValue('--chart-text').trim() || (dark ? '#cbd5e1' : '#64748b'),
        grid: styles.getPropertyValue('--chart-grid').trim() || (dark ? '#334155' : '#e9edf5'),
        card: styles.getPropertyValue('--paper').trim() || (dark ? '#17243a' : '#ffffff'),
        primary: styles.getPropertyValue('--chart-primary').trim() || '#3672eb',
        primaryRgb: styles.getPropertyValue('--chart-primary-rgb').trim() || '54, 114, 235',
        green: styles.getPropertyValue('--chart-green').trim() || '#20a37a',
        amber: styles.getPropertyValue('--chart-amber').trim() || '#e3a23b',
        violet: styles.getPropertyValue('--chart-violet').trim() || '#8a76d3',
        cyan: styles.getPropertyValue('--chart-cyan').trim() || '#50a7c7',
        rose: styles.getPropertyValue('--chart-rose').trim() || '#da718e',
    };
};

document.querySelectorAll('.theme-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        document.documentElement.dataset.theme = isDark ? 'light' : 'dark';
        localStorage.setItem(dashboardThemeKey, isDark ? 'light' : 'dark');
        if (typeof window.refreshHRChartTheme === 'function') {
            window.refreshHRChartTheme();
        }
    });
});

document.addEventListener('DOMContentLoaded', () => {

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const counters = document.querySelectorAll('[data-count-up]');

    if (!reducedMotion) {
        counters.forEach((counter) => {
            const target = Number(counter.dataset.countUp);
            const decimalPlaces = Number(counter.dataset.decimals ?? 0);
            const suffix = counter.dataset.countSuffix ?? '';

            if (!Number.isFinite(target) || !Number.isInteger(decimalPlaces) || decimalPlaces < 0 || decimalPlaces > 3) {
                return;
            }

            const formatter = new Intl.NumberFormat(undefined, {
                minimumFractionDigits: decimalPlaces,
                maximumFractionDigits: decimalPlaces,
            });
            const duration = 620;
            const startTime = performance.now();
            counter.textContent = `${formatter.format(0)}${suffix}`;

            const animate = (currentTime) => {
                const progress = Math.min((currentTime - startTime) / duration, 1);
                const easedProgress = 1 - ((1 - progress) ** 3);
                counter.textContent = `${formatter.format(target * easedProgress)}${suffix}`;

                if (progress < 1) {
                    requestAnimationFrame(animate);
                }
            };

            requestAnimationFrame(animate);
        });
    }

    if (!window.hrDashboardData || typeof window.Chart === 'undefined') {
        return;
    }

    const data = window.hrDashboardData;
    const colors = getThemeColors();
    Chart.defaults.font.family = "'DM Sans', sans-serif";
    Chart.defaults.color = colors.text;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        animation: reducedMotion ? false : { duration: 650, easing: 'easeOutQuart' },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: colors.text, boxWidth: 8, boxHeight: 8, padding: 14 },
            },
            tooltip: {
                backgroundColor: colors.dark ? '#0c1729' : '#ffffff',
                titleColor: colors.dark ? '#f1f5f9' : '#17243a',
                bodyColor: colors.dark ? '#d2dceb' : '#475569',
                borderColor: colors.dark ? '#344967' : '#dfe7f2',
                borderWidth: 1,
                padding: 10,
                displayColors: true,
                usePointStyle: true,
            },
        },
        scales: {
            x: { ticks: { color: colors.text, maxRotation: 0 }, grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, ticks: { color: colors.text }, grid: { color: colors.grid }, border: { display: false } },
        },
    };

    const makeChart = (id, config) => {
        const canvas = document.getElementById(id);
        return canvas ? new Chart(canvas, config) : null;
    };

    const areaGradient = (context) => {
        const { chart } = context;
        const { chartArea } = chart;

        if (!chartArea) {
            return `rgba(${getThemeColors().primaryRgb}, 0.12)`;
        }

        const theme = getThemeColors();
        const gradient = chart.ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, `rgba(${theme.primaryRgb}, 0.25)`);
        gradient.addColorStop(1, `rgba(${theme.primaryRgb}, 0.015)`);

        return gradient;
    };

    const centerLabelPlugin = {
        id: 'dashboardCenterLabel',
        afterDraw(chart, args, options) {
            if (!options?.value || !chart.chartArea) {
                return;
            }

            const { ctx, chartArea } = chart;
            const centerX = (chartArea.left + chartArea.right) / 2;
            const centerY = (chartArea.top + chartArea.bottom) / 2;
            const theme = getThemeColors();

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = theme.dark ? '#f1f5f9' : '#17243a';
            ctx.font = `700 20px ${Chart.defaults.font.family}`;
            ctx.fillText(options.value, centerX, centerY - 5);
            ctx.fillStyle = theme.text;
            ctx.font = `500 10px ${Chart.defaults.font.family}`;
            ctx.fillText(options.label ?? '', centerX, centerY + 13);
            ctx.restore();
        },
    };

    Chart.register(centerLabelPlugin);

    const assignedEmployeeCount = Object.values(data.departmentDistribution).reduce((total, count) => total + Number(count), 0);
    const chartColors = [colors.primary, colors.green, colors.amber, colors.violet, colors.cyan, colors.rose, '#8190a5'];

    window.hrCharts = [
        makeChart('attendanceTrendChart', {
            type: 'line',
            data: {
                labels: data.attendanceTrend.map((item) => item.month),
                datasets: [{
                    label: 'Attendance rate',
                    data: data.attendanceTrend.map((item) => item.rate),
                    borderColor: colors.primary,
                    backgroundColor: areaGradient,
                    fill: true,
                    tension: 0.38,
                    borderWidth: 2.5,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    pointBackgroundColor: colors.primary,
                }],
            },
            options: {
                ...chartOptions,
                plugins: { ...chartOptions.plugins, legend: { display: false } },
                scales: {
                    ...chartOptions.scales,
                    y: { ...chartOptions.scales.y, suggestedMax: 100, ticks: { ...chartOptions.scales.y.ticks, callback: (value) => `${value}%` } },
                },
            },
        }),
        makeChart('departmentDistributionChart', {
            type: 'doughnut',
            data: {
                labels: Object.keys(data.departmentDistribution),
                datasets: [{
                    data: Object.values(data.departmentDistribution),
                    backgroundColor: chartColors,
                    borderColor: colors.card,
                    borderWidth: 3,
                    hoverOffset: 6,
                }],
            },
            options: {
                ...chartOptions,
                cutout: '72%',
                scales: {},
                plugins: {
                    ...chartOptions.plugins,
                    legend: { ...chartOptions.plugins.legend, position: 'right', labels: { ...chartOptions.plugins.legend.labels, padding: 11 } },
                    dashboardCenterLabel: { value: assignedEmployeeCount.toLocaleString(), label: 'assigned' },
                },
            },
        }),
        makeChart('hireResignationChart', {
            type: 'line',
            data: {
                labels: data.hireResignationTrend.map((item) => item.month),
                datasets: [
                    {
                        label: 'New hires',
                        data: data.hireResignationTrend.map((item) => item.hires),
                        borderColor: colors.primary,
                        backgroundColor: areaGradient,
                        fill: true,
                        tension: 0.36,
                        borderWidth: 2.5,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                    },
                    {
                        label: 'Resignations',
                        data: data.hireResignationTrend.map((item) => item.resignations),
                        borderColor: colors.amber,
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.36,
                        borderWidth: 2,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                    },
                ],
            },
            options: chartOptions,
        }),
        makeChart('leaveBreakdownChart', {
            type: 'doughnut',
            data: {
                labels: Object.keys(data.leaveBreakdown),
                datasets: [{ data: Object.values(data.leaveBreakdown), backgroundColor: chartColors, borderColor: colors.card, borderWidth: 3, hoverOffset: 6 }],
            },
            options: { ...chartOptions, cutout: '66%', scales: {} },
        }),
        makeChart('departmentPerformanceChart', {
            type: 'bar',
            data: {
                labels: Object.keys(data.departmentPerformance),
                datasets: [{
                    label: 'Performance score',
                    data: Object.values(data.departmentPerformance).map(Number),
                    backgroundColor: colors.green,
                    borderRadius: 7,
                    barThickness: 13,
                }],
            },
            options: {
                ...chartOptions,
                indexAxis: 'y',
                plugins: { ...chartOptions.plugins, legend: { display: false } },
                scales: { ...chartOptions.scales, x: { ...chartOptions.scales.x, suggestedMax: 100 }, y: { ...chartOptions.scales.y, grid: { display: false } } },
            },
        }),
        makeChart('departmentSatisfactionChart', {
            type: 'line',
            data: {
                labels: Object.keys(data.departmentSatisfaction),
                datasets: [{
                    label: 'Satisfaction score',
                    data: Object.values(data.departmentSatisfaction).map(Number),
                    borderColor: colors.violet,
                    backgroundColor: colors.violet,
                    borderWidth: 2.5,
                    tension: 0.3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: colors.violet,
                }],
            },
            options: {
                ...chartOptions,
                plugins: { ...chartOptions.plugins, legend: { display: false } },
                scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, suggestedMax: 100 } },
            },
        }),
    ].filter(Boolean);

    window.refreshHRChartTheme = () => {
        const theme = getThemeColors();
        const palette = [theme.primary, theme.green, theme.amber, theme.violet, theme.cyan, theme.rose, '#8190a5'];
        Chart.defaults.color = theme.text;

        window.hrCharts.forEach((chart) => {
            const chartId = chart.canvas.id;
            chart.options.color = theme.text;
            if (chart.options.plugins?.legend?.labels) {
                chart.options.plugins.legend.labels.color = theme.text;
            }
            if (chart.options.plugins?.tooltip) {
                chart.options.plugins.tooltip.backgroundColor = theme.dark ? '#0c1729' : '#ffffff';
                chart.options.plugins.tooltip.titleColor = theme.dark ? '#f1f5f9' : '#17243a';
                chart.options.plugins.tooltip.bodyColor = theme.dark ? '#d2dceb' : '#475569';
                chart.options.plugins.tooltip.borderColor = theme.dark ? '#344967' : '#dfe7f2';
            }

            Object.values(chart.options.scales ?? {}).forEach((scale) => {
                if (scale.ticks) {
                    scale.ticks.color = theme.text;
                }
                if (scale.grid && scale.grid.display !== false) {
                    scale.grid.color = theme.grid;
                }
            });

            if (chartId === 'attendanceTrendChart') {
                chart.data.datasets[0].borderColor = theme.primary;
                chart.data.datasets[0].pointBackgroundColor = theme.primary;
            } else if (chartId === 'hireResignationChart') {
                chart.data.datasets[0].borderColor = theme.primary;
                chart.data.datasets[1].borderColor = theme.amber;
            } else if (chartId === 'departmentDistributionChart' || chartId === 'leaveBreakdownChart') {
                chart.data.datasets[0].backgroundColor = palette;
                chart.data.datasets[0].borderColor = theme.card;
            } else if (chartId === 'departmentPerformanceChart') {
                chart.data.datasets[0].backgroundColor = theme.green;
            } else if (chartId === 'departmentSatisfactionChart') {
                chart.data.datasets[0].borderColor = theme.violet;
                chart.data.datasets[0].backgroundColor = theme.violet;
                chart.data.datasets[0].pointBackgroundColor = theme.violet;
            }

            chart.update('none');
        });
    };
});
