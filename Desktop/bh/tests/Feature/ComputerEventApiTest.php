<?php

namespace Tests\Feature;

use App\Models\RegisteredComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputerEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_windows_events_are_stored_separately_and_restricted_to_attendance_event_ids(): void
    {
        $token = bin2hex(random_bytes(32));
        $computer = RegisteredComputer::create(['computer_code' => 'PC-TEST', 'hostname' => 'PC-01', 'is_active' => true, 'credential_hash' => hash('sha256', $token)]);
        $headers = ['X-Device-Code' => $computer->computer_code, 'Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)->postJson('/api/attendance/computers/events', ['events' => [[
            'event_identifier' => 'PC-TEST:PC-01:42', 'event_id' => 4624, 'event_timestamp' => '2026-11-09T05:15:00Z',
            'windows_account' => 'BRAVADO\\asha', 'windows_sid' => 'S-1-5-21-1001', 'logon_type' => 2, 'payload' => ['channel' => 'Security'],
        ]]])->assertAccepted()->assertJson(['accepted' => 1, 'duplicates' => 0, 'unmatched' => 1]);
        $this->assertDatabaseHas('computer_events', ['computer_id' => $computer->id, 'event_id' => 4624, 'processing_status' => 'unmatched']);
        $this->assertDatabaseMissing('raw_attendance_events', ['event_identifier' => 'PC-TEST:PC-01:42']);

        $this->withHeaders($headers)->postJson('/api/attendance/computers/events', ['events' => [[
            'event_identifier' => 'forbidden-event', 'event_id' => 4672, 'event_timestamp' => '2026-11-09T05:15:00Z',
            'windows_account' => 'BRAVADO\\asha', 'logon_type' => 2, 'payload' => [],
        ]]])->assertUnprocessable();
    }
}
