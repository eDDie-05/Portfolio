<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_needs_audited_compatibility_attestation_before_token_issue(): void
    {
        $admin = new User;
        $admin->forceFill(['name' => 'System Admin', 'email' => 'admin@example.test', 'password' => Hash::make('SecureStartPassword123!'), 'role' => 'system_admin', 'is_active' => true])->save();
        $device = AttendanceDevice::where('device_code', 'ZKT-001')->firstOrFail();
        $device->forceFill(['model' => 'Vendor confirmed model', 'serial_number' => 'SERIAL-001', 'firmware_version' => '1.0', 'connection_type' => 'csv_import'])->save();

        $this->actingAs($admin)->post(route('devices.verify', $device), ['confirmed' => '1', 'verification_notes' => 'Checked device label and vendor documentation for this exact firmware.'])->assertRedirect('/devices');
        $this->assertNotNull($device->fresh()->compatibility_verified_at);
        $this->assertSame(1, Artisan::call('hrms:rotate-device-token', ['deviceCode' => 'ZKT-002']));
        $this->assertNull(AttendanceDevice::where('device_code', 'ZKT-002')->value('api_token_hash'));

        $this->assertSame(0, Artisan::call('hrms:rotate-device-token', ['deviceCode' => 'ZKT-001']));
        $this->assertNotNull($device->fresh()->api_token_hash);
    }
}
