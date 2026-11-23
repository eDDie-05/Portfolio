<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\Branch;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\OffboardingCase;
use App\Models\RawAttendanceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $this->validatedFilters($request);
        $displayTimezone = $filters['branch_id'] !== null
            ? Branch::whereKey($filters['branch_id'])->value('timezone')
            : config('attendance.display_timezone');
        $events = $this->filteredEvents($filters);
        $employees = $this->accessibleEmployees()->where('is_active', true);

        if ($filters['branch_id'] !== null) {
            $employees->where('branch_id', $filters['branch_id']);
        }

        if ($filters['department'] !== null) {
            $employees->where('department', $filters['department']);
        }
        $onLeaveQuery = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->whereHas('employee', function (Builder $query) use ($filters, $request): void {
                $query->where('is_active', true)
                    ->when($filters['branch_id'] !== null, fn (Builder $employeeQuery) => $employeeQuery->where('branch_id', $filters['branch_id']))
                    ->when($filters['department'] !== null, fn (Builder $employeeQuery) => $employeeQuery->where('department', $filters['department']))
                    ->when($request->user()->role === 'hr_manager', fn (Builder $employeeQuery) => $employeeQuery->where('manager_id', $request->user()->id));
            });
        $pendingLeaveQuery = LeaveRequest::where('status', 'pending')
            ->when($request->user()->role === 'hr_manager', fn (Builder $query) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('manager_id', $request->user()->id)));

        $branchesQuery = Branch::where('is_active', true)->orderBy('name');
        $departmentsQuery = Department::where('is_active', true);
        $devicesQuery = AttendanceDevice::query();

        if ($request->user()->role === 'hr_manager') {
            $branchIds = $this->accessibleEmployees()->whereNotNull('branch_id')->distinct()->pluck('branch_id');
            $branchesQuery->whereIn('id', $branchIds);
            $departmentsQuery->whereHas('employees', fn (Builder $query) => $query->where('manager_id', $request->user()->id));
            $devicesQuery->whereIn('branch_id', $branchIds);
        }

        if ($filters['branch_id'] !== null) {
            $devicesQuery->where('branch_id', $filters['branch_id']);
        }

        $periodStart = CarbonImmutable::parse($filters['start_date'], $displayTimezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($filters['end_date'], $displayTimezone)->endOfDay();
        $employeeCountForMetrics = (clone $employees)->count();
        $periodDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $attendedDays = (clone $events)->whereNotNull('employee_id')
            ->get(['employee_id', 'event_timestamp'])
            ->unique(fn ($event): string => $event->employee_id.'|'.$event->event_timestamp->timezone($displayTimezone)->toDateString())
            ->count();
        $attendanceRate = $employeeCountForMetrics > 0
            ? min(100, round(($attendedDays / ($employeeCountForMetrics * $periodDays)) * 100, 1))
            : 0;
        $newHires = (clone $employees)->whereBetween('joined_on', [$periodStart->toDateString(), $periodEnd->toDateString()])->count();
        $resignationQuery = OffboardingCase::query()
            ->whereIn('status', ['completed', 'in_progress'])
            ->whereBetween('last_working_day', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereHas('employee', fn (Builder $query) => $query
                ->when($filters['branch_id'] !== null, fn (Builder $employeeQuery) => $employeeQuery->where('branch_id', $filters['branch_id']))
                ->when($filters['department'] !== null, fn (Builder $employeeQuery) => $employeeQuery->where('department', $filters['department']))
                ->when($request->user()->role === 'hr_manager', fn (Builder $employeeQuery) => $employeeQuery->where('manager_id', $request->user()->id)));
        $resignations = $resignationQuery->count();
        $satisfactionScore = (clone $employees)->whereNotNull('satisfaction_score')->avg('satisfaction_score');
        $departmentDistribution = (clone $employees)->whereNotNull('department')
            ->selectRaw('department, COUNT(*) as total')->groupBy('department')->orderBy('department')->pluck('total', 'department')->toArray();
        $departmentPerformance = (clone $employees)->whereNotNull('department')->whereNotNull('performance_score')
            ->selectRaw('department, AVG(performance_score) as score')->groupBy('department')->orderBy('department')->pluck('score', 'department')->toArray();
        $departmentSatisfaction = (clone $employees)->whereNotNull('department')->whereNotNull('satisfaction_score')
            ->selectRaw('department, AVG(satisfaction_score) as score')->groupBy('department')->orderBy('department')->pluck('score', 'department')->toArray();
        $leaveBreakdown = LeaveRequest::query()
            ->whereBetween('start_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereHas('employee', fn (Builder $query) => $query
                ->when($filters['branch_id'] !== null, fn (Builder $employeeQuery) => $employeeQuery->where('branch_id', $filters['branch_id']))
                ->when($filters['department'] !== null, fn (Builder $employeeQuery) => $employeeQuery->where('department', $filters['department']))
                ->when($request->user()->role === 'hr_manager', fn (Builder $employeeQuery) => $employeeQuery->where('manager_id', $request->user()->id)))
            ->with('leaveType')->get()->groupBy(fn (LeaveRequest $leave): string => $leave->leaveType->name)
            ->map(fn ($requests): int => $requests->count())->toArray();

        $attendanceTrend = [];
        $hireResignationTrend = [];
        $trendStart = $periodEnd->startOfMonth()->subMonths(11);
        for ($month = 0; $month < 12; $month++) {
            $monthStart = $trendStart->addMonths($month)->startOfMonth();
            $monthEnd = $monthStart->endOfMonth();
            $monthEmployeeCount = (clone $employees)->count();
            $monthAttendees = (clone $events)->whereBetween('event_timestamp', [$monthStart->utc(), $monthEnd->utc()])
                ->whereNotNull('employee_id')->distinct('employee_id')->count('employee_id');
            $attendanceTrend[] = ['month' => $monthStart->format('M'), 'rate' => $monthEmployeeCount > 0 ? round(($monthAttendees / $monthEmployeeCount) * 100, 1) : 0];
            $hireResignationTrend[] = [
                'month' => $monthStart->format('M'),
                'hires' => (clone $employees)->whereBetween('joined_on', [$monthStart->toDateString(), $monthEnd->toDateString()])->count(),
                'resignations' => OffboardingCase::whereBetween('last_working_day', [$monthStart->toDateString(), $monthEnd->toDateString()])->count(),
            ];
        }

        return view('dashboard', [
            'filters' => $filters,
            'displayTimezone' => $displayTimezone,
            'branches' => $branchesQuery->get(),
            'departments' => $departmentsQuery->orderBy('name')->get(),
            'deviceCount' => (clone $devicesQuery)->count(),
            'onlineCount' => (clone $devicesQuery)->where('integration_status', 'connected')->count(),
            'eventCount' => (clone $events)->count(),
            'employeeCount' => $employees->count(),
            'employeesOnLeave' => $onLeaveQuery->count(),
            'pendingLeaveApprovals' => in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true) ? $pendingLeaveQuery->count() : 0,
            'unmatchedCount' => $request->user()->role === 'hr_manager' ? null : RawAttendanceEvent::where('processing_status', 'unmatched')->count(),
            'devices' => (clone $devicesQuery)->with('location')->orderBy('device_code')->get(),
            'recentEvents' => $events->with(['device.location', 'employee.branch'])->latest('received_timestamp')->limit(8)->get(),
            'hrMetrics' => [
                'attendanceRate' => $attendanceRate,
                'newHires' => $newHires,
                'resignations' => $resignations,
                'satisfactionScore' => $satisfactionScore === null ? null : round((float) $satisfactionScore, 1),
                'attendanceTrend' => $attendanceTrend,
                'departmentDistribution' => $departmentDistribution,
                'hireResignationTrend' => $hireResignationTrend,
                'leaveBreakdown' => $leaveBreakdown,
                'departmentPerformance' => $departmentPerformance,
                'departmentSatisfaction' => $departmentSatisfaction,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $events = $this->filteredEvents($filters)->with(['device.location', 'employee.branch'])->orderBy('event_timestamp')->lazy(1000);
        $filename = 'attendance-events-'.$filters['start_date'].'-'.$filters['end_date'].'.csv';

        return response()->streamDownload(function () use ($events): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Employee number', 'Employee', 'Department', 'Branch', 'Event time (branch timezone)', 'Event type', 'Source', 'Device', 'Device user ID', 'Processing status']);

            foreach ($events as $event) {
                fputcsv($output, [
                    $this->safeCsvValue($event->employee?->employee_no),
                    $this->safeCsvValue($event->employee?->name ?? 'Unmatched user'),
                    $this->safeCsvValue($event->employee?->department),
                    $this->safeCsvValue($event->employee?->branch?->name ?? $event->device?->location?->name),
                    $event->event_timestamp->timezone($event->employee?->branch?->timezone ?? $event->device?->location?->timezone ?? config('attendance.display_timezone'))->format('Y-m-d H:i:s'),
                    $this->safeCsvValue($event->event_type),
                    $this->safeCsvValue($event->source),
                    $this->safeCsvValue($event->device?->device_code),
                    $this->safeCsvValue($event->device_user_id),
                    $this->safeCsvValue($event->processing_status),
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{start_date: string, end_date: string, branch_id: int|null, department: string|null} */
    private function validatedFilters(Request $request): array
    {
        $branchTimezone = is_numeric($request->query('branch_id'))
            ? Branch::whereKey((int) $request->query('branch_id'))->where('is_active', true)->value('timezone')
            : null;
        $defaultTimezone = $branchTimezone ?? config('attendance.display_timezone');
        $today = now($defaultTimezone)->toDateString();
        $validator = Validator::make(array_merge(['start_date' => $today], $request->query()), [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'department' => ['nullable', 'string', Rule::exists('departments', 'name')->where('is_active', true)],
        ]);

        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }

        $validated = $validator->validated();

        if ($request->user()?->role === 'hr_manager') {
            $managedEmployees = User::where('manager_id', $request->user()->id);
            $allowedBranchIds = (clone $managedEmployees)->whereNotNull('branch_id')->distinct()->pluck('branch_id')->map(fn ($id): int => (int) $id)->all();
            $allowedDepartments = (clone $managedEmployees)->whereNotNull('department')->distinct()->pluck('department')->all();

            abort_unless(! isset($validated['branch_id']) || in_array((int) $validated['branch_id'], $allowedBranchIds, true), 403);
            abort_unless(! isset($validated['department']) || in_array($validated['department'], $allowedDepartments, true), 403);
        }

        $startDate = $validated['start_date'] ?? $today;
        $endDate = $validated['end_date'] ?? $startDate;

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            'department' => $validated['department'] ?? null,
        ];
    }

    /** @param array{start_date: string, end_date: string, branch_id: int|null, department: string|null} $filters */
    private function filteredEvents(array $filters): Builder
    {
        $timezone = $filters['branch_id'] !== null
            ? Branch::whereKey($filters['branch_id'])->value('timezone')
            : config('attendance.display_timezone');
        $start = CarbonImmutable::parse($filters['start_date'], $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($filters['end_date'], $timezone)->endOfDay()->utc();

        return RawAttendanceEvent::query()
            ->whereBetween('event_timestamp', [$start, $end])
            ->when(auth()->user()?->role === 'hr_manager', fn (Builder $query) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('manager_id', auth()->id())))
            ->when($filters['branch_id'] !== null, function (Builder $query) use ($filters): void {
                $query->where(function (Builder $branchQuery) use ($filters): void {
                    $branchQuery->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('branch_id', $filters['branch_id']))
                        ->orWhere(function (Builder $unmatchedQuery) use ($filters): void {
                            $unmatchedQuery->whereNull('employee_id')->whereHas('device', fn (Builder $deviceQuery) => $deviceQuery->where('branch_id', $filters['branch_id']));
                        });
                });
            })
            ->when($filters['department'] !== null, fn (Builder $query) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department', $filters['department'])));
    }

    private function accessibleEmployees(): Builder
    {
        return User::query()
            ->whereNotNull('employee_no')
            ->when(auth()->user()?->role === 'hr_manager', fn (Builder $query) => $query->where('manager_id', auth()->id()));
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+@\-]/', $value) === 1 ? "'".$value : $value;
    }
}
