<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceEventApiTest extends TestCase
{
    use RefreshDatabase;

    private function device(): array
    {
        $branch = Branch::create(['name' => 'Test Branch', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true]);
        $token = bin2hex(random_bytes(32));
        $device = AttendanceDevice::create(['device_code' => 'ZKT-TEST', 'branch_id' => $branch->id, 'manufacturer' => 'ZKTeco', 'integration_status' => 'configured', 'compatibility_verified_at' => now(), 'verification_notes' => 'Test fixture has verified device capabilities.', 'api_token_hash' => hash('sha256', $token), 'is_active' => true]);

        return [$device, $token];
    }

    public function test_event_api_rejects_requests_without_device_credentials(): void
    {
        $response = $this->postJson('/api/attendance/devices/events', ['events' => []]);
        $response->assertUnauthorized();
    }

    public function test_event_is_preserved_and_unmatched_users_are_flagged(): void
    {
        [$device, $token] = $this->device();
        $payload = ['events' => [[
            'device_user_id' => 'fingerprint-19', 'event_timestamp' => '2026-11-09T08:15:00+03:00',
            'event_identifier' => 'record-1', 'event_type' => 'unknown-punch', 'payload' => ['raw_code' => 9],
        ]]];

        $this->withToken($token)->withHeader('X-Device-Code', $device->device_code)->postJson('/api/attendance/devices/events', $payload)
            ->assertAccepted()->assertJson(['accepted' => 1, 'duplicates' => 0, 'unmatched' => 1]);
        $this->assertDatabaseHas('raw_attendance_events', ['device_id' => $device->id, 'device_user_id' => 'fingerprint-19', 'event_identifier' => 'record-1', 'processing_status' => 'unmatched']);
    }

    public function test_repeated_event_identifier_is_idempotent(): void
    {
        [$device, $token] = $this->device();
        $payload = ['events' => [['device_user_id' => 'unknown-1', 'event_timestamp' => '2026-11-09T08:15:00Z', 'event_identifier' => 'stable-id', 'payload' => ['raw' => 'preserved']]]];
        $this->withToken($token)->withHeader('X-Device-Code', $device->device_code)->postJson('/api/attendance/devices/events', $payload)->assertAccepted();
        $this->withToken($token)->withHeader('X-Device-Code', $device->device_code)->postJson('/api/attendance/devices/events', $payload)->assertAccepted()->assertJson(['accepted' => 0, 'duplicates' => 1, 'unmatched' => 0]);
        $this->assertDatabaseCount('raw_attendance_events', 1);
    }
}
