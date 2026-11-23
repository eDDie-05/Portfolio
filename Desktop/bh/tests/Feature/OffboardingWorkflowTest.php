<?php

namespace Tests\Feature;

use App\Models\OffboardingCase;
use App\Models\OffboardingClearanceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OffboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_offboarding_clearance_exit_interview_and_payroll_must_be_resolved_before_deactivation(): void
    {
        $administrator = $this->user('offboarding.admin@example.test', 'system_admin');
        $owner = $this->user('offboarding.owner@example.test', 'hr_manager');
        $employee = $this->user('offboarding.employee@example.test', 'employee', 'OFF-101');
        $employee->forceFill(['manager_id' => $owner->id])->save();
        $lastWorkingDay = now()->addDays(3)->toDateString();

        $this->actingAs($administrator)->post('/offboarding', [
            'employee_id' => $employee->id,
            'owner_id' => $owner->id,
            'reason' => 'resignation',
            'reason_details' => 'Private HR case details.',
            'notice_received_on' => now()->toDateString(),
            'last_working_day' => $lastWorkingDay,
        ])->assertRedirect();
        $case = OffboardingCase::firstOrFail();
        $this->assertSame('in_progress', $case->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'offboarding.case_created', 'subject_id' => $case->id]);
        $this->actingAs($administrator)->get('/offboarding')->assertOk()->assertSee('Offboarding');

        $this->actingAs($owner)->get('/offboarding/'.$case->id)->assertOk()->assertSee('Private HR case details.');
        $this->post('/offboarding', [
            'employee_id' => $employee->id,
            'reason' => 'resignation',
            'last_working_day' => $lastWorkingDay,
        ])->assertSessionHasErrors('employee_id');

        $this->post('/offboarding/'.$case->id.'/clearances', [
            'title' => 'Return laptop and badge',
            'department' => 'IT',
            'assigned_to' => $employee->id,
            'due_on' => $lastWorkingDay,
        ])->assertRedirect();
        $clearance = OffboardingClearanceItem::firstOrFail();
        $this->actingAs($employee)->get('/my-offboarding')->assertOk()->assertSee('Return laptop and badge')->assertDontSee('Private HR case details.')->assertDontSee('Final payroll');
        $this->post('/offboarding/clearances/'.$clearance->id.'/complete', [
            'decision' => 'waived',
            'completion_note' => 'Unauthorized waiver.',
        ])->assertForbidden();
        $this->post('/offboarding/clearances/'.$clearance->id.'/complete', [
            'decision' => 'cleared',
            'completion_note' => 'Equipment returned to IT.',
        ])->assertRedirect();
        $this->assertSame('cleared', $clearance->fresh()->status);

        $this->actingAs($owner)->post('/offboarding/'.$case->id.'/exit-interview', [
            'exit_interview_status' => 'completed',
            'exit_interview_at' => now()->subHour()->format('Y-m-d H:i'),
            'exit_interview_note' => 'Exit interview completed and recorded.',
        ])->assertRedirect();
        $this->post('/offboarding/'.$case->id.'/final-payroll', [
            'final_payroll_status' => 'processed',
            'final_payroll_note' => 'Payroll batch reference PAY-7781.',
        ])->assertRedirect();
        $this->assertSame('ready_to_close', $case->fresh()->status);

        $this->post('/offboarding/'.$case->id.'/close')->assertSessionHasErrors('case');
        $this->assertTrue($employee->fresh()->is_active);
        $this->travelTo(now()->addDays(4));
        $this->post('/offboarding/'.$case->id.'/close')->assertRedirect('/offboarding');
        $this->assertSame('completed', $case->fresh()->status);
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertNotNull($case->fresh()->account_deactivated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'offboarding.case_closed', 'subject_id' => $case->id]);
        $this->actingAs($employee->fresh())->get('/my-leave')->assertRedirect('/login')->assertSessionHasErrors('email');
    }

    public function test_hr_managers_can_open_cases_only_for_direct_reports_and_cancel_without_deactivating_them(): void
    {
        $manager = $this->user('offboarding.manager@example.test', 'hr_manager');
        $report = $this->user('offboarding.report@example.test', 'employee', 'OFF-201');
        $otherEmployee = $this->user('offboarding.other@example.test', 'employee', 'OFF-202');
        $report->forceFill(['manager_id' => $manager->id])->save();

        $this->actingAs($manager)->get('/offboarding/create')->assertOk()->assertSee('OFF-201')->assertDontSee('OFF-202');
        $this->post('/offboarding', [
            'employee_id' => $otherEmployee->id,
            'reason' => 'end_of_contract',
            'last_working_day' => now()->addDays(7)->toDateString(),
        ])->assertForbidden();
        $this->post('/offboarding', [
            'employee_id' => $report->id,
            'reason' => 'end_of_contract',
            'last_working_day' => now()->addDays(7)->toDateString(),
        ])->assertRedirect();
        $case = OffboardingCase::firstOrFail();
        $this->post('/offboarding/'.$case->id.'/cancel', ['cancellation_note' => 'The employee withdrew the notice.'])->assertRedirect();
        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertTrue($report->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'offboarding.case_cancelled', 'subject_id' => $case->id]);

        $this->actingAs($report)->get('/offboarding')->assertForbidden();
    }

    public function test_offboarding_factories_create_linked_cases_and_clearance_items(): void
    {
        $case = OffboardingCase::factory()->create();
        $item = OffboardingClearanceItem::factory()->for($case)->create();

        $this->assertSame($case->id, $item->offboarding_case_id);
        $this->assertDatabaseHas('offboarding_clearance_items', [
            'id' => $item->id,
            'offboarding_case_id' => $case->id,
            'status' => 'pending',
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
