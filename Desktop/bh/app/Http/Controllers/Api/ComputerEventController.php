<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComputerUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComputerEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $computer = $request->attributes->get('integration_record');
        $data = $request->validate(['events' => ['required', 'array', 'min:1', 'max:500'], 'events.*.event_identifier' => ['required', 'string', 'max:190'], 'events.*.event_id' => ['required', 'integer', 'in:4624,4634,4647'], 'events.*.event_timestamp' => ['required', 'date'], 'events.*.windows_account' => ['required', 'string', 'max:190'], 'events.*.windows_sid' => ['nullable', 'string', 'max:190'], 'events.*.logon_type' => ['nullable', 'integer', 'in:2,7,10,11'], 'events.*.payload' => ['required', 'array']]);
        $accepted = 0;
        $duplicates = 0;
        $unmatched = 0;
        DB::transaction(function () use ($data, $computer, &$accepted, &$duplicates, &$unmatched): void {
            foreach ($data['events'] as $event) {
                $mapping = ComputerUser::where('computer_id', $computer->id)->where(function ($query) use ($event) {
                    $query->where('windows_account', $event['windows_account']);
                    if (! empty($event['windows_sid'])) {
                        $query->orWhere('windows_sid', $event['windows_sid']);
                    }
                })->first();
                $inserted = DB::table('computer_events')->insertOrIgnore([
                    'computer_id' => $computer->id, 'employee_id' => $mapping?->employee_id, 'windows_account' => $event['windows_account'],
                    'windows_sid' => $event['windows_sid'] ?? null, 'event_id' => $event['event_id'], 'event_timestamp' => now()->parse($event['event_timestamp'])->utc(),
                    'event_identifier' => $event['event_identifier'], 'processing_status' => $mapping ? 'pending' : 'unmatched',
                    'raw_payload' => json_encode($event['payload'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted) {
                    $accepted++;
                    if (! $mapping) {
                        $unmatched++;
                    }
                } else {
                    $duplicates++;
                }
            }
            DB::table('computer_agent_status')->updateOrInsert(['computer_id' => $computer->id], ['last_seen_at' => now(), 'last_successful_communication_at' => now(), 'status' => 'online', 'details' => 'Event batch received.', 'updated_at' => now(), 'created_at' => now()]);
        });

        return response()->json(['accepted' => $accepted, 'duplicates' => $duplicates, 'unmatched' => $unmatched], 202);
    }

    public function health(Request $request): JsonResponse
    {
        $computer = $request->attributes->get('integration_record');
        $data = $request->validate(['agent_version' => ['required', 'string', 'max:80'], 'status' => ['required', 'in:healthy,degraded'], 'details' => ['nullable', 'string', 'max:1000'], 'queued_events' => ['nullable', 'integer', 'min:0']]);
        $computer->forceFill(['agent_version' => $data['agent_version']])->save();
        DB::table('computer_agent_status')->updateOrInsert(['computer_id' => $computer->id], ['last_seen_at' => now(), 'last_successful_communication_at' => now(), 'status' => $data['status'], 'details' => $data['details'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['received' => true]);
    }
}
