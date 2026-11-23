<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CsvAttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_stores_valid_raw_rows_and_logs_invalid_rows(): void
    {
        $admin = new User;
        $admin->forceFill(['name' => 'HR Admin', 'email' => 'hr@example.test', 'password' => Hash::make('SecureStartPassword123!'), 'role' => 'system_admin', 'is_active' => true])->save();
        $device = AttendanceDevice::where('device_code', 'ZKT-001')->firstOrFail();
        $csv = "device_user_id,event_timestamp,event_identifier,event_type\n42,2026-11-09 08:12:00,CSV-1,IN\n,not-a-date,BAD,OUT\n";

        $this->actingAs($admin)->post(route('devices.import', $device), ['attendance_file' => UploadedFile::fake()->createWithContent('attendance.csv', $csv)])
            ->assertRedirect()->assertSessionHas('warning');

        $this->assertDatabaseHas('raw_attendance_events', ['device_id' => $device->id, 'device_user_id' => '42', 'event_identifier' => 'CSV-1', 'source' => 'csv_import']);
        $this->assertDatabaseHas('integration_error_logs', ['source_id' => $device->id, 'error_code' => 'csv_row_invalid']);
        $this->assertDatabaseHas('attendance_sync_logs', ['device_id' => $device->id, 'status' => 'partial_failure', 'records_received' => 1, 'records_failed' => 2]);
    }
}
