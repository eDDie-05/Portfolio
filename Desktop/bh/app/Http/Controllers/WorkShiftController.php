<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\EmployeeShift;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkShiftController extends Controller
{
    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function index(): View
    {
        return view('shifts.index', [
            'shifts' => WorkShift::withCount(['assignments' => fn (Builder $query) => $query->where(fn (Builder $dateQuery) => $dateQuery->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString()))])->orderBy('name')->paginate(20, ['*'], 'shifts_page')->withQueryString(),
            'assignments' => EmployeeShift::with(['employee.branch', 'workShift', 'branch'])->latest('effective_from')->paginate(30, ['*'], 'assignments_page')->withQueryString(),
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->orderBy('name')->paginate(50, ['id', 'name', 'employee_no'], 'employees_page')->withQueryString(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedShift($request);
        $shift = WorkShift::create([...$data, 'is_active' => true]);
        $this->audit($request, 'work_shift.created', $shift, ['name' => $shift->name]);

        return back()->with('status', 'Work shift created.');
    }

    public function update(Request $request, WorkShift $workShift): RedirectResponse
    {
        $before = $workShift->only(['name', 'starts_at', 'ends_at', 'break_minutes', 'grace_period_minutes', 'days_of_week', 'is_active']);
        $data = $this->validatedShift($request, $workShift);
        $data['is_active'] = $request->boolean('is_active', $workShift->is_active);
        $workShift->update($data);
        $this->audit($request, 'work_shift.updated', $workShift, ['before' => $before, 'after' => $workShift->only(array_keys($before))]);

        return back()->with('status', 'Work shift updated. Existing assignments use this shift template.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')],
            'work_shift_id' => ['required', 'integer', Rule::exists('work_shifts', 'id')->where('is_active', true)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $employee = User::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            $overlapExists = EmployeeShift::where('employee_id', $employee->id)
                ->whereDate('effective_from', '<=', $data['effective_until'] ?? '9999-12-31')
                ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $data['effective_from']))
                ->exists();

            if ($overlapExists) {
                throw ValidationException::withMessages(['employee_id' => 'This employee already has a shift assignment covering some of those dates. End the earlier assignment first.']);
            }

            $assignment = EmployeeShift::create([...$data, 'assigned_by' => $request->user()->id]);
            $this->audit($request, 'work_shift.assigned', $assignment, ['employee_id' => $employee->id, 'work_shift_id' => $assignment->work_shift_id, 'effective_from' => $assignment->effective_from->toDateString(), 'effective_until' => $assignment->effective_until?->toDateString()]);
        });

        return back()->with('status', 'Shift assigned to employee.');
    }

    public function endAssignment(Request $request, EmployeeShift $employeeShift): RedirectResponse
    {
        $data = $request->validate(['effective_until' => ['required', 'date', 'after_or_equal:'.$employeeShift->effective_from->toDateString()]]);

        DB::transaction(function () use ($request, $employeeShift, $data): void {
            $assignment = EmployeeShift::whereKey($employeeShift->id)->lockForUpdate()->firstOrFail();
            abort_if($assignment->effective_until !== null, 409, 'This shift assignment has already ended.');
            $assignment->update(['effective_until' => $data['effective_until']]);
            $this->audit($request, 'work_shift.assignment_ended', $assignment, ['employee_id' => $assignment->employee_id, 'effective_until' => $assignment->effective_until->toDateString()]);
        });

        return back()->with('status', 'Shift assignment end date saved.');
    }

    private function validatedShift(Request $request, ?WorkShift $workShift = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('work_shifts', 'name')->ignore($workShift?->id)],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'break_minutes' => ['required', 'integer', 'between:0,600'],
            'grace_period_minutes' => ['required', 'integer', 'between:0,240'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['required', 'distinct', Rule::in(self::WEEKDAYS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $start = CarbonImmutable::createFromFormat('!H:i', $data['starts_at']);
        $end = CarbonImmutable::createFromFormat('!H:i', $data['ends_at']);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }
        if ((int) $data['break_minutes'] >= $start->diffInMinutes($end)) {
            throw ValidationException::withMessages(['break_minutes' => 'Break time must be shorter than the shift.']);
        }

        return $data;
    }

    private function audit(Request $request, string $action, object $subject, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
