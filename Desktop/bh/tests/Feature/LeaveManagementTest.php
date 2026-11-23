<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeaveManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_request_leave_and_authorized_hr_approval_updates_balance_and_audit_log(): void
    {
        $employee = $this->user('leave.employee@example.test', 'employee', 'EMP-501');
        $administrator = $this->user('leave.admin@example.test', 'system_admin');
        $leaveType = LeaveType::where('name', 'Annual leave')->firstOrFail();
        $balance = LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => now()->year, 'allocated_days' => 12, 'used_days' => 2]);
        $startDate = CarbonImmutable::today()->addDays(10);
        $endDate = $startDate->addDays(1);

        $this->actingAs($employee)->get('/my-leave')->assertOk()->assertSee('Request leave')->assertSee('12.00');
        $this->actingAs($employee)->post('/my-leave', [
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'reason' => 'Family travel',
        ])->assertRedirect('/my-leave');

        $leaveRequest = LeaveRequest::firstOrFail();
        $this->actingAs($administrator)->get('/leave/approvals')->assertOk()->assertSee('Family travel');
        $this->get('/leave/settings')->assertOk()->assertSee('Allocate balance');
        $this->post('/leave/approvals/'.$leaveRequest->id.'/approve', ['review_note' => 'Approved'])->assertRedirect();

        $this->assertSame('approved', $leaveRequest->fresh()->status);
        $this->assertSame('4.00', $balance->fresh()->used_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.requested', 'subject_id' => $leaveRequest->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.approved', 'subject_id' => $leaveRequest->id]);
    }

    public function test_employee_cannot_request_more_balance_than_allocated_or_overlap_a_pending_request(): void
    {
        $employee = $this->user('leave.employee@example.test', 'employee', 'EMP-502');
        $leaveType = LeaveType::where('name', 'Annual leave')->firstOrFail();
        LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => now()->year, 'allocated_days' => 1, 'used_days' => 0]);
        $startDate = CarbonImmutable::today()->addDays(10);

        $this->actingAs($employee)->from('/my-leave')->post('/my-leave', [
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->addDay()->toDateString(),
            'reason' => 'Family travel',
        ])->assertSessionHasErrors('leave_type_id');
        $this->assertDatabaseCount('leave_requests', 0);

        $this->post('/my-leave', [
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->toDateString(),
            'reason' => 'Appointment',
        ])->assertRedirect('/my-leave');
        $this->from('/my-leave')->post('/my-leave', [
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->toDateString(),
            'reason' => 'Second appointment',
        ])->assertSessionHasErrors('start_date');
        $anotherDate = $startDate->addDays(2);
        $this->from('/my-leave')->post('/my-leave', [
            'leave_type_id' => $leaveType->id,
            'start_date' => $anotherDate->toDateString(),
            'end_date' => $anotherDate->toDateString(),
            'reason' => 'Third appointment',
        ])->assertSessionHasErrors('leave_type_id');
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_hr_manager_cannot_review_a_request_outside_their_direct_reports(): void
    {
        $employee = $this->user('leave.employee@example.test', 'employee', 'EMP-503');
        $manager = $this->user('leave.manager@example.test', 'hr_manager');
        $otherManager = $this->user('leave.other-manager@example.test', 'hr_manager');
        $employee->forceFill(['manager_id' => $otherManager->id])->save();
        $leaveType = LeaveType::where('name', 'Unpaid leave')->firstOrFail();
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'requested_days' => 1,
            'reason' => 'Personal matter',
        ]);

        $this->actingAs($manager)->post('/leave/approvals/'.$leaveRequest->id.'/approve')->assertForbidden();
        $this->assertSame('pending', $leaveRequest->fresh()->status);
    }

    public function test_employee_cancelling_future_approved_leave_restores_allocated_balance(): void
    {
        $employee = $this->user('leave.employee@example.test', 'employee', 'EMP-504');
        $leaveType = LeaveType::where('name', 'Annual leave')->firstOrFail();
        $balance = LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => now()->year, 'allocated_days' => 12, 'used_days' => 3]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(6)->toDateString(),
            'requested_days' => 2,
            'reason' => 'Personal travel',
            'status' => 'approved',
        ]);

        $this->actingAs($employee)->post('/my-leave/'.$leaveRequest->id.'/cancel')->assertRedirect();

        $this->assertSame('cancelled', $leaveRequest->fresh()->status);
        $this->assertSame('1.00', $balance->fresh()->used_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.cancelled', 'subject_id' => $leaveRequest->id]);
    }

    private function user(string $email, string $role, ?string $employeeNumber = null): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Test '.ucfirst($role),
            'email' => $email,
            'password' => Hash::make('SecureStartPassword123!'),
            'role' => $role,
            'employee_no' => $employeeNumber,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
