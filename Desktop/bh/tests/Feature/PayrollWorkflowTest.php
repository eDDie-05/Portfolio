<?php

namespace Tests\Feature;

use App\Models\EmployeePayrollLoan;
use App\Models\EmployeeSalaryAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollLoanRepayment;
use App\Models\PayrollOvertime;
use App\Models\PayrollPeriod;
use App\Models\PayrollStatutoryRule;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Payroll\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_is_calculated_from_configured_components_approved_separately_and_exposed_as_a_payslip(): void
    {
        $payrollOfficer = $this->user('payroll.officer@example.test', 'payroll_officer');
        $financeOfficer = $this->user('payroll.finance@example.test', 'finance_officer');
        $administrator = $this->user('payroll.admin@example.test', 'system_admin');
        $employee = $this->user('payroll.employee@example.test', 'employee', 'PAY-101');
        $employee->forceFill([
            'bank_name' => 'Fixture Bank',
            'bank_branch' => 'Test Branch',
            'bank_account_number' => '1234567890',
            'tax_identifier' => 'TIN-ANNUAL-101',
        ])->save();
        $storedAccount = DB::table('users')->where('id', $employee->id)->value('bank_account_number');
        $this->assertNotSame('1234567890', $storedAccount);
        $this->assertSame('1234567890', $employee->fresh()->bank_account_number);
        $structure = $this->structure($payrollOfficer, 'Monthly Tanzania', '500000.00');
        $structure->components()->create([
            'name' => 'Transport allowance',
            'component_type' => 'earning',
            'calculation_method' => 'fixed',
            'value' => '25000.0000',
            'is_taxable' => true,
            'is_statutory' => false,
            'is_active' => true,
        ]);
        $structure->components()->create([
            'name' => 'Employer-configured deduction',
            'component_type' => 'deduction',
            'calculation_method' => 'percent_basic',
            'value' => '5.0000',
            'is_taxable' => false,
            'is_statutory' => true,
            'is_active' => true,
        ]);
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ]);
        $period = $this->period($payrollOfficer);

        $this->actingAs($payrollOfficer)->get('/payroll')->assertOk()->assertSee('Monthly Tanzania');
        $this->post('/payroll/periods/'.$period->id.'/adjustments', [
            'employee_id' => $employee->id,
            'category' => 'bonus',
            'description' => 'Approved performance bonus',
            'amount' => '10000.00',
            'is_taxable' => '1',
        ])->assertRedirect();
        $this->assertSame('10000.00', PayrollAdjustment::firstOrFail()->amount);
        $this->post('/payroll/periods/'.$period->id.'/process')->assertRedirect();

        $period->refresh();
        $entry = PayrollEntry::firstOrFail();
        $this->assertSame('processed', $period->status);
        $this->assertSame('535000.00', $entry->gross_amount);
        $this->assertSame('105075.00', $entry->deductions_amount);
        $this->assertSame('429925.00', $entry->net_amount);
        $this->assertSame(10, $entry->lines()->count());
        $this->assertSame('53500.00', $entry->lines()->where('name', 'NSSF employee contribution')->value('amount'));
        $this->assertSame('15000.00', $entry->lines()->where('name', 'NHIF employee contribution')->value('amount'));
        $this->assertSame('11575.00', $entry->lines()->where('name', 'PAYE')->value('amount'));
        $this->assertSame(7, count($period->fresh()->statutory_rules_snapshot));
        $this->assertDatabaseHas('payroll_statutory_rules', ['code' => 'PAYE', 'version' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.period_processed', 'subject_id' => $period->id]);

        $this->post('/payroll/periods/'.$period->id.'/approve')->assertForbidden();
        $this->actingAs($financeOfficer)->get('/payroll/periods/'.$period->id)->assertOk()->assertSee('535,000.00');
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $period->fresh()->status);
        $this->actingAs($employee)->get('/my-payroll')->assertOk()->assertSee('2026-11');
        $this->get('/my-payroll/payslips/'.$entry->id)->assertOk()->assertSee('Approved performance bonus');
        $this->get('/payroll/payslips/'.$entry->id)->assertForbidden();
        $this->actingAs($financeOfficer)->get('/payroll/periods/'.$period->id.'/export')->assertDownload('payroll-2026-11.csv');
        $this->get(route('payroll.periods.statutory.export', [$period, 'PAYE']))->assertDownload('paye-2026-11.csv');
        $this->actingAs($administrator)->post('/payroll/periods/'.$period->id.'/paid', ['payment_reference' => 'BANK-BATCH-123'])->assertRedirect();
        $this->assertSame('paid', $period->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.period_paid', 'subject_id' => $period->id]);
        $paymentExport = $this->actingAs($financeOfficer)->get(route('payroll.periods.bank-payments.export', $period));
        $paymentExport->assertDownload('bank-payments-2026-11.csv');
        $this->assertStringContainsString('1234567890', $paymentExport->streamedContent());
        $this->actingAs($administrator)->post(route('payroll.periods.close', $period))->assertRedirect();
        $this->assertSame('closed', $period->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.period_closed', 'subject_id' => $period->id]);
        $annualExport = $this->actingAs($financeOfficer)->get(route('payroll.reports.annual', 2026));
        $annualExport->assertDownload('annual-payroll-preparation-2026.csv');
        $this->assertStringContainsString('PREPARATION DATA ONLY', $annualExport->streamedContent());
        $this->assertStringContainsString('TIN-ANNUAL-101', $annualExport->streamedContent());
        $this->actingAs($financeOfficer)->get(route('payroll.reports.departments', 2026))
            ->assertDownload('department-payroll-2026.csv');
        $this->actingAs($employee)->get('/my-payroll/payslips/'.$entry->id)->assertOk();
    }

    public function test_overtime_requires_review_and_uses_a_snapshotted_effective_rule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15'));
        $payrollOfficer = $this->user('payroll.overtime-officer@example.test', 'payroll_officer');
        $reviewer = $this->user('payroll.overtime-reviewer@example.test', 'hr_director');
        $unrelatedManager = $this->user('payroll.overtime-manager@example.test', 'hr_manager');
        $employee = $this->user('payroll.overtime-employee@example.test', 'employee', 'PAY-801');
        $structure = $this->structure($payrollOfficer, 'Overtime Plan', '500000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $period = $this->period($payrollOfficer);

        $this->actingAs($payrollOfficer)->post(route('payroll.settings.overtime.store'), [
            'country_code' => 'TZ',
            'currency' => 'TZS',
            'frequency' => 'monthly',
            'overtime_type' => 'normal',
            'calculation_basis' => 'basic',
            'hours_per_period' => '160',
            'multiplier' => '1.500',
            'is_taxable' => '1',
            'is_pensionable' => '0',
            'effective_from' => '2026-01-01',
            'authority_reference' => 'Verified employer overtime policy fixture',
        ])->assertRedirect();
        $this->actingAs($employee)->post(route('payroll.overtime.store'), [
            'worked_on' => '2026-11-15',
            'overtime_type' => 'normal',
            'hours' => '2',
            'reason' => 'Approved project work',
        ])->assertRedirect();
        $overtime = PayrollOvertime::firstOrFail();

        $this->actingAs($payrollOfficer)->post(route('payroll.periods.process', $period))
            ->assertSessionHasErrors('period');
        $this->actingAs($unrelatedManager)->get(route('payroll.approvals'))->assertOk()->assertDontSee($employee->name);
        $this->post(route('payroll.overtime.review', $overtime), [
            'decision' => 'approve',
        ])->assertForbidden();
        $this->actingAs($reviewer)->post(route('payroll.overtime.review', $overtime), [
            'decision' => 'approve',
            'review_note' => 'Verified by supervisor',
        ])->assertRedirect();
        $this->actingAs($payrollOfficer)->post(route('payroll.periods.process', $period))->assertRedirect();

        $line = PayrollEntry::firstOrFail()->lines()->where('source_type', 'overtime')->firstOrFail();
        $this->assertSame('9375.00', $line->amount);
        $this->assertSame('2.00', $line->calculation_hours);
        $this->assertSame('1.500', $line->calculation_multiplier);
        $this->assertSame('processed', $overtime->fresh()->status);
        $this->assertCount(1, $period->fresh()->overtime_rules_snapshot);
    }

    public function test_loan_repayment_starts_only_after_terms_approval_and_disbursement_and_is_paid_with_payroll(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-05'));
        $payrollOfficer = $this->user('payroll.loan-officer@example.test', 'payroll_officer');
        $reviewer = $this->user('payroll.loan-reviewer@example.test', 'hr_director');
        $financeOfficer = $this->user('payroll.loan-finance@example.test', 'finance_officer');
        $employee = $this->user('payroll.loan-employee@example.test', 'employee', 'PAY-802');
        $structure = $this->structure($payrollOfficer, 'Loan Plan', '500000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $november = $this->period($payrollOfficer);

        $this->actingAs($employee)->post(route('payroll.loans.store'), [
            'loan_type' => 'loan',
            'principal_amount' => '30000.00',
            'reason' => 'Emergency expense',
        ])->assertRedirect();
        $loan = EmployeePayrollLoan::firstOrFail();
        $this->actingAs($reviewer)->post(route('payroll.loans.review', $loan), [
            'decision' => 'approve',
            'total_repayable' => '30000.00',
            'installment_amount' => '10000.00',
            'repayment_starts_on' => '2027-01-01',
            'terms_reference' => 'Signed agreement fixture',
        ])->assertRedirect();
        $this->assertSame('approved', $loan->fresh()->status);
        $this->actingAs($payrollOfficer)->post(route('payroll.periods.process', $november))->assertRedirect();
        $this->assertDatabaseCount('payroll_loan_repayments', 0);
        $this->actingAs($financeOfficer)->post(route('payroll.periods.approve', $november))->assertRedirect();
        $this->post(route('payroll.periods.paid', $november), ['payment_reference' => 'NOV-PAYMENT'])->assertRedirect();
        $this->post(route('payroll.loans.disburse', $loan), [
            'disbursement_reference' => 'BANK-LOAN-802',
            'disbursed_at' => '2026-12-05',
        ])->assertRedirect();
        $this->assertSame('active', $loan->fresh()->status);

        $january = $this->period($payrollOfficer, '2027-01', '2027-01-01', '2027-01-31', '2027-02-01');
        $this->actingAs($payrollOfficer)->post(route('payroll.periods.process', $january))->assertRedirect();
        $repayment = PayrollLoanRepayment::firstOrFail();
        $this->assertSame('scheduled', $repayment->status);
        $this->assertSame('10000.00', $repayment->amount);
        $this->actingAs($financeOfficer)->post(route('payroll.periods.approve', $january))->assertRedirect();
        $this->post(route('payroll.periods.paid', $january), ['payment_reference' => 'JAN-PAYMENT'])->assertRedirect();
        $this->assertSame('paid', $repayment->fresh()->status);
        $this->assertSame('20000.00', $loan->fresh()->remainingBalance());
    }

    public function test_pensionable_pay_basis_excludes_non_pensionable_components(): void
    {
        $payrollOfficer = $this->user('payroll.pensionable-officer@example.test', 'payroll_officer');
        $employee = $this->user('payroll.pensionable-employee@example.test', 'employee', 'PAY-803');
        $structure = $this->structure($payrollOfficer, 'Pensionable Plan', '500000.00');
        $structure->components()->create([
            'code' => 'TRANSPORT',
            'name' => 'Transport allowance',
            'component_type' => 'earning',
            'calculation_method' => 'fixed',
            'value' => '20000.0000',
            'is_taxable' => true,
            'is_pensionable' => false,
            'is_recurring' => true,
            'is_statutory' => false,
            'is_active' => true,
        ]);
        $structure->components()->create([
            'code' => 'PERFORMANCE',
            'name' => 'Performance allowance',
            'component_type' => 'earning',
            'calculation_method' => 'percent_gross',
            'value' => '10.0000',
            'is_taxable' => true,
            'is_pensionable' => false,
            'is_recurring' => true,
            'is_statutory' => false,
            'is_active' => true,
        ]);
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $period = $this->period($payrollOfficer);
        PayrollStatutoryRule::where('code', 'NSSF')->update(['calculation_basis' => 'pensionable_pay']);

        $this->actingAs($payrollOfficer)->post(route('payroll.periods.process', $period))->assertRedirect();

        $entry = PayrollEntry::firstOrFail();
        $pensionLine = $entry->lines()->where('name', 'NSSF employee contribution')->firstOrFail();
        $allowanceLine = $entry->lines()->where('name', 'Transport allowance')->firstOrFail();
        $performanceLine = $entry->lines()->where('name', 'Performance allowance')->firstOrFail();
        $this->assertSame('500000.00', $pensionLine->calculation_base);
        $this->assertSame('50000.00', $pensionLine->amount);
        $this->assertSame('TRANSPORT', $allowanceLine->component_code_snapshot);
        $this->assertSame('520000.00', $performanceLine->calculation_base);
        $this->assertSame('52000.00', $performanceLine->amount);
    }

    public function test_non_full_pay_leave_requires_a_reviewed_period_adjustment_not_calendar_day_proration(): void
    {
        $payrollOfficer = $this->user('payroll.leave-officer@example.test', 'payroll_officer');
        $employee = $this->user('payroll.leave-employee@example.test', 'employee', 'PAY-804');
        $structure = $this->structure($payrollOfficer, 'Leave Payroll Plan', '500000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $leaveType = LeaveType::create([
            'name' => 'Unpaid leave fixture',
            'deducts_balance' => false,
            'payroll_treatment' => 'unpaid',
            'payroll_paid_fraction' => '0.0000',
            'is_active' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-12',
            'requested_days' => '3.00',
            'reason' => 'Approved unpaid leave fixture',
            'status' => 'approved',
        ]);
        $period = $this->period($payrollOfficer);

        $this->actingAs($payrollOfficer)->post(route('payroll.periods.process', $period))
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('payroll_entries', 0);

        $this->post(route('payroll.adjustments.store', $period), [
            'employee_id' => $employee->id,
            'category' => 'leave_adjustment',
            'description' => 'Reviewed leave adjustment under employer workday policy',
            'amount' => '15000.00',
            'is_taxable' => '0',
        ])->assertRedirect();
        $this->post(route('payroll.periods.process', $period))->assertRedirect();
        $this->assertSame('15000.00', PayrollEntry::firstOrFail()->lines()->where('name', 'Reviewed leave adjustment under employer workday policy')->value('amount'));
    }

    public function test_payroll_processor_cannot_approve_own_run_and_employees_cannot_view_other_payslips(): void
    {
        $payrollOfficer = $this->user('payroll.separation@example.test', 'system_admin');
        $employee = $this->user('payroll.separation-employee@example.test', 'employee', 'PAY-201');
        $otherEmployee = $this->user('payroll.separation-other@example.test', 'employee', 'PAY-202');
        $otherEmployee->forceFill([
            'payroll_profile' => [...$otherEmployee->payroll_profile, 'payroll_status' => 'excluded'],
        ])->save();
        $structure = $this->structure($payrollOfficer, 'Second Salary Plan', '700000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $period = $this->period($payrollOfficer);

        $this->actingAs($payrollOfficer)->post('/payroll/periods/'.$period->id.'/process')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertSessionHasErrors('period');
        $this->assertSame('processed', $period->fresh()->status);
        $entry = PayrollEntry::where('employee_id', $employee->id)->firstOrFail();
        $this->actingAs($otherEmployee)->get('/payroll/payslips/'.$entry->id)->assertForbidden();
        $this->actingAs($employee)->get('/my-payroll')->assertOk()->assertDontSee('PAY-201');
        $this->actingAs($payrollOfficer)->post('/payroll/periods/'.$period->id.'/paid', ['payment_reference' => 'UNAPPROVED'])->assertSessionHasErrors('period');
    }

    public function test_assignments_cannot_overlap_and_deductions_cannot_exceed_gross(): void
    {
        $payrollOfficer = $this->user('payroll.validation@example.test', 'payroll_officer');
        $employee = $this->user('payroll.validation-employee@example.test', 'employee', 'PAY-301');
        $structure = $this->structure($payrollOfficer, 'Third Salary Plan', '1000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $this->actingAs($payrollOfficer)->post('/payroll/salary-assignments', [
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'effective_from' => '2026-12-01',
            'change_reason' => 'Annual salary review approved by HR.',
        ])->assertRedirect();
        $assignments = EmployeeSalaryAssignment::where('employee_id', $employee->id)->orderBy('effective_from')->get();
        $this->assertSame('2026-11-30', $assignments[0]->effective_to->toDateString());
        $period = $this->period($payrollOfficer);
        $structure->components()->create([
            'name' => 'Invalid excess deduction',
            'component_type' => 'deduction',
            'calculation_method' => 'fixed',
            'value' => '1500.0000',
            'is_taxable' => false,
            'is_statutory' => false,
            'is_active' => true,
        ]);
        $this->post('/payroll/periods/'.$period->id.'/process')->assertSessionHasErrors('period');
        $this->assertSame('draft', $period->fresh()->status);
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_money_calculator_rounds_using_decimal_arithmetic(): void
    {
        $calculator = app(PayrollCalculator::class);

        $this->assertSame('1.24', $calculator->money('1.235'));
        $this->assertSame('1.23', $calculator->money('1.2349'));
        $this->assertSame('0.01', $calculator->percent('0.10', '5'));
        $this->assertSame('61728.39', $calculator->percent('1234567.89', '5'));
        $this->assertSame('11575.00', $calculator->progressiveTax('481500.00', 'monthly', [
            'bands' => [
                ['limit' => '3000000', 'rate' => '0'],
                ['limit' => null, 'rate' => '5'],
            ],
            'non_resident_rate' => '15',
        ], 'resident'));
    }

    public function test_tanzania_payroll_requires_effective_verified_rules_before_processing(): void
    {
        $payrollOfficer = $this->user('payroll.missing-rules@example.test', 'payroll_officer');
        $employee = $this->user('payroll.missing-rules-employee@example.test', 'employee', 'PAY-501');
        $structure = $this->structure($payrollOfficer, 'Missing Rules Plan', '400000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $period = $this->period($payrollOfficer);
        PayrollStatutoryRule::query()->delete();

        $this->actingAs($payrollOfficer)->from('/payroll/periods/'.$period->id)->post('/payroll/periods/'.$period->id.'/process')
            ->assertRedirect('/payroll/periods/'.$period->id)
            ->assertSessionHasErrors('period');

        $this->assertSame('draft', $period->fresh()->status);
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_payroll_preflight_blocks_unassigned_active_employees_until_explicitly_excluded(): void
    {
        $payrollOfficer = $this->user('payroll.preflight@example.test', 'payroll_officer');
        $assignedEmployee = $this->user('payroll.preflight-assigned@example.test', 'employee', 'PAY-551');
        $unassignedEmployee = $this->user('payroll.preflight-unassigned@example.test', 'employee', 'PAY-552');
        $structure = $this->structure($payrollOfficer, 'Preflight Salary Plan', '400000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $assignedEmployee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollOfficer->id,
            'effective_from' => '2026-11-01',
        ]);
        $period = $this->period($payrollOfficer);

        $this->actingAs($payrollOfficer)->get('/payroll/periods/'.$period->id)
            ->assertOk()
            ->assertSee('1 active payroll employee(s) need exactly one matching salary assignment');
        $this->post('/payroll/periods/'.$period->id.'/process')->assertSessionHasErrors('period');
        $this->assertDatabaseCount('payroll_entries', 0);

        $unassignedEmployee->forceFill([
            'payroll_profile' => [...$unassignedEmployee->payroll_profile, 'payroll_status' => 'excluded'],
        ])->save();
        $this->post('/payroll/periods/'.$period->id.'/process')->assertRedirect();
        $this->assertDatabaseCount('payroll_entries', 1);
    }

    public function test_statutory_rule_versions_are_effective_dated_and_audited(): void
    {
        $administrator = $this->user('payroll.rules@example.test', 'system_admin');
        $this->configureStatutoryRules($administrator);
        $this->actingAs($administrator)->post(route('payroll.settings.statutory.store'), [
            'code' => 'WCF',
            'jurisdiction' => 'mainland',
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'employee_rate' => '0',
            'employer_rate' => '0.7500',
            'calculation_basis' => 'gross',
            'authority_reference' => 'Verified test source 2027',
        ])->assertRedirect();

        $rule = PayrollStatutoryRule::where('code', 'WCF')->orderByDesc('version')->firstOrFail();
        $this->assertSame(2, $rule->version);
        $this->assertSame('2026-12-31', PayrollStatutoryRule::where('code', 'WCF')->where('version', 1)->firstOrFail()->effective_to->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.statutory_rule_created', 'subject_id' => $rule->id]);

        $this->post(route('payroll.settings.statutory.store'), [
            'code' => 'WCF',
            'jurisdiction' => 'mainland',
            'effective_from' => '2027-06-01',
            'employee_rate' => '0',
            'employer_rate' => '0.8000',
            'calculation_basis' => 'gross',
            'authority_reference' => 'Overlapping test source',
        ])->assertSessionHasErrors('effective_from');
        $this->assertSame(2, PayrollStatutoryRule::where('code', 'WCF')->count());
    }

    public function test_statutory_rule_settings_reject_unverified_sdl_thresholds(): void
    {
        $administrator = $this->user('payroll.sdl-admin@example.test', 'system_admin');

        $this->actingAs($administrator)->post(route('payroll.settings.statutory.store'), [
            'code' => 'SDL',
            'jurisdiction' => 'mainland',
            'effective_from' => '2026-01-01',
            'employee_rate' => '0',
            'employer_rate' => '1.0000',
            'calculation_basis' => 'gross',
            'parameters' => json_encode(['minimum_employee_count' => 0]),
            'authority_reference' => 'Invalid threshold test',
        ])->assertSessionHasErrors('parameters');

        $this->assertDatabaseCount('payroll_statutory_rules', 0);
    }

    public function test_bank_export_requires_complete_employee_payment_details(): void
    {
        $payrollAdministrator = $this->user('payroll.bank-admin@example.test', 'system_admin');
        $financeOfficer = $this->user('payroll.bank-finance@example.test', 'finance_officer');
        $employee = $this->user('payroll.bank-employee@example.test', 'employee', 'PAY-701');
        $structure = $this->structure($payrollAdministrator, 'Bank Export Plan', '400000.00');
        EmployeeSalaryAssignment::create([
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'assigned_by' => $payrollAdministrator->id,
            'effective_from' => '2026-11-01',
        ]);
        $period = $this->period($payrollAdministrator);

        $this->actingAs($payrollAdministrator)->post('/payroll/periods/'.$period->id.'/process')->assertRedirect();
        $this->actingAs($financeOfficer)->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/paid', ['payment_reference' => 'BANK-TEST-701'])->assertRedirect();
        $this->from('/payroll/periods/'.$period->id)->get(route('payroll.periods.bank-payments.export', $period))
            ->assertRedirect('/payroll/periods/'.$period->id)
            ->assertSessionHasErrors('period');
    }

    public function test_employee_statutory_identifiers_are_encrypted_and_not_written_to_audit_details(): void
    {
        $administrator = $this->user('payroll.profile-admin@example.test', 'system_admin');
        $employee = $this->user('payroll.profile-employee@example.test', 'employee', 'PAY-601');

        $this->actingAs($administrator)->put(route('payroll.employees.statutory-profile', $employee), [
            'payroll_status' => 'active',
            'tax_residency' => 'resident',
            'pension_fund' => 'nssf',
            'tax_identifier' => 'TIN-PRIVATE-601',
            'pension_member_number' => 'PENSION-PRIVATE-601',
            'nhif_member_number' => 'NHIF-PRIVATE-601',
            'heslb_account_number' => 'HESLB-PRIVATE-601',
            'bank_name' => 'Private Fixture Bank',
            'bank_branch' => 'Private Fixture Branch',
            'bank_account_number' => 'PRIVATE-ACCOUNT-601',
            'nhif_enrolled' => '1',
        ])->assertRedirect();

        $this->assertSame('TIN-PRIVATE-601', $employee->fresh()->tax_identifier);
        $this->assertNotSame('TIN-PRIVATE-601', DB::table('users')->where('id', $employee->id)->value('tax_identifier'));
        $this->assertSame('PRIVATE-ACCOUNT-601', $employee->fresh()->bank_account_number);
        $auditDetails = DB::table('audit_logs')->where('action', 'payroll.statutory_profile_updated')->value('details');
        $this->assertStringNotContainsString('PRIVATE-ACCOUNT-601', (string) $auditDetails);
        $this->assertStringNotContainsString('TIN-PRIVATE-601', (string) $auditDetails);
    }

    public function test_employee_users_and_hr_managers_cannot_open_payroll(): void
    {
        $employee = $this->user('payroll.denied@example.test', 'employee', 'PAY-401');
        $manager = $this->user('payroll.denied-manager@example.test', 'hr_manager');

        $this->actingAs($employee)->get('/payroll')->assertForbidden();
        $this->actingAs($manager)->get('/payroll')->assertForbidden();
    }

    private function structure(User $creator, string $name, string $basicSalary): SalaryStructure
    {
        return SalaryStructure::create([
            'created_by' => $creator->id,
            'name' => $name,
            'country_code' => 'TZ',
            'currency' => 'TZS',
            'frequency' => 'monthly',
            'basic_salary' => $basicSalary,
            'is_active' => true,
        ]);
    }

    private function period(
        User $creator,
        string $periodKey = '2026-11',
        string $startsOn = '2026-11-01',
        string $endsOn = '2026-11-30',
        string $payOn = '2026-12-01'
    ): PayrollPeriod {
        if (! PayrollStatutoryRule::exists()) {
            $this->configureStatutoryRules($creator);
        }

        return PayrollPeriod::create([
            'created_by' => $creator->id,
            'country_code' => 'TZ',
            'currency' => 'TZS',
            'frequency' => 'monthly',
            'period_key' => $periodKey,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'pay_on' => $payOn,
            'status' => 'draft',
        ]);
    }

    private function user(string $email, string $role, ?string $employeeNumber = null): User
    {
        $user = new User;
        $user->forceFill([
            'name' => str($email)->before('@')->replace('.', ' ')->title()->toString(),
            'email' => $email,
            'employee_no' => $employeeNumber,
            'password' => Hash::make('Secure-password-123!'),
            'role' => $role,
            'is_active' => true,
            'payroll_profile' => $role === 'employee' ? [
                'payroll_status' => 'active',
                'tax_residency' => 'resident',
                'pension_fund' => 'nssf',
                'nhif_enrolled' => true,
                'heslb_billed' => false,
            ] : null,
        ])->save();

        return $user;
    }

    private function configureStatutoryRules(User $creator): void
    {
        $rules = [
            ['code' => 'PAYE', 'employee_rate' => null, 'employer_rate' => null, 'calculation_basis' => null, 'parameters' => [
                'bands' => [
                    ['limit' => '3000000', 'rate' => '0'],
                    ['limit' => null, 'rate' => '5'],
                ],
                'non_resident_rate' => '15',
            ]],
            ['code' => 'NSSF', 'employee_rate' => '10.0000', 'employer_rate' => '10.0000', 'calculation_basis' => 'gross', 'parameters' => ['paye_deductible' => true]],
            ['code' => 'PSSSF', 'employee_rate' => '5.0000', 'employer_rate' => '15.0000', 'calculation_basis' => 'gross', 'parameters' => ['paye_deductible' => true]],
            ['code' => 'WCF', 'employee_rate' => '0', 'employer_rate' => '0.5000', 'calculation_basis' => 'basic', 'parameters' => []],
            ['code' => 'NHIF', 'employee_rate' => '3.0000', 'employer_rate' => '3.0000', 'calculation_basis' => 'basic', 'parameters' => []],
            ['code' => 'SDL', 'employee_rate' => '0', 'employer_rate' => '0', 'calculation_basis' => 'gross', 'parameters' => ['minimum_employee_count' => 10]],
            ['code' => 'HESLB', 'employee_rate' => '15.0000', 'employer_rate' => '0', 'calculation_basis' => 'basic', 'parameters' => []],
        ];

        foreach ($rules as $rule) {
            PayrollStatutoryRule::create([
                'created_by' => $creator->id,
                'code' => $rule['code'],
                'jurisdiction' => 'mainland',
                'version' => 1,
                'effective_from' => '2026-01-01',
                'employee_rate' => $rule['employee_rate'],
                'employer_rate' => $rule['employer_rate'],
                'calculation_basis' => $rule['calculation_basis'],
                'parameters' => $rule['parameters'],
                'authority_reference' => 'Automated test fixture only; not a legal rate',
                'is_active' => true,
            ]);
        }
    }
}
