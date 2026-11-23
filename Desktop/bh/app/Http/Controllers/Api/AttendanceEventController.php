<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAttendanceEvent;
use App\Models\DeviceEmployeeMapping;
use App\Models\RawAttendanceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $device = $request->attributes->get('integration_record');
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:1000'],
            'events.*.device_user_id' => ['required', 'string', 'max:120'],
            'events.*.event_timestamp' => ['required', 'date'],
            'events.*.event_identifier' => ['nullable', 'string', 'max:190'],
            'events.*.event_type' => ['nullable', 'string', 'max:80'],
            'events.*.payload' => ['nullable', 'array'],
        ]);
        $device->loadMissing('location');
        $timezone = $device->location?->timezone ?? config('attendance.display_timezone');

        $accepted = 0;
        $duplicates = 0;
        $unmatched = 0;
        DB::transaction(function () use ($data, $device, $timezone, &$accepted, &$duplicates, &$unmatched): void {
            foreach ($data['events'] as $event) {
                $timestamp = now()->parse($event['event_timestamp'], $timezone)->utc();
                $fingerprint = hash('sha256', json_encode([$device->id, $event['device_user_id'], $timestamp->toIso8601String(), $event['event_type'] ?? null, $event['payload'] ?? []], JSON_THROW_ON_ERROR));
                $identifier = $event['event_identifier'] ?? $fingerprint;
                if (RawAttendanceEvent::where('device_id', $device->id)->where('event_identifier', $identifier)->exists()) {
                    $duplicates++;

                    continue;
                }
                $rawEvent = RawAttendanceEvent::create([
                    'device_id' => $device->id, 'employee_id' => null, 'device_user_id' => $event['device_user_id'],
                    'event_identifier' => $identifier, 'event_timestamp' => $timestamp, 'received_timestamp' => now()->utc(),
                    'event_type' => $event['event_type'] ?? null, 'raw_payload' => ['received_record' => $event],
                    'source' => 'zkteco', 'processing_status' => 'pending',
                ]);
                ProcessAttendanceEvent::dispatch($rawEvent->id)->afterCommit();
                $hasMapping = DeviceEmployeeMapping::where('device_id', $device->id)->where('device_user_id', $event['device_user_id'])
                    ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $timestamp))
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $timestamp))->exists();
                if (! $hasMapping) {
                    $unmatched++;
                }
                $accepted++;
            }
            $device->forceFill(['integration_status' => 'connected', 'last_successful_sync_at' => now()])->save();
            $device->syncLogs()->create(['started_at' => now(), 'finished_at' => now(), 'status' => 'success', 'records_received' => $accepted, 'records_failed' => 0, 'message' => "Accepted {$accepted}; duplicates {$duplicates}. Employee matching is queued."]);
        });

        return response()->json(['accepted' => $accepted, 'duplicates' => $duplicates, 'unmatched' => $unmatched], 202);
    }
}
