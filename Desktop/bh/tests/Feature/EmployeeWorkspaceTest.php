<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = new User;
        $user->forceFill(['name' => 'HR Admin', 'email' => 'hr@example.test', 'password' => Hash::make('SecureStartPassword123!'), 'role' => 'system_admin', 'is_active' => true])->save();

        return $user;
    }

    public function test_hr_can_create_employee_and_employee_sees_only_personal_workspace(): void
    {
        $branch = Branch::firstOrFail();
        $this->actingAs($this->administrator())->post('/employees', [
            'name' => 'Asha Employee', 'email' => 'asha@example.test', 'employee_no' => 'BRV-104', 'department' => 'Operations', 'branch_id' => $branch->id,
            'phone' => '+255 700 123456', 'address' => 'Mikocheni, Dar es Salaam', 'date_of_birth' => '1992-04-18',
            'emergency_contact_name' => 'Neema Employee', 'emergency_contact_phone' => '+255 700 654321', 'emergency_contact_relationship' => 'Sibling',
            'job_title' => 'Operations Analyst', 'employment_type' => 'permanent', 'joined_on' => '2026-01-10',
            'probation_ends_on' => '2026-04-10', 'contract_starts_on' => '2026-01-10', 'contract_ends_on' => '2027-01-09',
            'password' => 'InitialPassword123!', 'password_confirmation' => 'InitialPassword123!',
        ])->assertRedirect('/employees');

        $employee = User::where('employee_no', 'BRV-104')->firstOrFail();
        $this->assertSame('employee', $employee->role);
        $this->assertSame('Operations Analyst', $employee->job_title);
        $this->assertSame('permanent', $employee->employment_type);
        $this->assertSame('1992-04-18', $employee->date_of_birth->toDateString());
        $this->assertSame('Sibling', $employee->emergency_contact_relationship);
        $this->assertSame('2027-01-09', $employee->contract_ends_on->toDateString());
        $this->actingAs($employee)->get('/my-attendance')->assertOk()->assertSee('Asha Employee');
        $this->actingAs($employee)->get('/')->assertForbidden();
    }

    public function test_employee_can_change_their_password(): void
    {
        $employee = new User;
        $employee->forceFill(['name' => 'Asha Employee', 'email' => 'asha@example.test', 'employee_no' => 'BRV-104', 'password' => Hash::make('InitialPassword123!'), 'role' => 'employee', 'is_active' => true])->save();
        $this->actingAs($employee)->post('/my-password', ['current_password' => 'InitialPassword123!', 'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!'])->assertRedirect();
        $this->assertTrue(Hash::check('ChangedPassword123!', $employee->fresh()->password));
    }

    public function test_employee_creation_rejects_reversed_employment_date_ranges(): void
    {
        $branch = Branch::firstOrFail();

        $this->actingAs($this->administrator())->from('/employees/create')->post('/employees', [
            'name' => 'Asha Employee',
            'email' => 'asha@example.test',
            'employee_no' => 'BRV-104',
            'branch_id' => $branch->id,
            'joined_on' => '2026-04-10',
            'probation_ends_on' => '2026-04-09',
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertSessionHasErrors('probation_ends_on');

        $this->assertDatabaseMissing('users', ['employee_no' => 'BRV-104']);
    }

    public function test_administrator_pages_render_with_live_provisional_device_data(): void
    {
        $this->actingAs($this->administrator())->get('/')->assertOk()->assertSee('Attendance overview')->assertSee('ZKT-001');
        $this->get('/devices')->assertOk()->assertSee('Device management')->assertSee('Awaiting configuration');
    }
}
