@extends('layouts.app', ['title' => 'HR dashboard'])
@section('crumb', 'Overview')
@section('content')
@php
    $periodStart = \Carbon\CarbonImmutable::parse($filters['start_date']);
    $periodEnd = \Carbon\CarbonImmutable::parse($filters['end_date']);
    $periodDays = max(1, (int) $periodStart->diffInDays($periodEnd) + 1);
    $dashboardHour = now($displayTimezone)->hour;
    $greeting = $dashboardHour < 12 ? 'Good morning' : ($dashboardHour < 17 ? 'Good afternoon' : 'Good evening');
    $canReviewLeave = in_array(auth()->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true);
    $hasMovementData = array_sum(array_column($hrMetrics['hireResignationTrend'], 'hires'))
        + array_sum(array_column($hrMetrics['hireResignationTrend'], 'resignations')) > 0;
@endphp

<div class="page-wrap hr-dashboard" data-page-motion="dashboard">
    <header class="dashboard-welcome">
        <div class="dashboard-welcome-identity">
            <a class="dashboard-profile-avatar" href="{{ route('account.edit') }}" aria-label="Open your account settings">
                <span class="dashboard-profile-fallback" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
            </a>
            <div class="dashboard-welcome-copy">
                <div class="eyebrow">BRAVADO HR MANAGEMENT SYSTEM</div>
                <h1>{{ $greeting }}, {{ auth()->user()->name }}</h1>
                <p>Here's what's happening across your organization today.</p>
                <div class="dashboard-date-range">
                    <span class="live-dot" aria-hidden="true"></span>
                    <span>{{ $periodStart->format('M j, Y') }} – {{ $periodEnd->format('M j, Y') }}</span>
                    <span class="dashboard-timezone">{{ $displayTimezone }}</span>
                </div>
            </div>
        </div>
        <div class="heading-actions dashboard-heading-actions">
            <button class="button button-light theme-toggle" type="button" aria-label="Toggle light and dark theme">
                <span aria-hidden="true">◐</span> Theme
            </button>
            <a class="button button-primary" href="{{ route('attendance.export', request()->query()) }}">
                <span aria-hidden="true">↓</span> Export attendance
            </a>
        </div>
    </header>

    <form class="filter-row hr-filter-row dashboard-filters" method="get" action="{{ route('dashboard') }}" aria-label="Filter dashboard data">
        <div class="dashboard-filter-caption">
            <strong>Dashboard view</strong>
            <span>Choose a date range and team scope</span>
        </div>
        <div class="filter-group">
            <label class="filter-select">
                <span>From</span>
                <input type="date" name="start_date" value="{{ $filters['start_date'] }}" required>
            </label>
            <label class="filter-select">
                <span>To</span>
                <input type="date" name="end_date" value="{{ $filters['end_date'] }}" required>
            </label>
            <label class="filter-select">
                <span class="visually-hidden">Branch</span>
                <select name="branch_id" aria-label="Filter by branch">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch_id'] === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="filter-select">
                <span class="visually-hidden">Department</span>
                <select name="department" aria-label="Filter by department">
                    <option value="">All departments</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->name }}" @selected($filters['department'] === $department->name)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="button button-primary" type="submit">Apply filters</button>
        </div>
        <span class="updated"><span class="live-dot" aria-hidden="true"></span> Live system data</span>
    </form>

    <section class="stat-grid hr-stat-grid dashboard-kpi-grid" aria-label="Key workforce metrics">
        <article class="stat-card hr-stat dashboard-kpi dashboard-kpi-blue">
            <div class="stat-top"><span>Active employees</span><span class="stat-icon indigo" aria-hidden="true">♙</span></div>
            <div class="stat-value"><span data-count-up="{{ (int) $employeeCount }}">{{ number_format($employeeCount) }}</span></div>
            <div class="stat-foot">Current active roster</div>
        </article>
        <article class="stat-card hr-stat dashboard-kpi dashboard-kpi-green">
            <div class="stat-top"><span>Attendance rate</span><span class="stat-icon blue" aria-hidden="true">◷</span></div>
            <div class="stat-value"><span data-count-up="{{ (float) $hrMetrics['attendanceRate'] }}" data-decimals="1" data-count-suffix="%">{{ number_format((float) $hrMetrics['attendanceRate'], 1) }}%</span></div>
            <div class="stat-foot">Across {{ $periodDays }} {{ \Illuminate\Support\Str::plural('day', $periodDays) }}</div>
        </article>
        <article class="stat-card hr-stat dashboard-kpi dashboard-kpi-violet">
            <div class="stat-top"><span>Employees on leave</span><span class="stat-icon amber" aria-hidden="true">◷</span></div>
            <div class="stat-value"><span data-count-up="{{ (int) $employeesOnLeave }}">{{ number_format($employeesOnLeave) }}</span></div>
            <div class="stat-foot">Approved leave today</div>
        </article>
        <article class="stat-card hr-stat dashboard-kpi dashboard-kpi-blue">
            <div class="stat-top"><span>New hires</span><span class="stat-icon green" aria-hidden="true">＋</span></div>
            <div class="stat-value"><span data-count-up="{{ (int) $hrMetrics['newHires'] }}">{{ number_format($hrMetrics['newHires']) }}</span></div>
            <div class="stat-foot">Joined in the selected period</div>
        </article>
        <article class="stat-card hr-stat dashboard-kpi dashboard-kpi-amber">
            <div class="stat-top"><span>Pending leave requests</span><span class="stat-icon amber" aria-hidden="true">⌛</span></div>
            <div class="stat-value">
                @if($canReviewLeave)
                    <span data-count-up="{{ (int) $pendingLeaveApprovals }}">{{ number_format($pendingLeaveApprovals) }}</span>
                @else
                    <span aria-label="Not available for this role">—</span>
                @endif
            </div>
            <div class="stat-foot">{{ $canReviewLeave ? 'Awaiting your review' : 'Not available for this role' }}</div>
        </article>
    </section>

    <section class="dashboard-insight-grid" aria-label="Additional workforce insights">
        <article class="dashboard-insight-card">
            <span class="dashboard-insight-icon insight-amber" aria-hidden="true">↗</span>
            <div><span class="dashboard-insight-label">Resignations</span><strong data-count-up="{{ (int) $hrMetrics['resignations'] }}">{{ number_format($hrMetrics['resignations']) }}</strong></div>
            <span class="dashboard-insight-note">Selected period</span>
        </article>
        <article class="dashboard-insight-card">
            <span class="dashboard-insight-icon insight-violet" aria-hidden="true">★</span>
            <div><span class="dashboard-insight-label">Employee satisfaction</span><strong>
                @if($hrMetrics['satisfactionScore'] === null)
                    —
                @else
                    <span data-count-up="{{ (float) $hrMetrics['satisfactionScore'] }}" data-decimals="1">{{ number_format((float) $hrMetrics['satisfactionScore'], 1) }}</span><small>/100</small>
                @endif
            </strong></div>
            <span class="dashboard-insight-note">{{ $hrMetrics['satisfactionScore'] === null ? 'No reported score' : 'Reported score' }}</span>
        </article>
    </section>

    <section class="dashboard-quick-actions" aria-labelledby="quick-actions-title">
        <div class="dashboard-section-heading">
            <div><h2 id="quick-actions-title">Quick actions</h2><p>Shortcuts to the work you do most.</p></div>
        </div>
        <nav class="quick-action-grid" aria-label="Dashboard quick actions">
            @if(in_array(auth()->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true))
                <a class="quick-action" href="{{ route('employees.create') }}"><span class="quick-action-icon" aria-hidden="true">＋</span><span><strong>Add employee</strong><small>Create a people record</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
                <a class="quick-action" href="{{ route('leave.approvals') }}"><span class="quick-action-icon" aria-hidden="true">✓</span><span><strong>Review leave</strong><small>Open pending requests</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
            @endif
            @if(in_array(auth()->user()->role, ['system_admin', 'hr_director'], true))
                <a class="quick-action" href="{{ route('reports.index') }}"><span class="quick-action-icon" aria-hidden="true">▧</span><span><strong>View reports</strong><small>Explore people analytics</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
            @endif
            @if(in_array(auth()->user()->role, ['system_admin', 'attendance_officer'], true))
                <a class="quick-action" href="{{ route('devices.index') }}"><span class="quick-action-icon" aria-hidden="true">▤</span><span><strong>Manage devices</strong><small>Check attendance connections</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
            @endif
            <a class="quick-action" href="#events"><span class="quick-action-icon" aria-hidden="true">◷</span><span><strong>Recent activity</strong><small>Review the latest events</small></span><span class="quick-action-arrow" aria-hidden="true">↓</span></a>
        </nav>
    </section>

    <section class="hr-chart-grid dashboard-chart-grid" aria-label="People analytics">
        <article class="panel hr-chart-panel dashboard-chart-panel dashboard-chart-featured">
            <div class="panel-heading">
                <div><h2>Employee overview</h2><p>New hires and departures across the last 12 months</p></div>
                <span class="chart-period-label">12 months</span>
            </div>
            @if($hasMovementData)
                <div class="chart-container dashboard-chart-container"><canvas id="hireResignationChart" role="img" aria-label="Monthly trend of new hires and resignations"></canvas></div>
            @else
                <div class="chart-empty dashboard-empty-state"><span class="empty-state-icon" aria-hidden="true">↗</span><strong>No workforce movement yet</strong><span>Hiring and departure trends will appear when records are available.</span></div>
            @endif
        </article>

        <article class="panel hr-chart-panel dashboard-chart-panel">
            <div class="panel-heading"><div><h2>Attendance overview</h2><p>Employees with recorded attendance by month</p></div><span class="chart-period-label">12 months</span></div>
            @if($eventCount > 0)
                <div class="chart-container dashboard-chart-container"><canvas id="attendanceTrendChart" role="img" aria-label="Monthly attendance rate trend"></canvas></div>
            @else
                <div class="chart-empty dashboard-empty-state"><span class="empty-state-icon" aria-hidden="true">◷</span><strong>No attendance data yet</strong><span>Records for the selected period will appear here.</span></div>
            @endif
        </article>

        <article class="panel hr-chart-panel dashboard-chart-panel">
            <div class="panel-heading"><div><h2>Departments</h2><p>Active employees by department</p></div></div>
            @if(count($hrMetrics['departmentDistribution']) > 0)
                <div class="chart-container dashboard-chart-container"><canvas id="departmentDistributionChart" role="img" aria-label="Employee distribution by department"></canvas></div>
            @else
                <div class="chart-empty dashboard-empty-state"><span class="empty-state-icon" aria-hidden="true">▤</span><strong>No department data yet</strong><span>Department distribution will appear once employee records are assigned.</span></div>
            @endif
        </article>

        <article class="panel hr-chart-panel dashboard-chart-panel">
            <div class="panel-heading"><div><h2>Leave requests</h2><p>Requests by leave type in this period</p></div></div>
            @if(count($hrMetrics['leaveBreakdown']) > 0)
                <div class="chart-container dashboard-chart-container"><canvas id="leaveBreakdownChart" role="img" aria-label="Leave requests by leave type"></canvas></div>
            @else
                <div class="chart-empty dashboard-empty-state"><span class="empty-state-icon" aria-hidden="true">◷</span><strong>No leave requests yet</strong><span>Submitted requests will be summarized here.</span></div>
            @endif
        </article>

        <article class="panel hr-chart-panel dashboard-chart-panel">
            <div class="panel-heading"><div><h2>Performance by department</h2><p>Average reported performance score</p></div></div>
            @if(count($hrMetrics['departmentPerformance']) > 0)
                <div class="chart-container dashboard-chart-container"><canvas id="departmentPerformanceChart" role="img" aria-label="Average performance by department"></canvas></div>
            @else
                <div class="chart-empty dashboard-empty-state"><span class="empty-state-icon" aria-hidden="true">↗</span><strong>No performance scores yet</strong><span>Reported scores will appear when available.</span></div>
            @endif
        </article>

        <article class="panel hr-chart-panel dashboard-chart-panel">
            <div class="panel-heading"><div><h2>Employee experience</h2><p>Average satisfaction score by department</p></div></div>
            @if(count($hrMetrics['departmentSatisfaction']) > 0)
                <div class="chart-container dashboard-chart-container"><canvas id="departmentSatisfactionChart" role="img" aria-label="Average employee satisfaction by department"></canvas></div>
            @else
                <div class="chart-empty dashboard-empty-state"><span class="empty-state-icon" aria-hidden="true">★</span><strong>No survey data yet</strong><span>Department scores will appear when reported.</span></div>
            @endif
        </article>
    </section>

    <section class="dashboard-lower-grid">
        <article class="panel dashboard-activity-panel" id="events">
            <div class="panel-heading">
                <div><h2>Recent activity</h2><p>Latest attendance events received by the system</p></div>
                <span class="table-count">{{ number_format($eventCount) }} in period</span>
            </div>
            @if($recentEvents->isNotEmpty())
                <ol class="activity-timeline">
                    @foreach($recentEvents as $event)
                        <li class="activity-item">
                            <span class="activity-avatar" aria-hidden="true">{{ strtoupper(substr($event->employee?->name ?? '!', 0, 1)) }}</span>
                            <div class="activity-body">
                                <div class="activity-title-row">
                                    <strong>{{ $event->employee?->name ?? 'Unmatched user' }}</strong>
                                    <span class="status-pill {{ in_array($event->processing_status, ['mapped', 'processed'], true) ? 'ok' : 'pending' }}">{{ ucfirst($event->processing_status) }}</span>
                                </div>
                                <p>{{ $event->event_type ?: 'Unclassified punch' }} <span>·</span> {{ $event->employee?->employee_no ?? 'No employee mapping' }}</p>
                                <div class="activity-meta">
                                    <time datetime="{{ $event->event_timestamp->toIso8601String() }}">{{ $event->event_timestamp->timezone($displayTimezone)->format('M j, Y · g:i A') }}</time>
                                    <span>{{ ucfirst(str_replace('_', ' ', $event->source)) }}</span>
                                    <span>{{ $event->device?->device_code ?? 'Computer event' }}</span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @else
                <div class="dashboard-empty-state activity-empty-state"><span class="empty-state-icon" aria-hidden="true">◷</span><strong>No attendance activity yet</strong><span>Events received for this date range will appear here.</span></div>
            @endif
        </article>

        <div class="dashboard-side-panels">
            <article class="panel dashboard-device-panel">
                <div class="panel-heading">
                    <div><h2>Attendance devices</h2><p>Live registration and connection status</p></div>
                    <span class="table-count">{{ number_format($onlineCount) }} / {{ number_format($deviceCount) }} online</span>
                </div>
                @if($devices->isNotEmpty())
                    <div class="dashboard-device-list">
                        @foreach($devices as $device)
                            <div class="dashboard-device-row">
                                <span class="device-glyph" aria-hidden="true">▤</span>
                                <div class="device-info"><strong>{{ $device->device_code }}</strong><span>{{ $device->location?->name ?? 'Unassigned' }}</span></div>
                                <span class="status-pill {{ $device->integration_status === 'connected' ? 'ok' : (in_array($device->integration_status, ['disconnected', 'failed'], true) ? 'off' : 'pending') }}">{{ ucfirst(str_replace('_', ' ', $device->integration_status)) }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="dashboard-empty-state device-empty-state"><span class="empty-state-icon" aria-hidden="true">▤</span><strong>No devices registered</strong><span>Registered attendance devices will appear here.</span></div>
                @endif
                @if($unmatchedCount !== null)
                    <div class="dashboard-device-footer"><span>Unmatched events</span><strong>{{ number_format($unmatchedCount) }}</strong></div>
                @endif
            </article>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.hrDashboardData = @json(array_merge($hrMetrics, ['employeeCount' => $employeeCount]));
</script>
@endsection
