<?php

namespace App\Http\Controllers;

use App\Models\EmployeePayrollLoan;
use App\Models\EmployeeSalaryAssignment;
use App\Models\LeaveRequest;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollLoanRepayment;
use App\Models\PayrollOvertime;
use App\Models\PayrollOvertimeRule;
use App\Models\PayrollPeriod;
use App\Models\PayrollStatutoryRule;
use App\Models\SalaryStructure;
use App\Models\SalaryStructureComponent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Payroll\OvertimePayrollEngine;
use App\Payroll\PayrollCalculator;
use App\Payroll\StatutoryPayrollEngine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        return view('payroll.index', [
            'periods' => PayrollPeriod::with(['processor', 'approver', 'payer'])->latest('starts_on')->paginate(20),
            'structures' => SalaryStructure::with(['components'])->withCount(['assignments', 'components'])->latest()->paginate(15, ['*'], 'structures_page'),
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->orderBy('name')->paginate(50, [
                'id', 'name', 'employee_no', 'payroll_profile', 'tax_identifier', 'pension_member_number', 'nhif_member_number',
                'heslb_account_number', 'bank_name', 'bank_branch', 'bank_account_number',
            ], 'employees_page')->withQueryString(),
            'activeStructures' => SalaryStructure::where('is_active', true)->orderBy('name')->get(),
            'defaultCountryCode' => SystemSetting::value('default_country_code', 'TZ'),
            'defaultCurrency' => SystemSetting::value('default_currency', 'TZS'),
            'tanzaniaPayrollJurisdiction' => SystemSetting::value('tanzania_payroll_jurisdiction', 'mainland'),
            'statutoryRulesConfigured' => PayrollStatutoryRule::where('is_active', true)->count(),
            'pendingOvertimeCount' => PayrollOvertime::where('status', 'pending')->count(),
            'pendingLoanCount' => EmployeePayrollLoan::where('status', 'pending')->count(),
        ]);
    }

    public function componentSettings(): View
    {
        return view('payroll.component-settings', [
            'structures' => SalaryStructure::with('components')->orderBy('name')->get(),
        ]);
    }

    public function updateComponentSettings(Request $request, SalaryStructureComponent $salaryStructureComponent): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:80', Rule::unique('salary_structure_components', 'code')
                ->where('salary_structure_id', $salaryStructureComponent->salary_structure_id)
                ->ignore($salaryStructureComponent->id)],
            'is_taxable' => ['required', 'boolean'],
            'is_pensionable' => ['required', 'boolean'],
            'is_statutory' => ['required', 'boolean'],
            'is_recurring' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        DB::transaction(function () use ($request, $salaryStructureComponent, $data): void {
            $component = SalaryStructureComponent::whereKey($salaryStructureComponent->id)->lockForUpdate()->firstOrFail();
            $previous = $component->only(array_keys($data));
            $component->update($data);
            $this->audit($request, 'payroll.salary_component_settings_updated', $component, [
                'previous' => $previous,
                'current' => $component->only(array_keys($data)),
            ]);
        });

        return back()->with('status', 'Salary component settings updated. Processed payroll snapshots remain unchanged.');
    }

    public function assignmentSettings(): View
    {
        return view('payroll.assignment-settings', [
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->orderBy('name')->paginate(50),
            'activeStructures' => SalaryStructure::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateStatutoryProfile(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->role === 'employee' && $employee->is_active, 404);
        $data = $request->validate([
            'payroll_status' => ['required', Rule::in(['active', 'excluded'])],
            'tax_residency' => ['required', Rule::in(['resident', 'non_resident'])],
            'pension_fund' => ['required', Rule::in(['nssf', 'psssf', 'none'])],
            'nhif_enrolled' => ['sometimes', 'boolean'],
            'heslb_billed' => ['sometimes', 'boolean'],
            'tax_identifier' => ['nullable', 'string', 'max:40'],
            'pension_member_number' => ['nullable', 'string', 'max:60'],
            'nhif_member_number' => ['nullable', 'string', 'max:60'],
            'heslb_account_number' => ['nullable', 'string', 'max:60'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:80'],
        ]);
        $profile = [
            'payroll_status' => $data['payroll_status'],
            'tax_residency' => $data['tax_residency'],
            'pension_fund' => $data['pension_fund'],
            'nhif_enrolled' => $request->boolean('nhif_enrolled'),
            'heslb_billed' => $request->boolean('heslb_billed'),
        ];
        $sensitiveFields = [
            'tax_identifier', 'pension_member_number', 'nhif_member_number', 'heslb_account_number',
            'bank_name', 'bank_branch', 'bank_account_number',
        ];
        $sensitiveValues = array_intersect_key($data, array_flip($sensitiveFields));
        foreach ($sensitiveFields as $field) {
            $sensitiveValues[$field] = $data[$field] ?? null;
        }

        DB::transaction(function () use ($request, $employee, $profile, $sensitiveValues): void {
            $lockedEmployee = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $before = $lockedEmployee->payroll_profile ?? [];
            $changedFields = [];
            foreach ($sensitiveValues as $field => $value) {
                if ($lockedEmployee->{$field} !== $value) {
                    $changedFields[] = $field;
                }
            }
            foreach ($profile as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $changedFields[] = $field;
                }
            }
            $lockedEmployee->forceFill([...$sensitiveValues, 'payroll_profile' => $profile]);
            $lockedEmployee->save();
            $this->audit($request, 'payroll.statutory_profile_updated', $lockedEmployee, [
                'changed_fields' => $changedFields,
            ]);
        });

        return back()->with('status', 'Employee payroll and payment profile saved. Statutory rates are applied from the effective-dated rule versions.');
    }

    public function statutorySettings(): View
    {
        return view('payroll.statutory', [
            'rules' => PayrollStatutoryRule::with('creator')->orderBy('code')->orderByDesc('version')->paginate(30),
            'overtimeRules' => PayrollOvertimeRule::with('creator')->orderBy('country_code')->orderBy('overtime_type')->orderByDesc('version')->paginate(30, ['*'], 'overtime_page'),
            'codes' => StatutoryPayrollEngine::CODES,
            'overtimeTypes' => OvertimePayrollEngine::TYPES,
            'jurisdiction' => SystemSetting::value('tanzania_payroll_jurisdiction', 'mainland'),
        ]);
    }

    public function storeStatutoryRule(Request $request, StatutoryPayrollEngine $engine): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        $data = $request->validate([
            'code' => ['required', Rule::in(StatutoryPayrollEngine::CODES)],
            'jurisdiction' => ['required', Rule::in(['mainland', 'zanzibar'])],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'employee_rate' => ['required_unless:code,PAYE', 'nullable', 'numeric', 'between:0,100'],
            'employer_rate' => ['required_unless:code,PAYE', 'nullable', 'numeric', 'between:0,100'],
            'calculation_basis' => ['required_unless:code,PAYE', 'nullable', Rule::in(['basic', 'gross', 'taxable_pay', 'pensionable_pay'])],
            'threshold' => ['nullable', 'numeric', 'min:0'],
            'ceiling' => ['nullable', 'numeric', 'gt:0'],
            'parameters' => ['required_if:code,PAYE,SDL', 'nullable', 'json'],
            'authority_reference' => ['required', 'string', 'max:255'],
        ]);

        if ($data['code'] === 'PAYE') {
            $data['parameters'] = json_decode($data['parameters'] ?? '', true);
            $this->validatePayeParameters($data['parameters']);
            $data['employee_rate'] = null;
            $data['employer_rate'] = null;
            $data['calculation_basis'] = null;
        } else {
            $data['parameters'] = isset($data['parameters']) ? json_decode($data['parameters'], true) : [];
            if (! is_array($data['parameters'])) {
                throw ValidationException::withMessages(['parameters' => 'Rule parameters must be a JSON object.']);
            }
            if ($data['code'] === 'SDL') {
                $minimumEmployeeCount = filter_var($data['parameters']['minimum_employee_count'] ?? null, FILTER_VALIDATE_INT);
                if ($minimumEmployeeCount === false || $minimumEmployeeCount < 1) {
                    throw ValidationException::withMessages(['parameters' => 'SDL configuration must include a verified minimum_employee_count of at least one.']);
                }
            }
            if (in_array($data['code'], ['NSSF', 'PSSSF'], true) && ! is_bool($data['parameters']['paye_deductible'] ?? null)) {
                throw ValidationException::withMessages(['parameters' => 'Pension rule parameters must explicitly state whether contributions reduce PAYE taxable income.']);
            }
        }

        $rule = DB::transaction(function () use ($request, $data, $engine): PayrollStatutoryRule {
            $superseded = PayrollStatutoryRule::query()
                ->where('code', $data['code'])
                ->where('jurisdiction', $data['jurisdiction'])
                ->where('is_active', true)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $data['effective_from'])
                ->lockForUpdate()
                ->pluck('version')
                ->all();
            $rule = $engine->createRule($data, (int) $request->user()->id);
            $this->audit($request, 'payroll.statutory_rule_created', $rule, $engine->snapshot($rule));
            if ($superseded !== []) {
                $this->audit($request, 'payroll.statutory_rule_superseded', $rule, [
                    'code' => $rule->code,
                    'jurisdiction' => $rule->jurisdiction,
                    'superseded_versions' => $superseded,
                    'effective_through' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(),
                ]);
            }

            return $rule;
        });

        return back()->with('status', $rule->code.' rule version '.$rule->version.' saved. The cited authority and effective dates are recorded.');
    }

    public function deactivateStatutoryRule(Request $request, PayrollStatutoryRule $payrollStatutoryRule): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        DB::transaction(function () use ($request, $payrollStatutoryRule): void {
            $rule = PayrollStatutoryRule::whereKey($payrollStatutoryRule->id)->lockForUpdate()->firstOrFail();
            abort_unless($rule->is_active, 409);
            $rule->update(['is_active' => false]);
            $this->audit($request, 'payroll.statutory_rule_deactivated', $rule, [
                'code' => $rule->code,
                'jurisdiction' => $rule->jurisdiction,
                'version' => $rule->version,
                'effective_from' => $rule->effective_from->toDateString(),
            ]);
        });

        return back()->with('status', 'Rule version deactivated for future processing; existing payroll snapshots remain unchanged.');
    }

    public function storeOvertimeRule(Request $request, OvertimePayrollEngine $engine): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        $data = $request->validate([
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'frequency' => ['required', Rule::in(['monthly', 'biweekly', 'weekly'])],
            'overtime_type' => ['required', Rule::in(OvertimePayrollEngine::TYPES)],
            'calculation_basis' => ['required', Rule::in(['basic', 'gross', 'taxable_pay'])],
            'hours_per_period' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'multiplier' => ['required', 'numeric', 'gt:0', 'max:100'],
            'is_taxable' => ['required', 'boolean'],
            'is_pensionable' => ['required', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'authority_reference' => ['required', 'string', 'max:255'],
        ]);
        $rule = $engine->createRule($data, (int) $request->user()->id);
        $this->audit($request, 'payroll.overtime_rule_created', $rule, $engine->snapshot($rule));

        return back()->with('status', 'Overtime rule version saved. Only approved overtime uses a date-matched rule.');
    }

    public function deactivateOvertimeRule(Request $request, PayrollOvertimeRule $payrollOvertimeRule): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        DB::transaction(function () use ($request, $payrollOvertimeRule): void {
            $rule = PayrollOvertimeRule::whereKey($payrollOvertimeRule->id)->lockForUpdate()->firstOrFail();
            abort_unless($rule->is_active, 409);
            $rule->update(['is_active' => false]);
            $this->audit($request, 'payroll.overtime_rule_deactivated', $rule, [
                'overtime_type' => $rule->overtime_type,
                'version' => $rule->version,
                'effective_from' => $rule->effective_from->toDateString(),
            ]);
        });

        return back()->with('status', 'Overtime rule deactivated for future processing; existing snapshots remain unchanged.');
    }

    public function storeOvertimeRequest(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'employee', 403);
        $data = $request->validate([
            'worked_on' => ['required', 'date', 'before_or_equal:today'],
            'overtime_type' => ['required', Rule::in(OvertimePayrollEngine::TYPES)],
            'hours' => ['required', 'regex:/^\d{1,2}(?:\.\d{1,2})?$/', 'gt:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $employee = $request->user();
        abort_unless($employee->is_active && $employee->employee_no !== null, 403);
        $finalizedPeriodContainsDate = PayrollPeriod::whereDate('starts_on', '<=', $data['worked_on'])
            ->whereDate('ends_on', '>=', $data['worked_on'])
            ->whereIn('status', ['processed', 'approved', 'paid', 'closed'])
            ->exists();
        if ($finalizedPeriodContainsDate) {
            throw ValidationException::withMessages([
                'worked_on' => 'This date is already included in a finalized payroll period. Contact payroll to resolve the closed-period discrepancy.',
            ]);
        }

        $overtime = DB::transaction(function () use ($request, $employee, $data): PayrollOvertime {
            User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $duplicateExists = PayrollOvertime::where('employee_id', $employee->id)
                ->whereDate('worked_on', $data['worked_on'])
                ->where('overtime_type', $data['overtime_type'])
                ->where('status', '!=', 'rejected')
                ->exists();
            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'worked_on' => 'A request for this overtime type and date already exists.',
                ]);
            }
            $overtime = PayrollOvertime::create([
                'employee_id' => $employee->id,
                'created_by' => $employee->id,
                'worked_on' => $data['worked_on'],
                'overtime_type' => $data['overtime_type'],
                'hours' => $data['hours'],
                'reason' => $data['reason'],
                'status' => 'pending',
            ]);
            $this->audit($request, 'payroll.overtime_submitted', $overtime, [
                'employee_id' => $employee->id,
                'worked_on' => $overtime->worked_on->toDateString(),
                'hours' => $overtime->hours,
            ]);

            return $overtime;
        });

        return back()->with('status', 'Overtime submitted for independent review. It will not be paid unless approved.');
    }

    public function reviewOvertime(Request $request, PayrollOvertime $payrollOvertime): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager', 'payroll_officer'], true), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $payrollOvertime, $data): void {
            $overtime = PayrollOvertime::whereKey($payrollOvertime->id)->lockForUpdate()->firstOrFail();
            abort_unless($overtime->status === 'pending', 409);
            abort_if((int) $overtime->created_by === (int) $request->user()->id, 403, 'The requester cannot approve their own overtime.');
            if ($request->user()->role === 'hr_manager') {
                abort_unless($overtime->employee()->where('manager_id', $request->user()->id)->exists(), 403);
            }
            $decisionStatus = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            $overtime->update([
                'status' => $decisionStatus,
                'approved_by' => $data['decision'] === 'approve' ? $request->user()->id : null,
                'approved_at' => $data['decision'] === 'approve' ? now() : null,
                'review_note' => $data['review_note'] ?? null,
            ]);
            $this->audit($request, 'payroll.overtime_'.$decisionStatus, $overtime, [
                'employee_id' => $overtime->employee_id,
                'worked_on' => $overtime->worked_on->toDateString(),
                'hours' => $overtime->hours,
            ]);
        });

        return back()->with('status', 'Overtime request '.($data['decision'] === 'approve' ? 'approved' : 'rejected').'.');
    }

    public function storeLoanRequest(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'employee', 403);
        $data = $request->validate([
            'loan_type' => ['required', Rule::in(['advance', 'loan'])],
            'principal_amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        abort_unless($request->user()->is_active && $request->user()->employee_no !== null, 403);
        DB::transaction(function () use ($request, $data): void {
            $currentAssignments = EmployeeSalaryAssignment::with('salaryStructure')
                ->where('employee_id', $request->user()->id)
                ->whereDate('effective_from', '<=', CarbonImmutable::today())
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', CarbonImmutable::today()))
                ->lockForUpdate()
                ->get();
            if ($currentAssignments->count() !== 1) {
                throw ValidationException::withMessages([
                    'principal_amount' => 'A current unambiguous salary assignment is required to request a payroll loan or advance.',
                ]);
            }
            $loanStructure = $currentAssignments->first()->salaryStructure;
            if ($loanStructure === null || ! $loanStructure->is_active) {
                throw ValidationException::withMessages([
                    'principal_amount' => 'An active salary structure is required to set the currency for a payroll loan or advance.',
                ]);
            }
            $loanCurrency = $loanStructure->currency;
            $loan = EmployeePayrollLoan::create([
                'employee_id' => $request->user()->id,
                'requested_by' => $request->user()->id,
                'loan_type' => $data['loan_type'],
                'currency' => $loanCurrency,
                'principal_amount' => app(PayrollCalculator::class)->money($data['principal_amount']),
                'reason' => $data['reason'],
                'status' => 'pending',
            ]);
            $this->audit($request, 'payroll.loan_requested', $loan, [
                'employee_id' => $loan->employee_id,
                'loan_type' => $loan->loan_type,
                'principal_amount' => $loan->principal_amount,
            ]);
        });

        return back()->with('status', 'Request submitted. Repayment terms must be approved and documented before payroll deductions begin.');
    }

    public function reviewLoan(Request $request, EmployeePayrollLoan $employeePayrollLoan): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director', 'finance_officer'], true), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:1000'],
            'total_repayable' => ['required_if:decision,approve', 'nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'gt:0'],
            'installment_amount' => ['required_if:decision,approve', 'nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'gt:0'],
            'repayment_starts_on' => ['required_if:decision,approve', 'nullable', 'date'],
            'terms_reference' => ['required_if:decision,approve', 'nullable', 'string', 'max:180'],
        ]);
        DB::transaction(function () use ($request, $employeePayrollLoan, $data): void {
            $loan = EmployeePayrollLoan::whereKey($employeePayrollLoan->id)->lockForUpdate()->firstOrFail();
            abort_unless($loan->status === 'pending', 409);
            abort_if((int) $loan->requested_by === (int) $request->user()->id, 403, 'The requester cannot approve their own loan.');
            if ($data['decision'] === 'approve') {
                if (bccomp($data['total_repayable'], $loan->principal_amount, 2) < 0
                    || bccomp($data['installment_amount'], $data['total_repayable'], 2) > 0) {
                    throw ValidationException::withMessages([
                        'total_repayable' => 'Approved repayment must cover the principal, and each installment cannot exceed the total repayable amount.',
                    ]);
                }
            }
            $loan->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'approved_by' => $data['decision'] === 'approve' ? $request->user()->id : null,
                'approved_at' => $data['decision'] === 'approve' ? now() : null,
                'review_note' => $data['review_note'] ?? null,
                'total_repayable' => $data['decision'] === 'approve' ? app(PayrollCalculator::class)->money($data['total_repayable']) : null,
                'installment_amount' => $data['decision'] === 'approve' ? app(PayrollCalculator::class)->money($data['installment_amount']) : null,
                'repayment_starts_on' => $data['decision'] === 'approve' ? $data['repayment_starts_on'] : null,
                'terms_reference' => $data['decision'] === 'approve' ? $data['terms_reference'] : null,
            ]);
            $this->audit($request, 'payroll.loan_'.($data['decision'] === 'approve' ? 'approved' : 'rejected'), $loan, [
                'employee_id' => $loan->employee_id,
                'loan_type' => $loan->loan_type,
                'principal_amount' => $loan->principal_amount,
                'total_repayable' => $loan->total_repayable,
                'terms_reference' => $loan->terms_reference,
            ]);
        });

        return back()->with('status', 'Loan request '.($data['decision'] === 'approve' ? 'approved' : 'rejected').'.');
    }

    public function recordLoanDisbursement(Request $request, EmployeePayrollLoan $employeePayrollLoan): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'finance_officer'], true), 403);
        $data = $request->validate([
            'disbursement_reference' => ['required', 'string', 'max:180'],
            'disbursed_at' => ['required', 'date', 'before_or_equal:today'],
        ]);
        DB::transaction(function () use ($request, $employeePayrollLoan, $data): void {
            $loan = EmployeePayrollLoan::whereKey($employeePayrollLoan->id)->lockForUpdate()->firstOrFail();
            abort_unless($loan->status === 'approved', 409);
            $disbursedDate = CarbonImmutable::parse($data['disbursed_at']);
            if ($loan->approved_at === null || $disbursedDate->lt($loan->approved_at->startOfDay())) {
                throw ValidationException::withMessages([
                    'disbursed_at' => 'The disbursement date must be on or after the approved loan date.',
                ]);
            }
            if ($loan->repayment_starts_on->lt($disbursedDate)) {
                throw ValidationException::withMessages([
                    'disbursed_at' => 'The disbursement date cannot be later than the approved first repayment date.',
                ]);
            }
            $loan->update([
                'status' => 'active',
                'disbursed_by' => $request->user()->id,
                'disbursement_reference' => $data['disbursement_reference'],
                'disbursed_at' => $data['disbursed_at'],
            ]);
            $this->audit($request, 'payroll.loan_disbursed', $loan, [
                'employee_id' => $loan->employee_id,
                'principal_amount' => $loan->principal_amount,
                'disbursement_reference' => $loan->disbursement_reference,
                'disbursed_at' => $loan->disbursed_at->toIso8601String(),
            ]);
        });

        return back()->with('status', 'Loan disbursement recorded. Repayment can now begin on the approved start date.');
    }

    public function storeStructure(Request $request): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'frequency' => ['required', Rule::in(['monthly', 'biweekly', 'weekly'])],
            'basic_salary' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'gt:0'],
        ]);
        $data['basic_salary'] = app(PayrollCalculator::class)->money($data['basic_salary']);
        $structure = SalaryStructure::create([...$data, 'created_by' => $request->user()->id, 'is_active' => true]);
        $this->audit($request, 'payroll.salary_structure_created', $structure, ['currency' => $structure->currency, 'country_code' => $structure->country_code]);

        return back()->with('status', 'Salary structure created. Add the applicable configured components next.');
    }

    public function storeComponent(Request $request, SalaryStructure $salaryStructure): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'component_type' => ['required', Rule::in(['earning', 'benefit', 'deduction'])],
            'calculation_method' => ['required', Rule::in(['fixed', 'percent_basic', 'percent_gross'])],
            'value' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/', 'min:0'],
            'is_taxable' => ['required', 'boolean'],
            'is_pensionable' => ['sometimes', 'boolean'],
            'is_statutory' => ['required', 'boolean'],
            'is_recurring' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        if ($data['calculation_method'] !== 'fixed' && (float) $data['value'] > 1000) {
            throw ValidationException::withMessages(['value' => 'Percentage components cannot exceed 1000%. Configure statutory rates only after jurisdiction review.']);
        }
        $data['value'] = bcadd((string) $data['value'], '0', 4);
        $component = DB::transaction(function () use ($request, $salaryStructure, $data): SalaryStructureComponent {
            $structure = SalaryStructure::whereKey($salaryStructure->id)->lockForUpdate()->firstOrFail();
            abort_unless($structure->is_active, 409);
            $component = $structure->components()->create([...$data, 'is_active' => true]);
            $this->audit($request, 'payroll.salary_component_created', $component, ['salary_structure_id' => $structure->id, 'type' => $component->component_type, 'is_statutory' => $component->is_statutory]);

            return $component;
        });

        return back()->with('status', 'Salary component added.');
    }

    public function deactivateStructure(Request $request, SalaryStructure $salaryStructure): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        DB::transaction(function () use ($request, $salaryStructure): void {
            $structure = SalaryStructure::whereKey($salaryStructure->id)->lockForUpdate()->firstOrFail();
            abort_unless($structure->is_active, 409);
            $structure->update(['is_active' => false]);
            $this->audit($request, 'payroll.salary_structure_deactivated', $structure, ['name' => $structure->name]);
        });

        return back()->with('status', 'Salary structure deactivated. Existing payroll snapshots and assignments are preserved.');
    }

    public function deactivateComponent(Request $request, SalaryStructureComponent $salaryStructureComponent): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        DB::transaction(function () use ($request, $salaryStructureComponent): void {
            SalaryStructure::whereKey($salaryStructureComponent->salary_structure_id)->lockForUpdate()->firstOrFail();
            $component = SalaryStructureComponent::whereKey($salaryStructureComponent->id)->lockForUpdate()->firstOrFail();
            abort_unless($component->is_active, 409);
            $component->update(['is_active' => false]);
            $this->audit($request, 'payroll.salary_component_deactivated', $component, [
                'salary_structure_id' => $component->salary_structure_id,
                'name' => $component->name,
            ]);
        });

        return back()->with('status', 'Salary component deactivated. Historical payroll lines are preserved.');
    }

    public function assignStructure(Request $request): RedirectResponse
    {
        $this->authorizeStructureManagement($request->user());
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')],
            'salary_structure_id' => ['required', 'integer', Rule::exists('salary_structures', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date'],
            'change_reason' => ['required', 'string', 'max:1000'],
            'salary_grade' => ['nullable', 'string', 'max:60'],
            'salary_scale' => ['nullable', 'string', 'max:60'],
            'salary_step' => ['nullable', 'string', 'max:60'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $employee = User::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            $structure = SalaryStructure::whereKey($data['salary_structure_id'])->where('is_active', true)->firstOrFail();
            $overlappingAssignments = EmployeeSalaryAssignment::with('salaryStructure')->where('employee_id', $employee->id)
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
                ->lockForUpdate()->get();
            $previousSalaryAssignments = [];
            foreach ($overlappingAssignments as $assignment) {
                if ($assignment->effective_from->gte($data['effective_from'])) {
                    throw ValidationException::withMessages(['effective_from' => 'A future salary assignment already exists. Review it before adding a new effective date.']);
                }
                $previousSalaryAssignments[] = [
                    'assignment_id' => $assignment->id,
                    'salary_structure_id' => $assignment->salary_structure_id,
                    'basic_salary' => $assignment->salaryStructure?->basic_salary,
                    'effective_from' => $assignment->effective_from->toDateString(),
                    'effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(),
                ];
                $assignment->update(['effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()]);
            }
            $assignment = EmployeeSalaryAssignment::create([...$data, 'assigned_by' => $request->user()->id]);
            $this->audit($request, 'payroll.salary_assigned', $assignment, [
                'employee_id' => $employee->id,
                'salary_structure_id' => $structure->id,
                'salary_amount' => $structure->basic_salary,
                'previous_salary_assignments' => $previousSalaryAssignments,
                'effective_from' => $assignment->effective_from->toDateString(),
                'reason' => $assignment->change_reason,
            ]);
        });

        return back()->with('status', 'Salary structure assigned.');
    }

    public function storePeriod(Request $request): RedirectResponse
    {
        $this->authorizePayrollOperator($request->user());
        $data = $request->validate([
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'frequency' => ['required', Rule::in(['monthly', 'biweekly', 'weekly'])],
            'period_key' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'pay_on' => ['required', 'date', 'after_or_equal:ends_on'],
        ]);
        $period = PayrollPeriod::create([...$data, 'created_by' => $request->user()->id, 'status' => 'draft']);
        $this->audit($request, 'payroll.period_created', $period, ['period_key' => $period->period_key, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString()]);

        return redirect()->route('payroll.periods.show', $period)->with('status', 'Payroll period created in draft.');
    }

    public function showPeriod(Request $request, PayrollPeriod $payrollPeriod, StatutoryPayrollEngine $statutoryEngine): View
    {
        return view('payroll.period', [
            'period' => $payrollPeriod->load(['processor', 'approver', 'payer']),
            'readiness' => $this->payrollReadiness($payrollPeriod, $statutoryEngine),
            'entries' => PayrollEntry::with('lines')->where('payroll_period_id', $payrollPeriod->id)->orderBy('employee_number_snapshot')->paginate(50)->withQueryString(),
            'adjustments' => PayrollAdjustment::with('employee')->where('payroll_period_id', $payrollPeriod->id)->latest()->paginate(50, ['*'], 'adjustments_page')->withQueryString(),
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')
                ->where(fn (Builder $query) => $query->whereNull('payroll_profile->payroll_status')->orWhere('payroll_profile->payroll_status', 'active'))
                ->whereHas('salaryAssignments', fn (Builder $query) => $query->whereDate('effective_from', '<=', $payrollPeriod->ends_on)
                    ->where(fn (Builder $activeQuery) => $activeQuery->whereNull('effective_to')->orWhereDate('effective_to', '>=', $payrollPeriod->starts_on))
                    ->whereHas('salaryStructure', fn (Builder $structureQuery) => $structureQuery->where('country_code', $payrollPeriod->country_code)->where('currency', $payrollPeriod->currency)->where('frequency', $payrollPeriod->frequency)))
                ->orderBy('name')->paginate(50, ['id', 'name', 'employee_no'], 'employees_page')->withQueryString(),
        ]);
    }

    public function storeAdjustment(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        abort_unless($payrollPeriod->status === 'draft', 409);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')],
            'category' => ['required', Rule::in(['bonus', 'overtime', 'loan', 'advance', 'benefit', 'deduction', 'leave_adjustment'])],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'gt:0'],
            'is_taxable' => ['required', 'boolean'],
            'is_pensionable' => ['sometimes', 'boolean'],
        ]);
        $employee = User::whereKey($data['employee_id'])->where('is_active', true)->firstOrFail();
        $lineType = in_array($data['category'], ['loan', 'advance', 'deduction', 'leave_adjustment'], true) ? 'deduction' : ($data['category'] === 'benefit' ? 'benefit' : 'earning');
        $adjustment = DB::transaction(function () use ($request, $payrollPeriod, $employee, $data, $lineType): PayrollAdjustment {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            abort_unless($period->status === 'draft', 409);
            $assignment = $this->assignmentForPeriod($employee, $period);
            abort_unless($assignment !== null && $assignment->salaryStructure->country_code === $period->country_code && $assignment->salaryStructure->currency === $period->currency && $assignment->salaryStructure->frequency === $period->frequency, 422, 'The employee must have a matching active salary structure for this period.');
            $adjustment = $period->adjustments()->create([
                'employee_id' => $employee->id,
                'created_by' => $request->user()->id,
                'category' => $data['category'],
                'line_type' => $lineType,
                'description' => $data['description'],
                'amount' => app(PayrollCalculator::class)->money($data['amount']),
                'is_taxable' => (bool) $data['is_taxable'],
                'is_pensionable' => (bool) ($data['is_pensionable'] ?? false),
            ]);
            $this->audit($request, 'payroll.adjustment_created', $adjustment, ['period_id' => $period->id, 'employee_id' => $employee->id, 'category' => $adjustment->category, 'amount' => $adjustment->amount]);

            return $adjustment;
        });

        return back()->with('status', 'Payroll adjustment added to the draft.');
    }

    public function process(
        Request $request,
        PayrollPeriod $payrollPeriod,
        PayrollCalculator $calculator,
        StatutoryPayrollEngine $statutoryEngine,
        OvertimePayrollEngine $overtimeEngine
    ): RedirectResponse {
        $this->authorizePayrollOperator($request->user());
        DB::transaction(function () use ($request, $payrollPeriod, $calculator, $statutoryEngine, $overtimeEngine): void {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'draft') {
                throw ValidationException::withMessages(['period' => 'Only a draft period can be processed.']);
            }
            $eligibleEmployees = User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')
                ->where(fn (Builder $query) => $query->whereNull('payroll_profile->payroll_status')->orWhere('payroll_profile->payroll_status', 'active'));
            $eligibleEmployeeCount = (clone $eligibleEmployees)->count();
            $employees = (clone $eligibleEmployees)
                ->whereHas('salaryAssignments', fn (Builder $query) => $query->whereDate('effective_from', '<=', $period->ends_on)
                    ->where(fn (Builder $activeQuery) => $activeQuery->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->starts_on))
                    ->whereHas('salaryStructure', fn (Builder $structureQuery) => $structureQuery->where('country_code', $period->country_code)->where('currency', $period->currency)->where('frequency', $period->frequency)))
                ->orderBy('id')->lockForUpdate();
            $employeeCount = (clone $employees)->count();
            if ($employeeCount === 0) {
                throw ValidationException::withMessages(['period' => 'No active employees have a salary assignment for this period.']);
            }
            if ($employeeCount !== $eligibleEmployeeCount) {
                throw ValidationException::withMessages(['period' => 'Every employee marked active for payroll must have a matching full-period salary assignment. Explicitly exclude ineligible employees or complete their assignment before processing.']);
            }
            $unreviewedOvertimeExists = PayrollOvertime::whereBetween('worked_on', [$period->starts_on, $period->ends_on])
                ->where('status', 'pending')
                ->whereHas('employee', fn (Builder $query) => $query
                    ->where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')
                    ->where(fn (Builder $statusQuery) => $statusQuery->whereNull('payroll_profile->payroll_status')->orWhere('payroll_profile->payroll_status', 'active')))
                ->exists();
            if ($unreviewedOvertimeExists) {
                throw ValidationException::withMessages(['period' => 'Review or reject all pending overtime requests in this payroll period before processing.']);
            }
            $unadjustedUnpaidLeaveExists = LeaveRequest::query()
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $period->ends_on)
                ->whereDate('end_date', '>=', $period->starts_on)
                ->whereHas('leaveType', fn (Builder $query) => $query->where('payroll_treatment', '!=', 'paid'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('payroll_adjustments')
                    ->whereColumn('payroll_adjustments.employee_id', 'leave_requests.employee_id')
                    ->where('payroll_adjustments.payroll_period_id', $period->id)
                    ->where('payroll_adjustments.category', 'leave_adjustment'))
                ->exists();
            if ($unadjustedUnpaidLeaveExists) {
                throw ValidationException::withMessages([
                    'period' => 'Approved leave with a non-full-pay treatment overlaps this period. Add an employer-reviewed leave adjustment using the approved working-day policy; calendar-day leave totals are never deducted automatically.',
                ]);
            }
            $isTanzaniaPayroll = $period->country_code === 'TZ' && $period->currency === 'TZS';
            $jurisdiction = SystemSetting::value('tanzania_payroll_jurisdiction', 'mainland');
            $usedStatutoryRules = [];
            $resolvedStatutoryRules = [];
            $usedOvertimeRules = [];
            $ruleForCode = function (string $code) use ($statutoryEngine, $jurisdiction, $period, &$usedStatutoryRules, &$resolvedStatutoryRules): PayrollStatutoryRule {
                if (! isset($resolvedStatutoryRules[$code])) {
                    $resolvedStatutoryRules[$code] = $statutoryEngine->ruleForPeriod($code, $jurisdiction, $period);
                    $usedStatutoryRules[$code] = $statutoryEngine->snapshot($resolvedStatutoryRules[$code]);
                }

                return $resolvedStatutoryRules[$code];
            };
            $sdlRule = null;
            if ($isTanzaniaPayroll) {
                foreach (StatutoryPayrollEngine::CODES as $code) {
                    $ruleForCode($code);
                }
                $sdlRule = $ruleForCode('SDL');
            }
            $sdlMinimumEmployees = (int) ($sdlRule?->parameters['minimum_employee_count'] ?? PHP_INT_MAX);
            $sdlApplies = $sdlRule !== null && $employeeCount >= $sdlMinimumEmployees;
            if ($sdlApplies) {
                $usedStatutoryRules['SDL'] = $statutoryEngine->snapshot($sdlRule);
            }
            $totals = ['gross' => '0.00', 'deductions' => '0.00', 'net' => '0.00', 'employer_contributions' => '0.00'];
            $employees->chunkById(100, function ($employeeBatch) use ($period, $calculator, $statutoryEngine, $overtimeEngine, $ruleForCode, $sdlRule, $isTanzaniaPayroll, $sdlApplies, &$totals, &$usedOvertimeRules): void {
                foreach ($employeeBatch as $employee) {
                    $assignment = $this->assignmentForPeriod($employee, $period);
                    if ($assignment === null) {
                        continue;
                    }
                    $structure = SalaryStructure::whereKey($assignment->salary_structure_id)->lockForUpdate()->firstOrFail();
                    $componentChangesMidPeriod = $structure->components()->where('is_active', true)->where('is_recurring', true)
                        ->where(fn (Builder $query) => $query
                            ->where(fn (Builder $effectiveFromQuery) => $effectiveFromQuery
                                ->whereDate('effective_from', '>', $period->starts_on)
                                ->whereDate('effective_from', '<=', $period->ends_on))
                            ->orWhere(fn (Builder $effectiveToQuery) => $effectiveToQuery
                                ->whereDate('effective_to', '>=', $period->starts_on)
                                ->whereDate('effective_to', '<', $period->ends_on)))
                        ->exists();
                    if ($componentChangesMidPeriod) {
                        throw ValidationException::withMessages([
                            'period' => 'A salary component starts or ends mid-period. Enter an approved pro-rata adjustment instead of silently applying a full-period amount.',
                        ]);
                    }
                    $structure->setRelation('components', $structure->components()->where('is_active', true)->where('is_recurring', true)
                        ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $period->starts_on))
                        ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->ends_on))
                        ->orderBy('id')
                        ->lockForUpdate()->get());
                    if ($structure->country_code !== $period->country_code || $structure->currency !== $period->currency || $structure->frequency !== $period->frequency) {
                        throw ValidationException::withMessages(['period' => 'Every employee must have a salary structure matching the period country, currency, and frequency.']);
                    }
                    $adjustments = $period->adjustments()->where('employee_id', $employee->id)->get();
                    $earningTotal = '0.00';
                    $deductionTotal = '0.00';
                    $benefitTotal = '0.00';
                    $taxableEarningsTotal = '0.00';
                    $pensionableEarningsTotal = $structure->basic_salary;
                    $entry = PayrollEntry::create([
                        'payroll_period_id' => $period->id,
                        'employee_id' => $employee->id,
                        'salary_structure_id' => $structure->id,
                        'employee_number_snapshot' => $employee->employee_no,
                        'employee_name_snapshot' => $employee->name,
                        'department_snapshot' => $employee->department,
                        'job_title_snapshot' => $employee->job_title,
                        'tax_identifier_snapshot' => $employee->tax_identifier === null ? null : Crypt::encryptString($employee->tax_identifier),
                        'tax_residency_snapshot' => ($employee->payroll_profile ?? [])['tax_residency'] ?? null,
                        'pension_fund_snapshot' => ($employee->payroll_profile ?? [])['pension_fund'] ?? null,
                        'currency' => $period->currency,
                        'basic_amount' => $calculator->money($structure->basic_salary),
                        'gross_amount' => '0.00', 'deductions_amount' => '0.00', 'benefits_amount' => '0.00', 'net_amount' => '0.00', 'employer_contributions_amount' => '0.00',
                    ]);
                    $this->createLine($entry, 'Basic salary', 'earning', 'fixed', $structure->basic_salary, true, false, null, null, null);
                    $earningTotal = $calculator->money(bcadd($earningTotal, $structure->basic_salary, 4));
                    $taxableEarningsTotal = $calculator->money(bcadd($taxableEarningsTotal, $structure->basic_salary, 4));
                    foreach ($structure->components as $component) {
                        $base = match ($component->calculation_method) {
                            'percent_basic' => $structure->basic_salary,
                            'percent_gross' => $earningTotal,
                            default => null,
                        };
                        $amount = $base === null ? $calculator->money($component->value) : $calculator->percent($base, $component->value);
                        $this->createLine($entry, $component->name, $component->component_type, $component->calculation_method, $amount, $component->is_taxable, $component->is_statutory, $base, $component->calculation_method === 'fixed' ? null : $component->value, $component->id, componentCode: $component->code);
                        if ($component->component_type === 'deduction') {
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $amount, 4));
                        } else {
                            $earningTotal = $calculator->money(bcadd($earningTotal, $amount, 4));
                            if ($component->is_taxable) {
                                $taxableEarningsTotal = $calculator->money(bcadd($taxableEarningsTotal, $amount, 4));
                            }
                            if ($component->is_pensionable) {
                                $pensionableEarningsTotal = $calculator->money(bcadd($pensionableEarningsTotal, $amount, 4));
                            }
                            if ($component->component_type === 'benefit') {
                                $benefitTotal = $calculator->money(bcadd($benefitTotal, $amount, 4));
                            }
                        }
                    }
                    foreach ($adjustments as $adjustment) {
                        $this->createLine($entry, $adjustment->description, $adjustment->line_type, 'fixed', $adjustment->amount, $adjustment->is_taxable, false, null, null, null, $adjustment->id);
                        if ($adjustment->line_type === 'deduction') {
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $adjustment->amount, 4));
                        } else {
                            $earningTotal = $calculator->money(bcadd($earningTotal, $adjustment->amount, 4));
                            if ($adjustment->is_taxable) {
                                $taxableEarningsTotal = $calculator->money(bcadd($taxableEarningsTotal, $adjustment->amount, 4));
                            }
                            if ($adjustment->is_pensionable) {
                                $pensionableEarningsTotal = $calculator->money(bcadd($pensionableEarningsTotal, $adjustment->amount, 4));
                            }
                            if ($adjustment->line_type === 'benefit') {
                                $benefitTotal = $calculator->money(bcadd($benefitTotal, $adjustment->amount, 4));
                            }
                        }
                    }
                    $overtimeRecords = PayrollOvertime::query()
                        ->where('employee_id', $employee->id)
                        ->where('status', 'approved')
                        ->whereNull('payroll_period_id')
                        ->whereBetween('worked_on', [$period->starts_on, $period->ends_on])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $grossBeforeOvertime = $earningTotal;
                    $taxablePayBeforeOvertime = $taxableEarningsTotal;
                    foreach ($overtimeRecords as $overtimeRecord) {
                        $overtimeRule = $overtimeEngine->ruleForDate($period, $overtimeRecord->overtime_type, $overtimeRecord->worked_on->toDateString());
                        $overtimeBase = match ($overtimeRule->calculation_basis) {
                            'basic' => $structure->basic_salary,
                            'gross' => $grossBeforeOvertime,
                            'taxable_pay' => $taxablePayBeforeOvertime,
                            default => throw ValidationException::withMessages([
                                'period' => 'The configured overtime rule has an unsupported calculation basis.',
                            ]),
                        };
                        $hourlyRate = bcdiv($overtimeBase, $overtimeRule->hours_per_period, 8);
                        $overtimeAmount = $overtimeEngine->amount(
                            $overtimeRule,
                            $structure->basic_salary,
                            $grossBeforeOvertime,
                            $taxablePayBeforeOvertime,
                            $overtimeRecord->hours,
                            $calculator
                        );
                        $usedOvertimeRules[$overtimeRule->id] = $overtimeEngine->snapshot($overtimeRule);
                        $this->createLine(
                            $entry,
                            'Overtime - '.ucfirst($overtimeRecord->overtime_type),
                            'earning',
                            'approved_overtime',
                            $overtimeAmount,
                            $overtimeRule->is_taxable,
                            false,
                            $overtimeBase,
                            null,
                            null,
                            null,
                            $overtimeRecord->hours,
                            $overtimeRule->multiplier,
                            'overtime',
                            $overtimeRecord->id
                        );
                        $overtimeRecord->update([
                            'payroll_period_id' => $period->id,
                            'rule_id' => $overtimeRule->id,
                            'hourly_rate' => $hourlyRate,
                            'multiplier' => $overtimeRule->multiplier,
                            'calculated_amount' => $overtimeAmount,
                            'status' => 'processed',
                        ]);
                        $earningTotal = $calculator->money(bcadd($earningTotal, $overtimeAmount, 4));
                        if ($overtimeRule->is_taxable) {
                            $taxableEarningsTotal = $calculator->money(bcadd($taxableEarningsTotal, $overtimeAmount, 4));
                        }
                        if ($overtimeRule->is_pensionable) {
                            $pensionableEarningsTotal = $calculator->money(bcadd($pensionableEarningsTotal, $overtimeAmount, 4));
                        }
                    }
                    $profile = $employee->payroll_profile ?? [];
                    if ($isTanzaniaPayroll) {
                        foreach (['tax_residency', 'pension_fund', 'nhif_enrolled', 'heslb_billed'] as $requiredField) {
                            if (! array_key_exists($requiredField, $profile)) {
                                throw ValidationException::withMessages([
                                    'period' => 'Complete the confirmed statutory profile for employee '.$employee->employee_no.' before processing payroll.',
                                ]);
                            }
                        }
                        if (! in_array($profile['tax_residency'], ['resident', 'non_resident'], true)
                            || ! in_array($profile['pension_fund'], ['nssf', 'psssf', 'none'], true)) {
                            throw ValidationException::withMessages([
                                'period' => 'The statutory profile for employee '.$employee->employee_no.' contains an invalid tax residency or pension fund.',
                            ]);
                        }
                    }
                    $automaticStatutoryNames = [];
                    if ($isTanzaniaPayroll && $profile['pension_fund'] !== 'none') {
                        $automaticStatutoryNames[] = strtolower($profile['pension_fund']);
                        $automaticStatutoryNames[] = strtolower($profile['pension_fund']).' employee contribution';
                    }
                    if ($isTanzaniaPayroll && $profile['nhif_enrolled']) {
                        $automaticStatutoryNames[] = 'nhif';
                        $automaticStatutoryNames[] = 'nhif employee contribution';
                    }
                    if ($isTanzaniaPayroll && $profile['heslb_billed']) {
                        $automaticStatutoryNames[] = 'heslb';
                        $automaticStatutoryNames[] = 'heslb loan repayment';
                    }
                    if ($isTanzaniaPayroll) {
                        $automaticStatutoryNames[] = 'paye';
                        $automaticStatutoryNames[] = 'wcf';
                        $automaticStatutoryNames[] = 'wcf employer contribution';
                        if ($sdlApplies) {
                            $automaticStatutoryNames[] = 'sdl';
                            $automaticStatutoryNames[] = 'sdl employer levy';
                        }
                    }
                    foreach ($structure->components as $component) {
                        if (in_array(strtolower(trim($component->name)), $automaticStatutoryNames, true)) {
                            throw ValidationException::withMessages(['period' => 'The salary structure already contains an automatically calculated statutory item ('.$component->name.'). Remove it to prevent a duplicate deduction.']);
                        }
                    }
                    foreach ($adjustments as $adjustment) {
                        if (in_array(strtolower(trim($adjustment->description)), $automaticStatutoryNames, true)) {
                            throw ValidationException::withMessages(['period' => 'A manual payroll adjustment duplicates the automatic statutory item '.$adjustment->description.'. Remove the adjustment and retry.']);
                        }
                    }
                    $employerContributionTotal = '0.00';
                    $pensionEmployeeContribution = '0.00';
                    $pensionRule = null;
                    if ($isTanzaniaPayroll && $profile['pension_fund'] !== 'none') {
                        $pensionRule = $ruleForCode(strtoupper($profile['pension_fund']));
                        $pensionBase = $statutoryEngine->contributionBase($pensionRule, $structure->basic_salary, $earningTotal, $taxableEarningsTotal, $pensionableEarningsTotal);
                        $pensionEmployeeContribution = $statutoryEngine->contribution($pensionRule, $pensionBase, 'employee', $calculator);
                        $pensionEmployerContribution = $statutoryEngine->contribution($pensionRule, $pensionBase, 'employer', $calculator);
                        if (bccomp($pensionEmployeeContribution, '0', 2) > 0) {
                            $this->createLine($entry, strtoupper($profile['pension_fund']).' employee contribution', 'deduction', 'configured_statutory_rate', $pensionEmployeeContribution, false, true, $pensionBase, $pensionRule->employee_rate, null);
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $pensionEmployeeContribution, 4));
                        }
                        if (bccomp($pensionEmployerContribution, '0', 2) > 0) {
                            $this->createLine($entry, strtoupper($profile['pension_fund']).' employer contribution', 'employer_contribution', 'configured_statutory_rate', $pensionEmployerContribution, false, true, $pensionBase, $pensionRule->employer_rate, null);
                            $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $pensionEmployerContribution, 4));
                        }
                    }
                    if ($isTanzaniaPayroll && $profile['nhif_enrolled']) {
                        $nhifRule = $ruleForCode('NHIF');
                        $nhifBase = $statutoryEngine->contributionBase($nhifRule, $structure->basic_salary, $earningTotal, $taxableEarningsTotal);
                        $nhifEmployeeContribution = $statutoryEngine->contribution($nhifRule, $nhifBase, 'employee', $calculator);
                        $nhifEmployerContribution = $statutoryEngine->contribution($nhifRule, $nhifBase, 'employer', $calculator);
                        $this->createLine($entry, 'NHIF employee contribution', 'deduction', 'configured_statutory_rate', $nhifEmployeeContribution, false, true, $nhifBase, $nhifRule->employee_rate, null);
                        $this->createLine($entry, 'NHIF employer contribution', 'employer_contribution', 'configured_statutory_rate', $nhifEmployerContribution, false, true, $nhifBase, $nhifRule->employer_rate, null);
                        $deductionTotal = $calculator->money(bcadd($deductionTotal, $nhifEmployeeContribution, 4));
                        $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $nhifEmployerContribution, 4));
                    }
                    if ($isTanzaniaPayroll && $profile['heslb_billed']) {
                        $heslbRule = $ruleForCode('HESLB');
                        $heslbBase = $statutoryEngine->contributionBase($heslbRule, $structure->basic_salary, $earningTotal, $taxableEarningsTotal);
                        $heslbContribution = $statutoryEngine->contribution($heslbRule, $heslbBase, 'employee', $calculator);
                        $this->createLine($entry, 'HESLB loan repayment', 'deduction', 'configured_statutory_rate', $heslbContribution, false, true, $heslbBase, $heslbRule->employee_rate, null);
                        $deductionTotal = $calculator->money(bcadd($deductionTotal, $heslbContribution, 4));
                    }
                    if ($isTanzaniaPayroll) {
                        $payeBase = $taxableEarningsTotal;
                        if ($pensionRule !== null && ($pensionRule->parameters['paye_deductible'] ?? false)) {
                            $payeBase = bccomp($taxableEarningsTotal, $pensionEmployeeContribution, 2) >= 0
                                ? bcsub($taxableEarningsTotal, $pensionEmployeeContribution, 2)
                                : '0.00';
                        }
                        $payeRule = $ruleForCode('PAYE');
                        try {
                            $paye = $calculator->progressiveTax(
                                $payeBase,
                                $period->frequency,
                                $payeRule->parameters ?? [],
                                $profile['tax_residency']
                            );
                        } catch (\InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['period' => 'The configured PAYE rule is incomplete or invalid: '.$exception->getMessage()]);
                        }
                        if (bccomp($paye, '0', 2) > 0) {
                            $this->createLine($entry, 'PAYE', 'deduction', 'progressive_tax_configured', $paye, false, true, $payeBase, null, null);
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $paye, 4));
                        }
                        $wcfRule = $ruleForCode('WCF');
                        $wcfAssessmentBase = $statutoryEngine->contributionBase($wcfRule, $structure->basic_salary, $earningTotal, $taxableEarningsTotal);
                        $wcfContribution = $statutoryEngine->contribution($wcfRule, $wcfAssessmentBase, 'employer', $calculator);
                        $this->createLine($entry, 'WCF employer contribution', 'employer_contribution', 'configured_statutory_rate', $wcfContribution, false, true, $wcfAssessmentBase, $wcfRule->employer_rate, null);
                        $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $wcfContribution, 4));
                        if ($sdlApplies) {
                            $sdlBase = $statutoryEngine->contributionBase($sdlRule, $structure->basic_salary, $earningTotal, $taxableEarningsTotal);
                            $sdlContribution = $statutoryEngine->contribution($sdlRule, $sdlBase, 'employer', $calculator);
                            $this->createLine($entry, 'SDL employer levy', 'employer_contribution', 'configured_statutory_rate', $sdlContribution, false, true, $sdlBase, $sdlRule->employer_rate, null);
                            $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $sdlContribution, 4));
                        }
                    }
                    $loans = EmployeePayrollLoan::query()
                        ->where('employee_id', $employee->id)
                        ->where('status', 'active')
                        ->whereDate('repayment_starts_on', '<=', $period->starts_on)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    foreach ($loans as $loan) {
                        if ($loan->currency !== $period->currency) {
                            throw ValidationException::withMessages([
                                'period' => 'An active loan or advance uses a different currency from this payroll period. Resolve the currency or process the matching payroll currency; no conversion is assumed.',
                            ]);
                        }
                        $outstandingBalance = $loan->remainingBalance();
                        if (bccomp($outstandingBalance, '0.00', 2) <= 0) {
                            continue;
                        }
                        $repaymentAmount = bccomp($loan->installment_amount, $outstandingBalance, 2) > 0
                            ? $outstandingBalance
                            : $loan->installment_amount;
                        $repayment = PayrollLoanRepayment::create([
                            'employee_payroll_loan_id' => $loan->id,
                            'payroll_period_id' => $period->id,
                            'payroll_entry_id' => $entry->id,
                            'amount' => $repaymentAmount,
                            'status' => 'scheduled',
                        ]);
                        $this->createLine(
                            $entry,
                            ucfirst($loan->loan_type).' repayment #'.$loan->id,
                            'deduction',
                            'scheduled_loan_repayment',
                            $repaymentAmount,
                            false,
                            false,
                            $outstandingBalance,
                            null,
                            null,
                            null,
                            null,
                            null,
                            'loan_repayment',
                            $repayment->id
                        );
                        $deductionTotal = $calculator->money(bcadd($deductionTotal, $repaymentAmount, 4));
                    }
                    $net = bcsub($earningTotal, $deductionTotal, 2);
                    if (bccomp($net, '0.00', 2) === -1) {
                        throw ValidationException::withMessages(['period' => 'The configured deductions exceed gross pay for employee '.$employee->employee_no.'. Review the draft adjustments and components.']);
                    }
                    $entry->update(['gross_amount' => $earningTotal, 'deductions_amount' => $deductionTotal, 'benefits_amount' => $benefitTotal, 'net_amount' => $net, 'employer_contributions_amount' => $employerContributionTotal]);
                    $totals['gross'] = $calculator->money(bcadd($totals['gross'], $earningTotal, 4));
                    $totals['deductions'] = $calculator->money(bcadd($totals['deductions'], $deductionTotal, 4));
                    $totals['net'] = $calculator->money(bcadd($totals['net'], $net, 4));
                    $totals['employer_contributions'] = $calculator->money(bcadd($totals['employer_contributions'], $employerContributionTotal, 4));
                }
            });
            if ($period->entries()->count() !== $employeeCount) {
                throw ValidationException::withMessages(['period' => 'Each active employee in payroll must have exactly one valid matching assignment for the whole period. Correct assignments and retry.']);
            }
            $period->update([
                'status' => 'processed', 'processed_by' => $request->user()->id, 'processed_at' => now(),
                'employee_count' => $period->entries()->count(), 'gross_total' => $totals['gross'],
                'deductions_total' => $totals['deductions'], 'net_total' => $totals['net'], 'employer_contributions_total' => $totals['employer_contributions'],
                'statutory_rules_snapshot' => $usedStatutoryRules,
                'overtime_rules_snapshot' => $usedOvertimeRules,
            ]);
            $this->audit($request, 'payroll.period_processed', $period, ['employee_count' => $period->employee_count, 'gross_total' => $period->gross_total, 'deductions_total' => $period->deductions_total, 'net_total' => $period->net_total]);
        });

        return back()->with('status', 'Payroll calculated from the configured structures and draft adjustments. Review the snapshot before approval.');
    }

    public function approve(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'hr_director', 'finance_officer'], true), 403);
        DB::transaction(function () use ($request, $payrollPeriod): void {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'processed') {
                throw ValidationException::withMessages(['period' => 'Only a processed period can be approved.']);
            }
            if ((int) $period->processed_by === (int) $request->user()->id) {
                throw ValidationException::withMessages(['period' => 'The payroll processor cannot approve their own payroll run.']);
            }
            $period->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->audit($request, 'payroll.period_approved', $period, ['processor_id' => $period->processed_by, 'approved_net_total' => $period->net_total]);
        });

        return back()->with('status', 'Payroll period approved. Payslips are now available to employees.');
    }

    public function markPaid(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        abort_unless($request->user()->role === 'system_admin' || $request->user()->role === 'finance_officer', 403);
        $data = $request->validate(['payment_reference' => ['required', 'string', 'max:180']]);
        DB::transaction(function () use ($request, $payrollPeriod, $data): void {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'approved') {
                throw ValidationException::withMessages(['period' => 'Only an approved payroll period can be marked paid.']);
            }
            if ((int) $period->processed_by === (int) $request->user()->id) {
                throw ValidationException::withMessages(['period' => 'The payroll processor cannot record payment for their own payroll run.']);
            }
            $period->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now(), 'payment_reference' => $data['payment_reference']]);
            PayrollLoanRepayment::where('payroll_period_id', $period->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'paid', 'paid_at' => now()]);
            $period->entries()->get()->each(function (PayrollEntry $entry): void {
                EmployeePayrollLoan::where('employee_id', $entry->employee_id)
                    ->where('status', 'active')
                    ->get()
                    ->each(function (EmployeePayrollLoan $loan): void {
                        if (bccomp($loan->remainingBalance(), '0.00', 2) === 0) {
                            $loan->update(['status' => 'completed']);
                        }
                    });
            });
            $this->audit($request, 'payroll.period_paid', $period, ['payment_reference' => $period->payment_reference, 'net_total' => $period->net_total]);
        });

        return back()->with('status', 'Payroll payment recorded.');
    }

    public function approvalQueue(Request $request): View
    {
        $overtimeRequests = PayrollOvertime::with('employee')->where('status', 'pending');
        if ($request->user()->role === 'hr_manager') {
            $overtimeRequests->whereHas('employee', fn (Builder $query) => $query->where('manager_id', $request->user()->id));
        }

        return view('payroll.approvals', [
            'overtimeRequests' => $overtimeRequests->orderBy('worked_on')->paginate(30, ['*'], 'overtime_page'),
            'loanRequests' => EmployeePayrollLoan::with('employee')->where('status', 'pending')->latest()->paginate(30, ['*'], 'loans_page'),
            'loansAwaitingDisbursement' => EmployeePayrollLoan::with('employee')->where('status', 'approved')->latest()->paginate(30, ['*'], 'disbursements_page'),
            'canReviewOvertime' => in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager', 'payroll_officer'], true),
            'canReviewLoans' => in_array($request->user()->role, ['system_admin', 'hr_director', 'finance_officer'], true),
            'canDisburseLoans' => in_array($request->user()->role, ['system_admin', 'finance_officer'], true),
        ]);
    }

    public function closePeriod(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['system_admin', 'finance_officer'], true), 403);
        DB::transaction(function () use ($request, $payrollPeriod): void {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'paid') {
                throw ValidationException::withMessages(['period' => 'Only a paid payroll period can be closed.']);
            }
            $period->update([
                'status' => 'closed',
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ]);
            $this->audit($request, 'payroll.period_closed', $period, [
                'period_key' => $period->period_key,
                'net_total' => $period->net_total,
            ]);
        });

        return back()->with('status', 'Payroll period closed. Its calculation and statutory snapshots are immutable.');
    }

    public function mine(Request $request): View
    {
        return view('payroll.mine', [
            'entries' => PayrollEntry::with('payrollPeriod')->where('employee_id', $request->user()->id)
                ->whereHas('payrollPeriod', fn (Builder $query) => $query->whereIn('status', ['approved', 'paid', 'closed']))
                ->latest()->paginate(15),
            'overtimeRequests' => PayrollOvertime::where('employee_id', $request->user()->id)->latest('worked_on')->paginate(10, ['*'], 'overtime_page'),
            'loans' => EmployeePayrollLoan::where('employee_id', $request->user()->id)->latest()->paginate(10, ['*'], 'loans_page'),
        ]);
    }

    public function payslip(Request $request, PayrollEntry $payrollEntry): View
    {
        abort_unless($payrollEntry->employee_id === $request->user()->id || in_array($request->user()->role, ['system_admin', 'hr_director', 'payroll_officer', 'finance_officer'], true), 404);
        abort_unless(in_array($payrollEntry->payrollPeriod->status, ['approved', 'paid', 'closed'], true), 404);

        return view('payroll.payslip', ['entry' => $payrollEntry->load(['payrollPeriod', 'lines'])]);
    }

    public function export(Request $request, PayrollPeriod $payrollPeriod): StreamedResponse
    {
        abort_unless(in_array($payrollPeriod->status, ['approved', 'paid', 'closed'], true), 404);
        $entries = $payrollPeriod->entries()->orderBy('employee_number_snapshot')->lazy(500);

        return response()->streamDownload(function () use ($entries, $payrollPeriod): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Employee number', 'Employee', 'Currency', 'Basic', 'Gross', 'Deductions', 'Benefits', 'Net pay', 'Employer contributions', 'Period']);
            foreach ($entries as $entry) {
                fputcsv($output, [
                    $this->safeCsvValue($entry->employee_number_snapshot),
                    $this->safeCsvValue($entry->employee_name_snapshot),
                    $entry->currency, $entry->basic_amount, $entry->gross_amount, $entry->deductions_amount,
                    $entry->benefits_amount, $entry->net_amount, $entry->employer_contributions_amount, $this->safeCsvValue($payrollPeriod->period_key),
                ]);
            }
            fclose($output);
        }, 'payroll-'.$payrollPeriod->period_key.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportStatutory(Request $request, PayrollPeriod $payrollPeriod, string $code): StreamedResponse
    {
        abort_unless(in_array(strtoupper($code), StatutoryPayrollEngine::CODES, true), 404);
        abort_unless(in_array($payrollPeriod->status, ['approved', 'paid', 'closed'], true), 404);

        $code = strtoupper($code);
        $lineName = match ($code) {
            'PAYE' => 'PAYE',
            'NSSF', 'PSSSF' => $code.'%',
            'NHIF' => 'NHIF%',
            'SDL' => 'SDL%',
            'WCF' => 'WCF%',
            'HESLB' => 'HESLB%',
        };
        $lines = PayrollEntryLine::query()
            ->join('payroll_entries', 'payroll_entries.id', '=', 'payroll_entry_lines.payroll_entry_id')
            ->where('payroll_entries.payroll_period_id', $payrollPeriod->id)
            ->where('payroll_entry_lines.is_statutory', true)
            ->where('payroll_entry_lines.name', 'like', $lineName)
            ->orderBy('payroll_entries.employee_number_snapshot')
            ->orderBy('payroll_entry_lines.name')
            ->get([
                'payroll_entries.employee_number_snapshot',
                'payroll_entries.employee_name_snapshot',
                'payroll_entry_lines.name',
                'payroll_entry_lines.line_type',
                'payroll_entry_lines.calculation_base',
                'payroll_entry_lines.rate_percent',
                'payroll_entry_lines.amount',
            ]);

        return response()->streamDownload(function () use ($lines, $payrollPeriod, $code): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Period', 'Code', 'Employee number', 'Employee', 'Contribution', 'Side', 'Calculation base', 'Rate percent', 'Amount', 'Currency']);
            foreach ($lines as $line) {
                fputcsv($output, [
                    $this->safeCsvValue($payrollPeriod->period_key),
                    $code,
                    $this->safeCsvValue($line->employee_number_snapshot),
                    $this->safeCsvValue($line->employee_name_snapshot),
                    $this->safeCsvValue($line->name),
                    $this->safeCsvValue($line->line_type),
                    $line->calculation_base,
                    $line->rate_percent,
                    $line->amount,
                    $payrollPeriod->currency,
                ]);
            }
            fclose($output);
        }, strtolower($code).'-'.$payrollPeriod->period_key.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportAnnualPreparation(Request $request, int $year): StreamedResponse
    {
        abort_unless($year >= 2000 && $year <= 2100, 404);
        $annualEmployees = [];
        $entries = PayrollEntry::with(['lines', 'payrollPeriod'])
            ->whereHas('payrollPeriod', fn (Builder $query) => $query
                ->whereYear('pay_on', $year)
                ->whereIn('status', ['paid', 'closed']))
            ->orderBy('id')
            ->lazyById(500);

        foreach ($entries as $entry) {
            $key = $entry->employee_number_snapshot.'|'.$entry->currency;
            $annualEmployees[$key] ??= [
                'employee_number' => $entry->employee_number_snapshot,
                'employee_name' => $entry->employee_name_snapshot,
                'tax_identifier' => $entry->tax_identifier_snapshot === null ? '' : Crypt::decryptString($entry->tax_identifier_snapshot),
                'tax_residency' => $entry->tax_residency_snapshot ?? '',
                'currency' => $entry->currency,
                'gross_pay' => '0.00',
                'taxable_pay' => '0.00',
                'paye' => '0.00',
                'pension_contributions' => '0.00',
                'other_statutory_deductions' => '0.00',
                'net_paid' => '0.00',
            ];
            $row = &$annualEmployees[$key];
            $row['gross_pay'] = bcadd($row['gross_pay'], $entry->gross_amount, 2);
            $row['net_paid'] = bcadd($row['net_paid'], $entry->net_amount, 2);
            foreach ($entry->lines as $line) {
                if ($line->is_taxable && in_array($line->line_type, ['earning', 'benefit'], true)) {
                    $row['taxable_pay'] = bcadd($row['taxable_pay'], $line->amount, 2);
                }
                if ($line->line_type === 'deduction' && $line->name === 'PAYE') {
                    $row['paye'] = bcadd($row['paye'], $line->amount, 2);
                }
                if ($line->line_type === 'deduction' && $line->is_statutory) {
                    if (str_starts_with(strtoupper($line->name), 'NSSF ') || str_starts_with(strtoupper($line->name), 'PSSSF ')) {
                        $row['pension_contributions'] = bcadd($row['pension_contributions'], $line->amount, 2);
                    } else {
                        $row['other_statutory_deductions'] = bcadd($row['other_statutory_deductions'], $line->amount, 2);
                    }
                }
            }
            unset($row);
        }

        return response()->streamDownload(function () use ($annualEmployees, $year): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['PREPARATION DATA ONLY - NOT AN OFFICIAL TRA P9/P10 FILING', $year]);
            fputcsv($output, ['Employee number', 'Employee', 'Tax identifier', 'Tax residency', 'Currency', 'Gross pay', 'Taxable pay', 'PAYE withheld', 'Employee pension contributions', 'Other statutory employee deductions', 'Net paid']);
            foreach ($annualEmployees as $row) {
                fputcsv($output, [
                    $this->safeCsvValue($row['employee_number']),
                    $this->safeCsvValue($row['employee_name']),
                    $this->safeCsvValue($row['tax_identifier']),
                    $this->safeCsvValue($row['tax_residency']),
                    $row['currency'],
                    $row['gross_pay'],
                    $row['taxable_pay'],
                    $row['paye'],
                    $row['pension_contributions'],
                    $row['other_statutory_deductions'],
                    $row['net_paid'],
                ]);
            }
            fclose($output);
        }, 'annual-payroll-preparation-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportDepartmentSummary(Request $request, int $year): StreamedResponse
    {
        abort_unless($year >= 2000 && $year <= 2100, 404);
        $departments = [];
        $entries = PayrollEntry::whereHas('payrollPeriod', fn (Builder $query) => $query
            ->whereYear('pay_on', $year)
            ->whereIn('status', ['paid', 'closed']))
            ->orderBy('id')
            ->lazyById(500);

        foreach ($entries as $entry) {
            $department = $entry->department_snapshot ?: 'Unassigned';
            $key = $department.'|'.$entry->currency;
            $departments[$key] ??= [
                'department' => $department,
                'currency' => $entry->currency,
                'employees' => [],
                'gross_pay' => '0.00',
                'deductions' => '0.00',
                'net_paid' => '0.00',
                'employer_contributions' => '0.00',
            ];
            $departments[$key]['employees'][$entry->employee_number_snapshot] = true;
            $departments[$key]['gross_pay'] = bcadd($departments[$key]['gross_pay'], $entry->gross_amount, 2);
            $departments[$key]['deductions'] = bcadd($departments[$key]['deductions'], $entry->deductions_amount, 2);
            $departments[$key]['net_paid'] = bcadd($departments[$key]['net_paid'], $entry->net_amount, 2);
            $departments[$key]['employer_contributions'] = bcadd($departments[$key]['employer_contributions'], $entry->employer_contributions_amount, 2);
        }

        return response()->streamDownload(function () use ($departments, $year): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Department payroll summary', $year, 'Paid and closed payroll periods only']);
            fputcsv($output, ['Department', 'Currency', 'Distinct employees', 'Gross pay', 'Employee deductions', 'Net paid', 'Employer contributions']);
            foreach ($departments as $row) {
                fputcsv($output, [
                    $this->safeCsvValue($row['department']),
                    $row['currency'],
                    count($row['employees']),
                    $row['gross_pay'],
                    $row['deductions'],
                    $row['net_paid'],
                    $row['employer_contributions'],
                ]);
            }
            fclose($output);
        }, 'department-payroll-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportBankPayments(Request $request, PayrollPeriod $payrollPeriod): StreamedResponse
    {
        abort_unless(in_array($payrollPeriod->status, ['approved', 'paid', 'closed'], true), 404);

        $missingBankDetails = $payrollPeriod->entries()
            ->whereHas('employee', fn (Builder $query) => $query
                ->whereNull('bank_name')->orWhere('bank_name', '')
                ->orWhereNull('bank_account_number')->orWhere('bank_account_number', ''))
            ->exists();
        if ($missingBankDetails) {
            throw ValidationException::withMessages([
                'period' => 'Complete bank name and account number for every employee before exporting payment data.',
            ]);
        }

        $entries = PayrollEntry::with('employee')
            ->where('payroll_period_id', $payrollPeriod->id)
            ->orderBy('employee_number_snapshot')
            ->lazy(500);

        return response()->streamDownload(function () use ($entries, $payrollPeriod): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Employee number', 'Employee', 'Bank', 'Branch', 'Account number', 'Net pay', 'Currency', 'Payment date', 'Period']);
            foreach ($entries as $entry) {
                fputcsv($output, [
                    $this->safeCsvValue($entry->employee_number_snapshot),
                    $this->safeCsvValue($entry->employee_name_snapshot),
                    $this->safeCsvValue($entry->employee?->bank_name),
                    $this->safeCsvValue($entry->employee?->bank_branch),
                    $this->safeCsvValue($entry->employee?->bank_account_number),
                    $entry->net_amount,
                    $payrollPeriod->currency,
                    $payrollPeriod->pay_on->toDateString(),
                    $this->safeCsvValue($payrollPeriod->period_key),
                ]);
            }
            fclose($output);
        }, 'bank-payments-'.$payrollPeriod->period_key.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{ready: bool, blockers: array<int, string>, warnings: array<int, string>}
     */
    private function payrollReadiness(PayrollPeriod $period, StatutoryPayrollEngine $statutoryEngine): array
    {
        $employees = User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')
            ->where(fn (Builder $query) => $query->whereNull('payroll_profile->payroll_status')->orWhere('payroll_profile->payroll_status', 'active'));
        $employeeCount = (clone $employees)->count();
        $matchingAssignmentQuery = fn (Builder $query) => $query
            ->whereDate('effective_from', '<=', $period->starts_on)
            ->where(fn (Builder $activeQuery) => $activeQuery->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->ends_on))
            ->whereHas('salaryStructure', fn (Builder $structureQuery) => $structureQuery
                ->where('country_code', $period->country_code)
                ->where('currency', $period->currency)
                ->where('frequency', $period->frequency)
                ->where('is_active', true));
        $assignedEmployeeCount = (clone $employees)->whereHas('salaryAssignments', $matchingAssignmentQuery)->count();
        $duplicateAssignmentEmployeeCount = EmployeeSalaryAssignment::query()
            ->whereDate('effective_from', '<=', $period->ends_on)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->starts_on))
            ->whereHas('employee', fn (Builder $query) => $query->where('role', 'employee')->where('is_active', true)
                ->whereNotNull('employee_no')
                ->where(fn (Builder $statusQuery) => $statusQuery->whereNull('payroll_profile->payroll_status')->orWhere('payroll_profile->payroll_status', 'active')))
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $blockers = [];
        $warnings = [];

        if ($employeeCount === 0) {
            $blockers[] = 'No employees are marked active for payroll.';
        } elseif ($assignedEmployeeCount !== $employeeCount) {
            $blockers[] = ($employeeCount - $assignedEmployeeCount).' active payroll employee(s) need exactly one matching salary assignment that covers the entire period.';
        }
        if ($duplicateAssignmentEmployeeCount > 0) {
            $blockers[] = $duplicateAssignmentEmployeeCount.' employee(s) have overlapping salary assignments for this period; resolve them before processing.';
        }

        if ($period->country_code === 'TZ' && $period->currency === 'TZS') {
            $profileIncompleteCount = (clone $employees)->whereHas('salaryAssignments', $matchingAssignmentQuery)
                ->where(function (Builder $query): void {
                    foreach (['tax_residency', 'pension_fund', 'nhif_enrolled', 'heslb_billed'] as $field) {
                        $query->orWhereNull('payroll_profile->'.$field);
                    }
                })->count();
            if ($profileIncompleteCount > 0) {
                $blockers[] = $profileIncompleteCount.' assigned employee(s) need complete residency, pension, NHIF, and HESLB profile confirmations.';
            }

            $jurisdiction = SystemSetting::value('tanzania_payroll_jurisdiction', 'mainland');
            foreach (StatutoryPayrollEngine::CODES as $code) {
                try {
                    $statutoryEngine->ruleForPeriod($code, $jurisdiction, $period);
                } catch (ValidationException $exception) {
                    $blockers[] = $code.': '.($exception->errors()['period'][0] ?? 'A valid rule covering the entire period is required.');
                }
            }
        }

        $employeesMissingBankDetails = (clone $employees)->whereHas('salaryAssignments', $matchingAssignmentQuery)
            ->where(fn (Builder $query) => $query
                ->whereNull('bank_name')->orWhere('bank_name', '')
                ->orWhereNull('bank_account_number')->orWhere('bank_account_number', ''))
            ->count();
        if ($employeesMissingBankDetails > 0) {
            $warnings[] = $employeesMissingBankDetails.' assigned employee(s) need bank details before generic bank export.';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function assignmentForPeriod(User $employee, PayrollPeriod $period): ?EmployeeSalaryAssignment
    {
        $assignments = EmployeeSalaryAssignment::with('salaryStructure')->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $period->ends_on)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->starts_on))
            ->orderByDesc('effective_from')->lockForUpdate()->get();
        if ($assignments->count() !== 1) {
            return null;
        }
        $assignment = $assignments->first();
        if ($assignment->effective_from->gt($period->starts_on) || ($assignment->effective_to !== null && $assignment->effective_to->lt($period->ends_on))) {
            return null;
        }

        return $assignment;
    }

    private function createLine(
        PayrollEntry $entry,
        string $name,
        string $lineType,
        ?string $method,
        string $amount,
        bool $taxable,
        bool $statutory,
        ?string $base,
        ?string $rate,
        ?int $componentId,
        ?int $adjustmentId = null,
        ?string $calculationHours = null,
        ?string $calculationMultiplier = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $componentCode = null
    ): void {
        PayrollEntryLine::create([
            'payroll_entry_id' => $entry->id,
            'salary_structure_component_id' => $componentId,
            'payroll_adjustment_id' => $adjustmentId,
            'name' => $name,
            'line_type' => $lineType,
            'calculation_method' => $method,
            'calculation_base' => $base,
            'rate_percent' => $rate,
            'amount' => $amount,
            'is_taxable' => $taxable,
            'is_statutory' => $statutory,
            'calculation_hours' => $calculationHours,
            'calculation_multiplier' => $calculationMultiplier,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'component_code_snapshot' => $componentCode,
        ]);
    }

    private function authorizeStructureManagement(User $user): void
    {
        abort_unless(in_array($user->role, ['system_admin', 'hr_director', 'payroll_officer'], true), 403);
    }

    private function authorizePayrollOperator(User $user): void
    {
        abort_unless(in_array($user->role, ['system_admin', 'hr_director', 'payroll_officer'], true), 403);
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

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+@\-]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  array<string, mixed>|null  $parameters
     */
    private function validatePayeParameters(?array $parameters): void
    {
        $bands = $parameters['bands'] ?? null;
        $nonResidentRate = $parameters['non_resident_rate'] ?? null;
        if (! is_array($bands) || $bands === [] || ! is_numeric($nonResidentRate) || (float) $nonResidentRate < 0 || (float) $nonResidentRate > 100) {
            throw ValidationException::withMessages([
                'parameters' => 'PAYE parameters must include ordered annual bands and a verified non-resident rate.',
            ]);
        }

        $previousLimit = 0.0;
        foreach ($bands as $index => $band) {
            if (! is_array($band) || ! isset($band['rate']) || ! is_numeric($band['rate']) || (float) $band['rate'] < 0 || (float) $band['rate'] > 100) {
                throw ValidationException::withMessages(['parameters' => 'Every PAYE band must include a numeric rate from 0 to 100.']);
            }

            $limit = $band['limit'] ?? null;
            if ($limit === null) {
                if ($index !== array_key_last($bands)) {
                    throw ValidationException::withMessages(['parameters' => 'Only the last annual PAYE band may have a null limit.']);
                }

                continue;
            }

            if (! is_numeric($limit) || (float) $limit <= $previousLimit) {
                throw ValidationException::withMessages(['parameters' => 'Annual PAYE band limits must be numeric and strictly increasing.']);
            }

            $previousLimit = (float) $limit;
        }

        if (($bands[array_key_last($bands)]['limit'] ?? null) !== null) {
            throw ValidationException::withMessages(['parameters' => 'The last annual PAYE band must have a null limit.']);
        }
    }
}
