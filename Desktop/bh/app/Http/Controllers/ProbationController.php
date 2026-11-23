<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProbationController extends Controller
{
    public function index(Request $request): View
    {
        $employees = User::query()
            ->where('role', 'employee')
            ->where('is_active', true)
            ->whereNotNull('employee_no')
            ->whereNotNull('probation_ends_on')
            ->when($request->user()->role === 'hr_manager', fn ($query) => $query->where('manager_id', $request->user()->id))
            ->with('probationReviewer')
            ->orderBy('probation_ends_on')
            ->paginate(30);
        $employees->getCollection()->each(function (User $employee): void {
            $employee->setAttribute('probation_days_remaining', CarbonImmutable::today()->diffInDays($employee->probation_ends_on, false));
        });

        return view('probation.index', [
            'employees' => $employees,
            'overdueCount' => User::query()->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->whereNotNull('probation_ends_on')->whereIn('probation_review_status', ['pending', 'in_review', 'extended'])->whereDate('probation_ends_on', '<', today())->when($request->user()->role === 'hr_manager', fn ($query) => $query->where('manager_id', $request->user()->id))->count(),
            'dueSoonCount' => User::query()->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->whereNotNull('probation_ends_on')->whereIn('probation_review_status', ['pending', 'in_review', 'extended'])->whereBetween('probation_ends_on', [today()->toDateString(), today()->addDays(30)->toDateString()])->when($request->user()->role === 'hr_manager', fn ($query) => $query->where('manager_id', $request->user()->id))->count(),
        ]);
    }

    public function review(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->role === 'employee' && $employee->employee_no && $employee->is_active && $employee->probation_ends_on, 404);
        $this->authorizeEmployee($request->user(), $employee);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['in_review', 'confirmed', 'extended', 'not_confirmed'])],
            'review_note' => ['required', 'string', 'max:3000'],
            'new_probation_ends_on' => ['required_if:decision,extended', 'nullable', 'date', 'after:today', 'after:'.$employee->probation_ends_on->toDateString()],
        ]);

        DB::transaction(function () use ($request, $employee, $data): void {
            $lockedEmployee = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $currentStatus = $lockedEmployee->probation_review_status ?? 'pending';
            if (in_array($currentStatus, ['confirmed', 'not_confirmed'], true)) {
                throw ValidationException::withMessages(['decision' => 'This probation review is closed. Contact an HR director to correct the employee record.']);
            }

            $originalEndDate = $lockedEmployee->probation_ends_on?->toDateString();
            $lockedEmployee->forceFill([
                'probation_review_status' => $data['decision'],
                'probation_review_note' => $data['review_note'],
                'probation_reviewed_at' => now(),
                'probation_reviewed_by' => $request->user()->id,
                ...($data['decision'] === 'extended' ? ['probation_ends_on' => $data['new_probation_ends_on']] : []),
            ])->save();
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => 'employee.probation_reviewed',
                'subject_type' => 'employee',
                'subject_id' => $lockedEmployee->id,
                'details' => json_encode([
                    'employee_no' => $lockedEmployee->employee_no,
                    'from_status' => $currentStatus,
                    'to_status' => $data['decision'],
                    'original_probation_ends_on' => $originalEndDate,
                    'revised_probation_ends_on' => $lockedEmployee->probation_ends_on->toDateString(),
                    'review_note' => $data['review_note'],
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Probation review saved.');
    }

    private function authorizeEmployee(User $actor, User $employee): void
    {
        abort_unless(in_array($actor->role, ['system_admin', 'hr_director'], true) || ($actor->role === 'hr_manager' && $employee->manager_id === $actor->id), 403);
    }
}
