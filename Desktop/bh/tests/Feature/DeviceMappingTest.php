<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_mapping_history_closes_old_assignment_when_an_id_is_reassigned(): void
    {
        $branch = Branch::firstOrFail();
        $device = AttendanceDevice::where('device_code', 'ZKT-001')->firstOrFail();
        $department = Department::factory()->create(['name' => 'Operations']);
        $admin = new User;
        $admin->forceFill(['name' => 'HR Manager', 'email' => 'manager@example.test', 'employee_no' => 'MGR-001', 'password' => Hash::make('SecureStartPassword123!'), 'role' => 'hr_manager', 'department' => $department->name, 'department_id' => $department->id, 'branch_id' => $branch->id, 'is_active' => true])->save();
        $employees = [];
        foreach ([['BRV-100', 'Musa One'], ['BRV-101', 'Musa Two']] as [$number,$name]) {
            $employee = new User;
            $employee->forceFill(['name' => $name, 'email' => strtolower($number).'@example.test', 'employee_no' => $number, 'password' => Hash::make('EmployeePassword123!'), 'role' => 'employee', 'is_active' => true, 'branch_id' => $branch->id, 'department' => $department->name, 'department_id' => $department->id, 'manager_id' => $admin->id])->save();
            $employees[] = $employee;
        }

        $this->actingAs($admin)->post(route('devices.mappings.store', $device), ['device_user_id' => '19', 'employee_id' => $employees[0]->id, 'valid_from' => '2026-11-01T08:00'])->assertRedirect();
        $this->actingAs($admin)->post(route('devices.mappings.store', $device), ['device_user_id' => '19', 'employee_id' => $employees[1]->id, 'valid_from' => '2026-11-05T08:00'])->assertRedirect();

        $this->assertDatabaseCount('device_employee_mappings', 2);
        $this->assertDatabaseHas('device_employee_mappings', ['device_id' => $device->id, 'device_user_id' => '19', 'employee_id' => $employees[0]->id, 'valid_until' => '2026-11-05 04:59:59']);
        $this->assertDatabaseHas('device_employee_mappings', ['device_id' => $device->id, 'device_user_id' => '19', 'employee_id' => $employees[1]->id]);
    }
}
