<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\RawAttendanceEvent;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->validatedFilters($request);
        $events = $this->filteredEvents($filters);
        $periods = PayrollPeriod::whereDate('starts_on', '<=', $filters['end_date'])
            ->whereDate('ends_on', '>=', $filters['start_date']);
        $leaveRequests = LeaveRequest::whereDate('start_date', '<=', $filters['end_date'])
            ->whereDate('end_date', '>=', $filters['start_date']);

        return view('reports.index', [
            'filters' => $filters,
            'eventCount' => (clone $events)->count(),
            'matchedCount' => (clone $events)->whereNotNull('employee_id')->count(),
            'unmatchedCount' => (clone $events)->whereNull('employee_id')->count(),
            'employeeCount' => (clone $events)->whereNotNull('employee_id')->distinct('employee_id')->count('employee_id'),
            'leaveCount' => (clone $leaveRequests)->count(),
            'approvedLeaveCount' => (clone $leaveRequests)->where('status', 'approved')->count(),
            'payrollCount' => (clone $periods)->count(),
            'paidPayrollCount' => (clone $periods)->where('status', 'paid')->count(),
            'paidPayrollTotals' => (clone $periods)->where('status', 'paid')->selectRaw('currency, SUM(net_total) as total')->groupBy('currency')->orderBy('currency')->get(),
            'periods' => (clone $periods)->latest('starts_on')->paginate(10)->withQueryString(),
            'recentEvents' => (clone $events)->with(['employee.branch', 'device.location'])->latest('event_timestamp')->paginate(30)->withQueryString(),
        ]);
    }

    public function exportAttendance(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $events = $this->filteredEvents($filters)->with(['employee.branch', 'device.location'])->orderBy('event_timestamp')->lazy(1000);

        return response()->streamDownload(function () use ($events): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Employee number', 'Employee', 'Branch', 'Timestamp (UTC)', 'Device', 'Event type', 'Processing status']);

            foreach ($events as $event) {
                fputcsv($output, [
                    $this->safeCsvValue($event->employee?->employee_no),
                    $this->safeCsvValue($event->employee?->name ?? 'Unmatched'),
                    $this->safeCsvValue($event->employee?->branch?->name ?? $event->device?->location?->name),
                    $event->event_timestamp->utc()->format('Y-m-d H:i:s'),
                    $this->safeCsvValue($event->device?->device_code),
                    $this->safeCsvValue($event->event_type),
                    $this->safeCsvValue($event->processing_status),
                ]);
            }

            fclose($output);
        }, 'attendance-report-'.$filters['start_date'].'-'.$filters['end_date'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{start_date: string, end_date: string} */
    private function validatedFilters(Request $request): array
    {
        $today = now(config('attendance.display_timezone'))->toDateString();
        $todayDate = CarbonImmutable::parse($today);
        $fiscalStartMonth = (int) SystemSetting::value('fiscal_year_start_month', '1');
        $fiscalStartYear = $todayDate->month < $fiscalStartMonth ? $todayDate->year - 1 : $todayDate->year;
        $defaultStartDate = CarbonImmutable::create($fiscalStartYear, $fiscalStartMonth, 1, 0, 0, 0, config('attendance.display_timezone'))->toDateString();
        $validator = Validator::make(array_merge(['start_date' => $defaultStartDate, 'end_date' => $today], $request->query()), [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $validator->after(function ($validator): void {
            $startDate = $validator->getData()['start_date'] ?? null;
            $endDate = $validator->getData()['end_date'] ?? null;

            if (! $validator->errors()->has('start_date') && ! $validator->errors()->has('end_date') && is_string($startDate) && is_string($endDate) && CarbonImmutable::parse($startDate)->diffInDays(CarbonImmutable::parse($endDate)) > 365) {
                $validator->errors()->add('end_date', 'Choose a report range of 366 days or less.');
            }
        });

        $data = $validator->validate();

        return ['start_date' => $data['start_date'], 'end_date' => $data['end_date']];
    }

    /** @param array{start_date: string, end_date: string} $filters */
    private function filteredEvents(array $filters): Builder
    {
        $timezone = config('attendance.display_timezone');
        $start = CarbonImmutable::parse($filters['start_date'], $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($filters['end_date'], $timezone)->addDay()->startOfDay()->utc();

        return RawAttendanceEvent::query()->where('event_timestamp', '>=', $start)->where('event_timestamp', '<', $end);
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+@\-]/', $value) === 1 ? "'".$value : $value;
    }
}
