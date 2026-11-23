document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get') {
        return;
    }

    const submitButton = event.submitter;

    if (submitButton instanceof HTMLElement) {
        submitButton.classList.add('is-loading');
        submitButton.setAttribute('aria-busy', 'true');
    }
});

const dashboardThemeKey = 'bravado-dashboard-theme';
const savedTheme = localStorage.getItem(dashboardThemeKey);
if (savedTheme === 'dark') {
    document.documentElement.dataset.theme = 'dark';
}

document.querySelectorAll('.theme-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        document.documentElement.dataset.theme = isDark ? 'light' : 'dark';
        localStorage.setItem(dashboardThemeKey, isDark ? 'light' : 'dark');
        if (window.hrCharts) {
            window.hrCharts.forEach((chart) => chart.update());
        }
    });
});

document.addEventListener('DOMContentLoaded', () => {
    if (!window.hrDashboardData || typeof window.Chart === 'undefined') {
        return;
    }

    const data = window.hrDashboardData;
    const isDark = document.documentElement.dataset.theme === 'dark';
    const textColor = isDark ? '#cbd5e1' : '#64748b';
    const gridColor = isDark ? '#334155' : '#e9edf5';
    Chart.defaults.font.family = "'DM Sans', sans-serif";
    Chart.defaults.color = textColor;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 700 },
        plugins: { legend: { labels: { boxWidth: 8, boxHeight: 8 } } },
        scales: {
            x: { ticks: { color: textColor }, grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, ticks: { color: textColor }, grid: { color: gridColor }, border: { display: false } },
        },
    };
    const makeChart = (id, config) => {
        const canvas = document.getElementById(id);
        return canvas ? new Chart(canvas, config) : null;
    };

    window.hrCharts = [
        makeChart('attendanceTrendChart', {
            type: 'line',
            data: { labels: data.attendanceTrend.map((item) => item.month), datasets: [{ label: 'Attendance', data: data.attendanceTrend.map((item) => item.rate), borderColor: '#5478e8', backgroundColor: 'rgba(84,120,232,.12)', fill: true, tension: .35, pointRadius: 3 }] },
            options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, suggestedMax: 100 } } },
        }),
        makeChart('departmentDistributionChart', {
            type: 'doughnut',
            data: { labels: Object.keys(data.departmentDistribution), datasets: [{ data: Object.values(data.departmentDistribution), backgroundColor: ['#5478e8','#55b89b','#f2a65a','#9b7ede','#54a8d8','#df718b'], borderWidth: 0, hoverOffset: 5 }] },
            options: { ...chartOptions, cutout: '68%', scales: {} },
        }),
        makeChart('hireResignationChart', {
            type: 'bar',
            data: { labels: data.hireResignationTrend.map((item) => item.month), datasets: [
                { label: 'Hires', data: data.hireResignationTrend.map((item) => item.hires), backgroundColor: '#5478e8', borderRadius: 4 },
                { label: 'Resignations', data: data.hireResignationTrend.map((item) => item.resignations), backgroundColor: '#f2a65a', borderRadius: 4 },
            ] },
            options: chartOptions,
        }),
        makeChart('leaveBreakdownChart', {
            type: 'pie',
            data: { labels: Object.keys(data.leaveBreakdown), datasets: [{ data: Object.values(data.leaveBreakdown), backgroundColor: ['#5478e8','#55b89b','#f2a65a','#9b7ede','#54a8d8','#df718b','#8090a5'], borderWidth: 2, borderColor: isDark ? '#1e293b' : '#ffffff' }] },
            options: { ...chartOptions, scales: {} },
        }),
        makeChart('departmentPerformanceChart', {
            type: 'bar',
            data: { labels: Object.keys(data.departmentPerformance), datasets: [{ label: 'Performance', data: Object.values(data.departmentPerformance).map((value) => Number(value).toFixed(1)), backgroundColor: '#55b89b', borderRadius: 4 }] },
            options: { ...chartOptions, indexAxis: 'y', scales: { ...chartOptions.scales, x: { ...chartOptions.scales.x, suggestedMax: 100 }, y: { ...chartOptions.scales.y, grid: { display: false } } } },
        }),
        makeChart('departmentSatisfactionChart', {
            type: 'bar',
            data: { labels: Object.keys(data.departmentSatisfaction), datasets: [{ label: 'Satisfaction', data: Object.values(data.departmentSatisfaction).map((value) => Number(value).toFixed(1)), backgroundColor: '#9b7ede', borderRadius: 5, barThickness: 18 }] },
            options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, suggestedMax: 100 } } },
        }),
    ].filter(Boolean);
});
