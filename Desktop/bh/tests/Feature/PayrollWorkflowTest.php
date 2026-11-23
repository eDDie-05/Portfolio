<?php

namespace Tests\Feature;

use App\Models\EmployeeSalaryAssignment;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Payroll\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('110420.00', $entry->deductions_amount);
        $this->assertSame('424580.00', $entry->net_amount);
        $this->assertSame(10, $entry->lines()->count());
        $this->assertSame('53500.00', $entry->lines()->where('name', 'NSSF employee contribution')->value('amount'));
        $this->assertSame('15000.00', $entry->lines()->where('name', 'NHIF employee contribution')->value('amount'));
        $this->assertSame('16920.00', $entry->lines()->where('name', 'PAYE')->value('amount'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.period_processed', 'subject_id' => $period->id]);

        $this->post('/payroll/periods/'.$period->id.'/approve')->assertForbidden();
        $this->actingAs($financeOfficer)->get('/payroll/periods/'.$period->id)->assertOk()->assertSee('535,000.00');
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $period->fresh()->status);
        $this->actingAs($employee)->get('/my-payroll')->assertOk()->assertSee('2026-11');
        $this->get('/my-payroll/payslips/'.$entry->id)->assertOk()->assertSee('Approved performance bonus');
        $this->get('/payroll/payslips/'.$entry->id)->assertForbidden();
        $this->actingAs($financeOfficer)->get('/payroll/periods/'.$period->id.'/export')->assertDownload('payroll-2026-11.csv');
        $this->actingAs($administrator)->post('/payroll/periods/'.$period->id.'/paid', ['payment_reference' => 'BANK-BATCH-123'])->assertRedirect();
        $this->assertSame('paid', $period->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.period_paid', 'subject_id' => $period->id]);
    }

    public function test_payroll_processor_cannot_approve_own_run_and_employees_cannot_view_other_payslips(): void
    {
        $payrollOfficer = $this->user('payroll.separation@example.test', 'system_admin');
        $employee = $this->user('payroll.separation-employee@example.test', 'employee', 'PAY-201');
        $otherEmployee = $this->user('payroll.separation-other@example.test', 'employee', 'PAY-202');
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

    private function period(User $creator): PayrollPeriod
    {
        return PayrollPeriod::create([
            'created_by' => $creator->id,
            'country_code' => 'TZ',
            'currency' => 'TZS',
            'frequency' => 'monthly',
            'period_key' => '2026-11',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'pay_on' => '2026-12-01',
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
        ])->save();

        return $user;
    }
}
