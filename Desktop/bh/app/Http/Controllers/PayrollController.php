<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSalaryAssignment;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\SalaryStructure;
use App\Models\SalaryStructureComponent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Payroll\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')->orderBy('name')->paginate(50, ['id', 'name', 'employee_no', 'payroll_profile'], 'employees_page')->withQueryString(),
            'activeStructures' => SalaryStructure::where('is_active', true)->orderBy('name')->get(),
            'defaultCountryCode' => SystemSetting::value('default_country_code', 'TZ'),
            'defaultCurrency' => SystemSetting::value('default_currency', 'TZS'),
            'tanzaniaPayrollJurisdiction' => SystemSetting::value('tanzania_payroll_jurisdiction', 'mainland'),
        ]);
    }

    public function updateStatutoryProfile(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->role === 'employee' && $employee->is_active, 404);
        $data = $request->validate([
            'tax_residency' => ['required', Rule::in(['resident', 'non_resident'])],
            'pension_fund' => ['required', Rule::in(['nssf', 'psssf', 'none'])],
            'pension_employee_rate' => ['required', 'numeric', 'between:0,10'],
            'pension_employer_rate' => ['required', 'numeric', 'between:0,20'],
            'nhif_enrolled' => ['sometimes', 'boolean'],
            'heslb_billed' => ['sometimes', 'boolean'],
        ]);
        if ($data['pension_fund'] === 'psssf' && (float) $data['pension_employee_rate'] > 5) {
            throw ValidationException::withMessages(['pension_employee_rate' => 'PSSSF employee contributions cannot exceed 5%.']);
        }
        if ((float) $data['pension_employee_rate'] + (float) $data['pension_employer_rate'] > 20) {
            throw ValidationException::withMessages(['pension_employer_rate' => 'Employee and employer pension contributions cannot exceed a combined 20%.']);
        }
        if ($data['pension_fund'] === 'none') {
            $data['pension_employee_rate'] = 0;
            $data['pension_employer_rate'] = 0;
        }
        $profile = [
            'tax_residency' => $data['tax_residency'],
            'pension_fund' => $data['pension_fund'],
            'pension_employee_rate' => number_format((float) $data['pension_employee_rate'], 4, '.', ''),
            'pension_employer_rate' => number_format((float) $data['pension_employer_rate'], 4, '.', ''),
            'nhif_enrolled' => $request->boolean('nhif_enrolled'),
            'heslb_billed' => $request->boolean('heslb_billed'),
        ];

        DB::transaction(function () use ($request, $employee, $profile): void {
            $lockedEmployee = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $before = $lockedEmployee->payroll_profile ?? [];
            $lockedEmployee->payroll_profile = $profile;
            $lockedEmployee->save();
            $this->audit($request, 'payroll.statutory_profile_updated', $lockedEmployee, ['before' => $before, 'after' => $profile]);
        });

        return back()->with('status', 'Employee statutory payroll profile saved. New draft payroll periods will use the saved profile.');
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
            'name' => ['required', 'string', 'max:120'],
            'component_type' => ['required', Rule::in(['earning', 'benefit', 'deduction'])],
            'calculation_method' => ['required', Rule::in(['fixed', 'percent_basic', 'percent_gross'])],
            'value' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/', 'min:0'],
            'is_taxable' => ['required', 'boolean'],
            'is_statutory' => ['required', 'boolean'],
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
        ]);

        DB::transaction(function () use ($request, $data): void {
            $employee = User::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            $structure = SalaryStructure::whereKey($data['salary_structure_id'])->where('is_active', true)->firstOrFail();
            $overlappingAssignments = EmployeeSalaryAssignment::where('employee_id', $employee->id)
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
                ->lockForUpdate()->get();
            foreach ($overlappingAssignments as $assignment) {
                if ($assignment->effective_from->gte($data['effective_from'])) {
                    throw ValidationException::withMessages(['effective_from' => 'A future salary assignment already exists. Review it before adding a new effective date.']);
                }
                $assignment->update(['effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()]);
            }
            $assignment = EmployeeSalaryAssignment::create([...$data, 'assigned_by' => $request->user()->id]);
            $this->audit($request, 'payroll.salary_assigned', $assignment, ['employee_id' => $employee->id, 'salary_structure_id' => $structure->id, 'effective_from' => $assignment->effective_from->toDateString()]);
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

    public function showPeriod(Request $request, PayrollPeriod $payrollPeriod): View
    {
        return view('payroll.period', [
            'period' => $payrollPeriod->load(['processor', 'approver', 'payer']),
            'entries' => PayrollEntry::with('lines')->where('payroll_period_id', $payrollPeriod->id)->orderBy('employee_number_snapshot')->paginate(50)->withQueryString(),
            'adjustments' => PayrollAdjustment::with('employee')->where('payroll_period_id', $payrollPeriod->id)->latest()->paginate(50, ['*'], 'adjustments_page')->withQueryString(),
            'employees' => User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')
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
            'category' => ['required', Rule::in(['bonus', 'overtime', 'loan', 'advance', 'benefit', 'deduction'])],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'gt:0'],
            'is_taxable' => ['required', 'boolean'],
        ]);
        $employee = User::whereKey($data['employee_id'])->where('is_active', true)->firstOrFail();
        $lineType = in_array($data['category'], ['loan', 'advance', 'deduction'], true) ? 'deduction' : ($data['category'] === 'benefit' ? 'benefit' : 'earning');
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
            ]);
            $this->audit($request, 'payroll.adjustment_created', $adjustment, ['period_id' => $period->id, 'employee_id' => $employee->id, 'category' => $adjustment->category, 'amount' => $adjustment->amount]);

            return $adjustment;
        });

        return back()->with('status', 'Payroll adjustment added to the draft.');
    }

    public function process(Request $request, PayrollPeriod $payrollPeriod, PayrollCalculator $calculator): RedirectResponse
    {
        $this->authorizePayrollOperator($request->user());
        DB::transaction(function () use ($request, $payrollPeriod, $calculator): void {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'draft') {
                throw ValidationException::withMessages(['period' => 'Only a draft period can be processed.']);
            }
            $employees = User::where('role', 'employee')->where('is_active', true)->whereNotNull('employee_no')
                ->whereHas('salaryAssignments', fn (Builder $query) => $query->whereDate('effective_from', '<=', $period->ends_on)
                    ->where(fn (Builder $activeQuery) => $activeQuery->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->starts_on))
                    ->whereHas('salaryStructure', fn (Builder $structureQuery) => $structureQuery->where('country_code', $period->country_code)->where('currency', $period->currency)->where('frequency', $period->frequency)))
                ->orderBy('id')->lockForUpdate();
            $employeeCount = (clone $employees)->count();
            if ($employeeCount === 0) {
                throw ValidationException::withMessages(['period' => 'No active employees have a salary assignment for this period.']);
            }
            $isTanzaniaPayroll = $period->country_code === 'TZ' && $period->currency === 'TZS';
            $isMainlandPayroll = $isTanzaniaPayroll && SystemSetting::value('tanzania_payroll_jurisdiction', 'mainland') === 'mainland';
            $sdlApplies = $isMainlandPayroll && User::where('role', 'employee')->where('is_active', true)->count() >= 10 && SystemSetting::value('sdl_exempt', '0') !== '1';
            $totals = ['gross' => '0.00', 'deductions' => '0.00', 'net' => '0.00', 'employer_contributions' => '0.00'];
            $employees->chunkById(100, function ($employeeBatch) use ($period, $calculator, $isTanzaniaPayroll, $isMainlandPayroll, $sdlApplies, &$totals): void {
                foreach ($employeeBatch as $employee) {
                    $assignment = $this->assignmentForPeriod($employee, $period);
                    if ($assignment === null) {
                        continue;
                    }
                    $structure = SalaryStructure::whereKey($assignment->salary_structure_id)->lockForUpdate()->firstOrFail();
                    $structure->setRelation('components', $structure->components()->where('is_active', true)->lockForUpdate()->get());
                    if ($structure->country_code !== $period->country_code || $structure->currency !== $period->currency || $structure->frequency !== $period->frequency) {
                        throw ValidationException::withMessages(['period' => 'Every employee must have a salary structure matching the period country, currency, and frequency.']);
                    }
                    $adjustments = $period->adjustments()->where('employee_id', $employee->id)->get();
                    $earningTotal = '0.00';
                    $deductionTotal = '0.00';
                    $benefitTotal = '0.00';
                    $taxableEarningsTotal = '0.00';
                    $wcfBase = $calculator->money($structure->basic_salary);
                    $entry = PayrollEntry::create([
                        'payroll_period_id' => $period->id,
                        'employee_id' => $employee->id,
                        'salary_structure_id' => $structure->id,
                        'employee_number_snapshot' => $employee->employee_no,
                        'employee_name_snapshot' => $employee->name,
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
                            'percent_gross' => bcadd($structure->basic_salary, $earningTotal, 4),
                            default => null,
                        };
                        $amount = $base === null ? $calculator->money($component->value) : $calculator->percent($base, $component->value);
                        $this->createLine($entry, $component->name, $component->component_type, $component->calculation_method, $amount, $component->is_taxable, $component->is_statutory, $base, $component->calculation_method === 'fixed' ? null : $component->value, $component->id);
                        if ($component->component_type === 'deduction') {
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $amount, 4));
                        } else {
                            $earningTotal = $calculator->money(bcadd($earningTotal, $amount, 4));
                            if ($component->is_taxable) {
                                $taxableEarningsTotal = $calculator->money(bcadd($taxableEarningsTotal, $amount, 4));
                            }
                            if ($component->component_type === 'earning' && $component->calculation_method === 'fixed') {
                                $wcfBase = $calculator->money(bcadd($wcfBase, $amount, 4));
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
                            if ($adjustment->line_type === 'benefit') {
                                $benefitTotal = $calculator->money(bcadd($benefitTotal, $adjustment->amount, 4));
                            }
                        }
                    }
                    $profile = array_merge([
                        'tax_residency' => 'resident', 'pension_fund' => 'nssf', 'pension_employee_rate' => '10.0000',
                        'pension_employer_rate' => '10.0000', 'nhif_enrolled' => true, 'heslb_billed' => false,
                    ], $employee->payroll_profile ?? []);
                    $automaticStatutoryNames = [];
                    if ($isMainlandPayroll && $profile['pension_fund'] !== 'none') {
                        $automaticStatutoryNames[] = strtolower($profile['pension_fund']);
                        $automaticStatutoryNames[] = strtolower($profile['pension_fund']).' employee contribution';
                    }
                    if ($isMainlandPayroll && $profile['nhif_enrolled']) {
                        $automaticStatutoryNames[] = 'nhif';
                        $automaticStatutoryNames[] = 'nhif employee contribution';
                    }
                    if ($isTanzaniaPayroll && $profile['heslb_billed']) {
                        $automaticStatutoryNames[] = 'heslb';
                        $automaticStatutoryNames[] = 'heslb loan repayment';
                    }
                    if ($isMainlandPayroll) {
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
                    $pensionBase = $calculator->money(bcsub($earningTotal, $benefitTotal, 2));
                    if ($isMainlandPayroll && $profile['pension_fund'] !== 'none') {
                        $pensionEmployeeContribution = $calculator->percent($pensionBase, (string) $profile['pension_employee_rate']);
                        $pensionEmployerContribution = $calculator->percent($pensionBase, (string) $profile['pension_employer_rate']);
                        if (bccomp($pensionEmployeeContribution, '0', 2) > 0) {
                            $this->createLine($entry, strtoupper($profile['pension_fund']).' employee contribution', 'deduction', 'percent_gross', $pensionEmployeeContribution, false, true, $pensionBase, (string) $profile['pension_employee_rate'], null);
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $pensionEmployeeContribution, 4));
                        }
                        if (bccomp($pensionEmployerContribution, '0', 2) > 0) {
                            $this->createLine($entry, strtoupper($profile['pension_fund']).' employer contribution', 'employer_contribution', 'percent_gross', $pensionEmployerContribution, false, true, $pensionBase, (string) $profile['pension_employer_rate'], null);
                            $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $pensionEmployerContribution, 4));
                        }
                    }
                    if ($isMainlandPayroll && $profile['nhif_enrolled']) {
                        $nhifEmployeeContribution = $calculator->percent($structure->basic_salary, '3');
                        $nhifEmployerContribution = $calculator->percent($structure->basic_salary, '3');
                        $this->createLine($entry, 'NHIF employee contribution', 'deduction', 'percent_basic', $nhifEmployeeContribution, false, true, $structure->basic_salary, '3', null);
                        $this->createLine($entry, 'NHIF employer contribution', 'employer_contribution', 'percent_basic', $nhifEmployerContribution, false, true, $structure->basic_salary, '3', null);
                        $deductionTotal = $calculator->money(bcadd($deductionTotal, $nhifEmployeeContribution, 4));
                        $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $nhifEmployerContribution, 4));
                    }
                    if ($isTanzaniaPayroll && $profile['heslb_billed']) {
                        $heslbContribution = $calculator->percent($structure->basic_salary, '15');
                        $this->createLine($entry, 'HESLB loan repayment', 'deduction', 'percent_basic', $heslbContribution, false, true, $structure->basic_salary, '15', null);
                        $deductionTotal = $calculator->money(bcadd($deductionTotal, $heslbContribution, 4));
                    }
                    if ($isMainlandPayroll) {
                        $payeBase = bccomp($taxableEarningsTotal, $pensionEmployeeContribution, 2) >= 0
                            ? bcsub($taxableEarningsTotal, $pensionEmployeeContribution, 2)
                            : '0.00';
                        $paye = $calculator->tanzaniaMainlandPaye($payeBase, $period->frequency, $profile['tax_residency']);
                        if (bccomp($paye, '0', 2) > 0) {
                            $this->createLine($entry, 'PAYE', 'deduction', 'tanzania_paye', $paye, false, true, $payeBase, null, null);
                            $deductionTotal = $calculator->money(bcadd($deductionTotal, $paye, 4));
                        }
                        $wcfContribution = $calculator->percent($wcfBase, '0.5');
                        $this->createLine($entry, 'WCF employer contribution', 'employer_contribution', 'percent_gross', $wcfContribution, false, true, $wcfBase, '0.5', null);
                        $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $wcfContribution, 4));
                        if ($sdlApplies) {
                            $sdlBase = $calculator->money(bcsub($earningTotal, $benefitTotal, 2));
                            $sdlContribution = $calculator->percent($sdlBase, '3.5');
                            $this->createLine($entry, 'SDL employer levy', 'employer_contribution', 'percent_gross', $sdlContribution, false, true, $sdlBase, '3.5', null);
                            $employerContributionTotal = $calculator->money(bcadd($employerContributionTotal, $sdlContribution, 4));
                        }
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
            $this->audit($request, 'payroll.period_paid', $period, ['payment_reference' => $period->payment_reference, 'net_total' => $period->net_total]);
        });

        return back()->with('status', 'Payroll payment recorded.');
    }

    public function mine(Request $request): View
    {
        return view('payroll.mine', [
            'entries' => PayrollEntry::with('payrollPeriod')->where('employee_id', $request->user()->id)
                ->whereHas('payrollPeriod', fn (Builder $query) => $query->whereIn('status', ['approved', 'paid']))
                ->latest()->paginate(15),
        ]);
    }

    public function payslip(Request $request, PayrollEntry $payrollEntry): View
    {
        abort_unless($payrollEntry->employee_id === $request->user()->id || in_array($request->user()->role, ['system_admin', 'hr_director', 'payroll_officer', 'finance_officer'], true), 404);
        abort_unless(in_array($payrollEntry->payrollPeriod->status, ['approved', 'paid'], true), 404);

        return view('payroll.payslip', ['entry' => $payrollEntry->load(['payrollPeriod', 'lines'])]);
    }

    public function export(Request $request, PayrollPeriod $payrollPeriod): StreamedResponse
    {
        abort_unless(in_array($payrollPeriod->status, ['approved', 'paid'], true), 404);
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

    private function createLine(PayrollEntry $entry, string $name, string $lineType, ?string $method, string $amount, bool $taxable, bool $statutory, ?string $base, ?string $rate, ?int $componentId, ?int $adjustmentId = null): void
    {
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
}
