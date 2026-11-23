@extends('layouts.app', ['title' => 'HR dashboard'])
@section('crumb', 'Overview')
@section('content')
<div class="page-wrap hr-dashboard" data-page-motion="dashboard">
    <div class="page-heading dashboard-heading">
        <div><div class="eyebrow">Attendance overview · Executive HR reporting</div><h1>People dashboard</h1><p>{{ \Carbon\CarbonImmutable::parse($filters['start_date'])->format('M j, Y') }} – {{ \Carbon\CarbonImmutable::parse($filters['end_date'])->format('M j, Y') }}</p><span class="visually-hidden">Attendance overview</span></div>
        <div class="heading-actions"><button class="button button-light theme-toggle" type="button" aria-label="Toggle light and dark theme"><span>◐</span> Theme</button><a class="button button-light" href="{{ route('attendance.export', request()->query()) }}">↓ Export Excel CSV</a></div>
    </div>

    <form class="filter-row hr-filter-row" method="get" action="{{ route('dashboard') }}">
        <div class="filter-group">
            <label class="filter-select">From <input type="date" name="start_date" value="{{ $filters['start_date'] }}" required></label>
            <label class="filter-select">To <input type="date" name="end_date" value="{{ $filters['end_date'] }}" required></label>
            <label class="filter-select"><select name="branch_id"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($filters['branch_id'] === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
            <label class="filter-select"><select name="department"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->name }}" @selected($filters['department'] === $department->name)>{{ $department->name }}</option>@endforeach</select></label>
            <button class="button button-primary" type="submit">Apply</button>
        </div>
        <span class="updated"><span class="live-dot"></span> Live system data</span>
    </form>

    <section class="stat-grid hr-stat-grid">
        <article class="stat-card hr-stat"><div class="stat-top"><span>Total employees</span><span class="stat-icon indigo">♙</span></div><div class="stat-value">{{ number_format($employeeCount) }}</div><div class="stat-foot"><span class="trend-up">▲</span> Active roster</div></article>
        <article class="stat-card hr-stat"><div class="stat-top"><span>Attendance rate</span><span class="stat-icon blue">◷</span></div><div class="stat-value">{{ number_format((float) $hrMetrics['attendanceRate'], 1) }}%</div><div class="stat-foot"><span class="{{ $hrMetrics['attendanceRate'] >= 85 ? 'trend-up' : 'trend-down' }}">{{ $hrMetrics['attendanceRate'] >= 85 ? '▲' : '▼' }}</span> {{ $periodDays = max(1, \Carbon\CarbonImmutable::parse($filters['start_date'])->diffInDays(\Carbon\CarbonImmutable::parse($filters['end_date'])) + 1) }} day period</div></article>
        <article class="stat-card hr-stat"><div class="stat-top"><span>New hires</span><span class="stat-icon green">＋</span></div><div class="stat-value">{{ number_format($hrMetrics['newHires']) }}</div><div class="stat-foot"><span class="trend-up">▲</span> Selected period</div></article>
        <article class="stat-card hr-stat"><div class="stat-top"><span>Resignations</span><span class="stat-icon amber">↗</span></div><div class="stat-value">{{ number_format($hrMetrics['resignations']) }}</div><div class="stat-foot"><span class="trend-down">▼</span> Offboarding cases</div></article>
        <article class="stat-card hr-stat"><div class="stat-top"><span>Employee satisfaction</span><span class="stat-icon indigo">★</span></div><div class="stat-value">{{ $hrMetrics['satisfactionScore'] === null ? '—' : number_format((float) $hrMetrics['satisfactionScore'], 1) }}<small>/100</small></div><div class="stat-foot"><span class="{{ ($hrMetrics['satisfactionScore'] ?? 0) >= 70 ? 'trend-up' : 'trend-down' }}">{{ ($hrMetrics['satisfactionScore'] ?? 0) >= 70 ? '▲' : '▼' }}</span> Reported score</div></article>
    </section>

    <section class="hr-chart-grid">
        <article class="panel hr-chart-panel"><div class="panel-heading"><div><h2>Monthly attendance trend</h2><p>Attendance % by month</p></div></div><div class="chart-container"><canvas id="attendanceTrendChart"></canvas></div></article>
        <article class="panel hr-chart-panel"><div class="panel-heading"><div><h2>Employee distribution</h2><p>By department</p></div></div><div class="chart-container"><canvas id="departmentDistributionChart"></canvas></div></article>
        <article class="panel hr-chart-panel"><div class="panel-heading"><div><h2>Hiring vs resignations</h2><p>Monthly comparison</p></div></div><div class="chart-container"><canvas id="hireResignationChart"></canvas></div></article>
        <article class="panel hr-chart-panel"><div class="panel-heading"><div><h2>Leave type breakdown</h2><p>Requests in selected period</p></div></div><div class="chart-container"><canvas id="leaveBreakdownChart"></canvas></div></article>
        <article class="panel hr-chart-panel"><div class="panel-heading"><div><h2>Department performance</h2><p>Average performance score</p></div></div><div class="chart-container"><canvas id="departmentPerformanceChart"></canvas></div></article>
        <article class="panel hr-chart-panel"><div class="panel-heading"><div><h2>Employee satisfaction</h2><p>Average score by department</p></div></div><div class="chart-container"><canvas id="departmentSatisfactionChart"></canvas></div></article>
    </section>

    <section id="events" class="panel events-panel"><div class="panel-heading"><div><h2>Recent attendance events</h2><p>Latest records received by the system</p></div></div><div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>EVENT</th><th>DATE & TIME</th><th>SOURCE</th><th>PROCESSING</th></tr></thead><tbody>@forelse($recentEvents as $event)<tr><td><strong>{{ $event->employee?->name ?? 'Unmatched user' }}</strong><small class="cell-sub">{{ $event->employee?->employee_no ?? 'No mapping' }}</small></td><td>{{ $event->event_type ?: 'Unclassified punch' }}</td><td>{{ $event->event_timestamp->timezone($displayTimezone)->format('M j, Y · g:i A') }}</td><td>{{ ucfirst(str_replace('_', ' ', $event->source)) }}</td><td><span class="status-pill {{ in_array($event->processing_status, ['mapped','processed'], true) ? 'ok' : 'pending' }}">{{ ucfirst($event->processing_status) }}</span></td></tr>@empty<tr><td colspan="5" class="table-empty">No attendance events received for this period.</td></tr>@endforelse</tbody></table></div></section>
    <section class="panel device-table-panel"><div class="panel-heading"><div><h2>Attendance devices</h2><p>Live registration and connection status</p></div></div><div class="device-list">@foreach($devices as $device)<div class="device-row"><div class="device-glyph">▤</div><div class="device-info"><strong>{{ $device->device_code }}</strong><span>{{ $device->location?->name ?? 'Unassigned' }}</span></div><span class="status-pill pending">{{ ucfirst(str_replace('_',' ', $device->integration_status)) }}</span></div>@endforeach</div></section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.hrDashboardData = @json($hrMetrics);
</script>
@vite('resources/js/app.js')
@endsection
