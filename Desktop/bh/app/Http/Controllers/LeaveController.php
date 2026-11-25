<?php

namespace App\Http\Controllers;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function index(Request $request): View
    {
        $year = (int) $request->query('year', now()->year);
        abort_unless($year >= 2000 && $year <= 2200, 422);

        return view('leave.mine', [
            'year' => $year,
            'types' => LeaveType::where('is_active', true)->orderBy('name')->get(),
            'balances' => LeaveBalance::with('leaveType')->where('employee_id', $request->user()->id)->where('year', $year)->orderBy('leave_type_id')->get(),
            'requests' => LeaveRequest::with(['leaveType', 'reviewer'])->where('employee_id', $request->user()->id)->latest('start_date')->paginate(15)->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);
        $startDate = CarbonImmutable::parse($data['start_date']);
        $endDate = CarbonImmutable::parse($data['end_date']);
        if ($startDate->year !== $endDate->year) {
            throw ValidationException::withMessages(['end_date' => 'Submit separate requests for dates in different calendar years.']);
        }

        $requestedDays = $startDate->diffInDays($endDate) + 1;
        DB::transaction(function () use ($data, $request, $startDate, $endDate, $requestedDays): void {
            $leaveType = LeaveType::whereKey($data['leave_type_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $hasOverlap = LeaveRequest::where('employee_id', $request->user()->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('start_date', '<=', $endDate->toDateString())
                ->whereDate('end_date', '>=', $startDate->toDateString())
                ->exists();
            if ($hasOverlap) {
                throw ValidationException::withMessages(['start_date' => 'This request overlaps a pending or approved leave request.']);
            }

            if ($leaveType->deducts_balance) {
                $balance = LeaveBalance::where('employee_id', $request->user()->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('year', $startDate->year)
                    ->lockForUpdate()
                    ->first();
                $pendingDays = LeaveRequest::where('employee_id', $request->user()->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('status', 'pending')
                    ->whereYear('start_date', $startDate->year)
                    ->sum('requested_days');
                if ($balance === null || ((float) $balance->allocated_days - (float) $balance->used_days - (float) $pendingDays) < $requestedDays) {
                    throw ValidationException::withMessages(['leave_type_id' => 'There is not enough allocated balance for this request. Contact HR if your balance is incorrect.']);
                }
            }

            $attachment = isset($data['attachment']) ? $this->storeAttachment($data['attachment']) : null;
            $leaveRequest = LeaveRequest::create([
                'employee_id' => $request->user()->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'requested_days' => $requestedDays,
                'reason' => $data['reason'],
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_name' => $attachment['name'] ?? null,
                'attachment_mime' => $attachment['mime'] ?? null,
            ]);
            $this->audit($request, 'leave.requested', 'leave_request', $leaveRequest->id, ['leave_type_id' => $leaveType->id, 'start_date' => $startDate->toDateString(), 'end_date' => $endDate->toDateString(), 'requested_days' => $requestedDays]);
        });

        return redirect()->route('leave.index')->with('status', 'Leave request submitted for review.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($leaveRequest->employee_id === $request->user()->id, 403);

        DB::transaction(function () use ($request, $leaveRequest): void {
            $lockedRequest = LeaveRequest::whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedRequest->status, ['pending', 'approved'], true) || ($lockedRequest->status === 'approved' && $lockedRequest->start_date->lt(CarbonImmutable::today()))) {
                throw ValidationException::withMessages(['leave' => 'Only pending or future approved leave can be cancelled.']);
            }
            if ($lockedRequest->status === 'approved') {
                $leaveType = LeaveType::findOrFail($lockedRequest->leave_type_id);
                if ($leaveType->deducts_balance) {
                    $balance = LeaveBalance::where('employee_id', $lockedRequest->employee_id)
                        ->where('leave_type_id', $lockedRequest->leave_type_id)
                        ->where('year', $lockedRequest->start_date->year)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $balance->decrement('used_days', (float) $lockedRequest->requested_days);
                }
            }
            $lockedRequest->update(['status' => 'cancelled']);
            $this->audit($request, 'leave.cancelled', 'leave_request', $lockedRequest->id, []);
        });

        return back()->with('status', 'Leave request cancelled.');
    }

    public function approvals(): View
    {
        $this->authorizeReviewer(auth()->user(), null);

        return view('leave.approvals', [
            'requests' => $this->reviewableRequests()->with(['employee.branch', 'leaveType'])->latest('start_date')->paginate(20),
        ]);
    }

    public function review(Request $request, LeaveRequest $leaveRequest, string $decision): RedirectResponse
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);
        $this->authorizeReviewer($request->user(), $leaveRequest);

        DB::transaction(function () use ($request, $leaveRequest, $decision, $data): void {
            $lockedRequest = LeaveRequest::whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages(['leave' => 'This request has already been reviewed.']);
            }
            if ($decision === 'approve') {
                $leaveType = LeaveType::whereKey($lockedRequest->leave_type_id)->lockForUpdate()->firstOrFail();
                if ($leaveType->deducts_balance) {
                    $balance = LeaveBalance::where('employee_id', $lockedRequest->employee_id)
                        ->where('leave_type_id', $lockedRequest->leave_type_id)
                        ->where('year', $lockedRequest->start_date->year)
                        ->lockForUpdate()
                        ->first();
                    if ($balance === null || ((float) $balance->allocated_days - (float) $balance->used_days) < (float) $lockedRequest->requested_days) {
                        throw ValidationException::withMessages(['leave' => 'The employee no longer has enough allocated balance to approve this request.']);
                    }
                    $balance->increment('used_days', (float) $lockedRequest->requested_days);
                }
            }
            $lockedRequest->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ]);
            $this->audit($request, 'leave.'.$lockedRequest->status, 'leave_request', $lockedRequest->id, ['employee_id' => $lockedRequest->employee_id, 'requested_days' => (float) $lockedRequest->requested_days]);
        });

        return back()->with('status', 'Leave request '.($decision === 'approve' ? 'approved' : 'rejected').'.');
    }

    public function settings(): View
    {
        abort_unless(in_array(auth()->user()->role, ['system_admin', 'hr_director'], true), 403);

        return view('leave.settings', [
            'types' => LeaveType::withCount('requests')->orderBy('name')->get(),
            'employees' => User::whereNotNull('employee_no')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_no']),
            'balances' => LeaveBalance::with(['employee', 'leaveType'])->latest('year')->latest('updated_at')->paginate(30),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director'], true), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:leave_types,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'deducts_balance' => ['required', 'boolean'],
            'payroll_treatment' => ['required', Rule::in(['paid', 'unpaid', 'fractional'])],
            'payroll_paid_fraction' => ['required_if:payroll_treatment,fractional', 'nullable', 'numeric', 'between:0,1'],
        ]);
        $data['payroll_paid_fraction'] = match ($data['payroll_treatment']) {
            'unpaid' => 0,
            'fractional' => $data['payroll_paid_fraction'],
            default => 1,
        };
        $leaveType = LeaveType::create([...$data, 'is_active' => true]);
        $this->audit($request, 'leave.type_created', 'leave_type', $leaveType->id, [
            'deducts_balance' => $leaveType->deducts_balance,
            'payroll_treatment' => $leaveType->payroll_treatment,
            'payroll_paid_fraction' => $leaveType->payroll_paid_fraction,
            'is_active' => $leaveType->is_active,
        ]);

        return back()->with('status', 'Leave type added. Assign balances where required.');
    }

    public function updateType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director'], true), 403);
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'deducts_balance' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'payroll_treatment' => ['required', Rule::in(['paid', 'unpaid', 'fractional'])],
            'payroll_paid_fraction' => ['required_if:payroll_treatment,fractional', 'nullable', 'numeric', 'between:0,1'],
        ]);
        $data['payroll_paid_fraction'] = match ($data['payroll_treatment']) {
            'unpaid' => 0,
            'fractional' => $data['payroll_paid_fraction'],
            default => 1,
        };
        $originalValues = $leaveType->only(['description', 'deducts_balance', 'is_active', 'payroll_treatment', 'payroll_paid_fraction']);
        $leaveType->update($data);
        $this->audit($request, 'leave.type_updated', 'leave_type', $leaveType->id, [
            'original_values' => $originalValues,
            'revised_values' => $leaveType->only(['description', 'deducts_balance', 'is_active', 'payroll_treatment', 'payroll_paid_fraction']),
        ]);

        return back()->with('status', 'Leave type policy updated.');
    }

    public function storeBalance(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director'], true), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNotNull('employee_no')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'year' => ['required', 'integer', 'between:2000,2200'],
            'allocated_days' => ['required', 'numeric', 'min:0', 'max:365'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            $balance = LeaveBalance::where('employee_id', $data['employee_id'])->where('leave_type_id', $data['leave_type_id'])->where('year', $data['year'])->lockForUpdate()->first();
            if ($balance !== null && (float) $data['allocated_days'] < (float) $balance->used_days) {
                throw ValidationException::withMessages(['allocated_days' => 'Allocation cannot be lower than the days already approved.']);
            }
            $previousAllocation = $balance?->allocated_days;
            $balance = LeaveBalance::updateOrCreate(
                ['employee_id' => $data['employee_id'], 'leave_type_id' => $data['leave_type_id'], 'year' => $data['year']],
                ['allocated_days' => $data['allocated_days'], 'updated_by' => $request->user()->id],
            );
            $this->audit($request, 'leave.balance_updated', 'leave_balance', $balance->id, ['employee_id' => $data['employee_id'], 'leave_type_id' => $data['leave_type_id'], 'year' => $data['year'], 'previous_allocated_days' => $previousAllocation, 'allocated_days' => $data['allocated_days']]);
        });

        return back()->with('status', 'Leave balance saved.');
    }

    private function reviewableRequests(): Builder
    {
        return LeaveRequest::query()->where('status', 'pending')
            ->when(auth()->user()->role === 'hr_manager', fn (Builder $query) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('manager_id', auth()->id())))
            ->when(! in_array(auth()->user()->role, ['system_admin', 'hr_director', 'hr_manager'], true), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    private function authorizeReviewer(User $reviewer, ?LeaveRequest $leaveRequest): void
    {
        abort_unless(in_array($reviewer->role, ['system_admin', 'hr_director', 'hr_manager'], true), 403);
        if ($leaveRequest !== null) {
            abort_if($leaveRequest->employee_id === $reviewer->id, 403);
            abort_unless(in_array($reviewer->role, ['system_admin', 'hr_director'], true) || $leaveRequest->employee()->where('manager_id', $reviewer->id)->exists(), 403);
        }
    }

    /** @return array{path: string, name: string, mime: string}|null */
    private function storeAttachment(mixed $attachment): ?array
    {
        if (! $attachment) {
            return null;
        }
        $extension = $attachment->guessExtension() ?? $attachment->extension();
        $path = $attachment->storeAs('leave-attachments', Str::uuid().'.'.$extension, config('filesystems.private_disk'));
        if ($path === false) {
            abort(500, 'The leave attachment could not be stored.');
        }

        return [
            'path' => $path,
            'name' => basename(str_replace('\\', '/', $attachment->getClientOriginalName())),
            'mime' => $attachment->getMimeType() ?? 'application/octet-stream',
        ];
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, string $action, string $subjectType, ?int $subjectId, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => json_encode($details),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
