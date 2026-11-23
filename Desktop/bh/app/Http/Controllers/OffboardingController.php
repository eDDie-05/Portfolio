<?php

namespace App\Http\Controllers;

use App\Models\OffboardingCase;
use App\Models\OffboardingClearanceItem;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OffboardingController extends Controller
{
    private const REASONS = ['resignation', 'termination', 'retirement', 'end_of_contract', 'death', 'other'];

    public function index(Request $request): View
    {
        $cases = OffboardingCase::with(['employee', 'owner'])->withCount(['clearances', 'clearances as open_clearances_count' => fn ($query) => $query->where('status', 'pending')])->latest();
        if ($request->user()->role === 'hr_manager') {
            $cases->where('owner_id', $request->user()->id);
        }

        return view('offboarding.index', [
            'cases' => $cases->paginate(25),
            'activeCount' => (clone $cases)->whereIn('status', ['in_progress', 'ready_to_close'])->count(),
            'readyCount' => (clone $cases)->where('status', 'ready_to_close')->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $employees = User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->orderBy('name');
        if ($request->user()->role === 'hr_manager') {
            $employees->where('manager_id', $request->user()->id);
        }

        return view('offboarding.create', [
            'employees' => $employees->get(),
            'owners' => User::whereIn('role', ['system_admin', 'hr_director', 'hr_manager'])->where('is_active', true)->orderBy('name')->get(),
            'reasons' => self::REASONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', ['system_admin', 'hr_director', 'hr_manager'])->where('is_active', true)],
            'reason' => ['required', Rule::in(self::REASONS)],
            'reason_details' => ['nullable', 'string', 'max:3000'],
            'notice_received_on' => ['nullable', 'date', 'before_or_equal:last_working_day'],
            'last_working_day' => ['required', 'date', 'after_or_equal:notice_received_on'],
        ]);
        $ownerId = $request->user()->role === 'hr_manager' ? $request->user()->id : ($data['owner_id'] ?? $request->user()->id);

        $case = DB::transaction(function () use ($request, $data, $ownerId): OffboardingCase {
            $employee = User::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            if ($employee->role !== 'employee' || ! $employee->is_active || ! $employee->employee_no) {
                throw ValidationException::withMessages(['employee_id' => 'Choose an active employee account.']);
            }
            if ($request->user()->role === 'hr_manager' && $employee->manager_id !== $request->user()->id) {
                abort(403);
            }
            if ($employee->offboardingCases()->whereIn('status', ['in_progress', 'ready_to_close'])->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'This employee already has an open offboarding case.']);
            }
            $case = OffboardingCase::create([
                'employee_id' => $employee->id,
                'owner_id' => $ownerId,
                'created_by' => $request->user()->id,
                'reason' => $data['reason'],
                'reason_details' => $data['reason_details'] ?? null,
                'notice_received_on' => $data['notice_received_on'] ?? null,
                'last_working_day' => $data['last_working_day'],
                'status' => 'in_progress',
            ]);
            $this->audit($request, 'offboarding.case_created', $case, ['employee_id' => $employee->id, 'reason' => $case->reason, 'last_working_day' => $case->last_working_day->toDateString()]);

            return $case;
        });

        return redirect()->route('offboarding.show', $case)->with('status', 'Offboarding case opened.');
    }

    public function show(Request $request, OffboardingCase $offboardingCase): View
    {
        $this->authorizeCase($request->user(), $offboardingCase);

        return view('offboarding.show', [
            'case' => $offboardingCase->load(['employee', 'owner', 'clearances.assignee', 'clearances.completer', 'completer']),
            'assignees' => User::where('is_active', true)->whereIn('role', ['system_admin', 'hr_director', 'hr_manager', 'employee'])->orderBy('name')->get(),
        ]);
    }

    public function myCases(Request $request): View
    {
        abort_unless($request->user()->role === 'employee' && $request->user()->employee_no, 403);

        return view('offboarding.mine', [
            'cases' => $request->user()->offboardingCases()->with(['clearances' => fn ($query) => $query->orderBy('id')])->latest()->paginate(10),
        ]);
    }

    public function storeClearance(Request $request, OffboardingCase $offboardingCase): RedirectResponse
    {
        $this->authorizeCase($request->user(), $offboardingCase);
        $this->authorizeCaseOwner($request->user(), $offboardingCase);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'department' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereIn('role', ['system_admin', 'hr_director', 'hr_manager', 'employee'])],
            'due_on' => ['nullable', 'date'],
        ]);
        DB::transaction(function () use ($request, $offboardingCase, $data): void {
            $case = OffboardingCase::whereKey($offboardingCase->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($case->status, ['in_progress', 'ready_to_close'], true), 409);
            $item = $case->clearances()->create([...$data, 'status' => 'pending']);
            $case->update(['status' => 'in_progress']);
            $this->audit($request, 'offboarding.clearance_created', $item, ['case_id' => $case->id, 'assigned_to' => $item->assigned_to]);
        });

        return back()->with('status', 'Clearance item added.');
    }

    public function completeClearance(Request $request, OffboardingClearanceItem $offboardingClearanceItem): RedirectResponse
    {
        $case = $offboardingClearanceItem->offboardingCase;
        $actor = $request->user();
        $isOwner = $this->isCaseOwner($actor, $case);
        $isAdministrator = in_array($actor->role, ['system_admin', 'hr_director'], true);
        if ($actor->role === 'employee') {
            abort_unless($case->employee_id === $actor->id && $offboardingClearanceItem->assigned_to === $actor->id, 403);
        } elseif (! $isAdministrator && ! $isOwner) {
            abort_unless($actor->role === 'hr_manager' && $offboardingClearanceItem->assigned_to === $actor->id, 403);
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['cleared', 'waived'])],
            'completion_note' => ['required_if:decision,waived', 'nullable', 'string', 'max:2000'],
        ]);
        abort_if($data['decision'] === 'waived' && ! ($isOwner || $isAdministrator), 403);

        DB::transaction(function () use ($request, $offboardingClearanceItem, $case, $data): void {
            $lockedCase = OffboardingCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($lockedCase->status, ['in_progress', 'ready_to_close'], true), 409);
            $item = OffboardingClearanceItem::whereKey($offboardingClearanceItem->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This clearance item has already been resolved.']);
            }
            $item->update([
                'status' => $data['decision'],
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
                'completion_note' => $data['completion_note'] ?? null,
            ]);
            $this->refreshReadiness($lockedCase);
            $this->audit($request, 'offboarding.clearance_'.$data['decision'], $item, ['case_id' => $case->id]);
        });

        return back()->with('status', 'Clearance item updated.');
    }

    public function recordExitInterview(Request $request, OffboardingCase $offboardingCase): RedirectResponse
    {
        $this->authorizeCase($request->user(), $offboardingCase);
        $this->authorizeCaseOwner($request->user(), $offboardingCase);
        $data = $request->validate([
            'exit_interview_status' => ['required', Rule::in(['completed', 'declined', 'not_applicable'])],
            'exit_interview_at' => ['required_if:exit_interview_status,completed', 'nullable', 'date', 'before_or_equal:now'],
            'exit_interview_note' => ['required', 'string', 'max:5000'],
        ]);
        DB::transaction(function () use ($request, $offboardingCase, $data): void {
            $case = OffboardingCase::whereKey($offboardingCase->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($case->status, ['in_progress', 'ready_to_close'], true), 409);
            $case->update([
                ...$data,
                'exit_interview_at' => $data['exit_interview_status'] === 'completed' ? $data['exit_interview_at'] : null,
            ]);
            $this->audit($request, 'offboarding.exit_interview_recorded', $case, ['status' => $case->exit_interview_status]);
            $this->refreshReadiness($case);
        });

        return back()->with('status', 'Exit interview status recorded.');
    }

    public function updateFinalPayroll(Request $request, OffboardingCase $offboardingCase): RedirectResponse
    {
        $this->authorizeCase($request->user(), $offboardingCase);
        $this->authorizeCaseOwner($request->user(), $offboardingCase);
        $data = $request->validate([
            'final_payroll_status' => ['required', Rule::in(['processed', 'not_applicable'])],
            'final_payroll_note' => ['required', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $offboardingCase, $data): void {
            $case = OffboardingCase::whereKey($offboardingCase->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($case->status, ['in_progress', 'ready_to_close'], true), 409);
            $case->update($data);
            $this->audit($request, 'offboarding.final_payroll_updated', $case, ['status' => $case->final_payroll_status, 'note' => $case->final_payroll_note]);
            $this->refreshReadiness($case);
        });

        return back()->with('status', 'Final payroll status recorded. This records a status only; payroll calculations are not available in this HRMS build.');
    }

    public function close(Request $request, OffboardingCase $offboardingCase): RedirectResponse
    {
        $this->authorizeCase($request->user(), $offboardingCase);
        $this->authorizeCaseOwner($request->user(), $offboardingCase);

        DB::transaction(function () use ($request, $offboardingCase): void {
            $case = OffboardingCase::whereKey($offboardingCase->id)->lockForUpdate()->firstOrFail();
            $employee = User::whereKey($case->employee_id)->lockForUpdate()->firstOrFail();
            $this->refreshReadiness($case);
            if ($case->status !== 'ready_to_close' || $case->last_working_day->isFuture()) {
                throw ValidationException::withMessages(['case' => 'Resolve all clearance items, exit interview, final payroll status, and wait until the last working day before closing.']);
            }
            $case->update([
                'status' => 'completed',
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
                'account_deactivated_at' => now(),
            ]);
            $employee->forceFill(['is_active' => false])->save();
            $this->audit($request, 'offboarding.case_closed', $case, ['employee_id' => $employee->id, 'account_deactivated' => true]);
        });

        return redirect()->route('offboarding.index')->with('status', 'Offboarding case closed and the employee account deactivated.');
    }

    public function cancel(Request $request, OffboardingCase $offboardingCase): RedirectResponse
    {
        $this->authorizeCase($request->user(), $offboardingCase);
        $this->authorizeCaseOwner($request->user(), $offboardingCase);
        $data = $request->validate(['cancellation_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $offboardingCase, $data): void {
            $case = OffboardingCase::whereKey($offboardingCase->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($case->status, ['in_progress', 'ready_to_close'], true), 409);
            $case->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_note' => $data['cancellation_note']]);
            $this->audit($request, 'offboarding.case_cancelled', $case, []);
        });

        return back()->with('status', 'Offboarding case cancelled. The employee account remains active.');
    }

    private function refreshReadiness(OffboardingCase $case): void
    {
        if (in_array($case->status, ['completed', 'cancelled'], true)) {
            return;
        }
        $hasPendingClearances = $case->clearances()->where('status', 'pending')->exists();
        $interviewResolved = in_array($case->exit_interview_status, ['completed', 'declined', 'not_applicable'], true);
        $payrollResolved = in_array($case->final_payroll_status, ['processed', 'not_applicable'], true);
        $case->update(['status' => ! $hasPendingClearances && $interviewResolved && $payrollResolved ? 'ready_to_close' : 'in_progress']);
    }

    private function authorizeCase(User $actor, OffboardingCase $case): void
    {
        abort_unless(in_array($actor->role, ['system_admin', 'hr_director'], true) || ($actor->role === 'hr_manager' && $case->owner_id === $actor->id), 403);
    }

    private function authorizeCaseOwner(User $actor, OffboardingCase $case): void
    {
        abort_unless(in_array($actor->role, ['system_admin', 'hr_director'], true) || $case->owner_id === $actor->id, 403);
    }

    private function isCaseOwner(User $actor, OffboardingCase $case): bool
    {
        return $case->owner_id === $actor->id;
    }

    /** @param array<string, mixed> $details */
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
