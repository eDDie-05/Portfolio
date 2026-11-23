<?php

namespace App\Jobs;

use App\Models\DeviceEmployeeMapping;
use App\Models\RawAttendanceEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessAttendanceEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 180];

    public function __construct(public readonly int $eventId) {}

    public function handle(): void
    {
        $event = RawAttendanceEvent::find($this->eventId);
        if (! $event || $event->processing_status !== 'pending') {
            return;
        }
        $mapping = DeviceEmployeeMapping::where('device_id', $event->device_id)->where('device_user_id', $event->device_user_id)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $event->event_timestamp))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $event->event_timestamp))->first();

        $event->forceFill([
            'employee_id' => $mapping?->employee_id,
            'processing_status' => $mapping ? 'mapped' : 'unmatched',
            'validation_message' => $mapping ? null : 'No active employee mapping exists for this device user.',
        ])->save();
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('integration_error_logs')->insert(['source_type' => 'raw_attendance_event', 'source_id' => $this->eventId, 'error_code' => 'event_processing_failed', 'message' => $exception?->getMessage() ?? 'Queue processing failed.', 'context' => json_encode(['event_id' => $this->eventId]), 'created_at' => now(), 'updated_at' => now()]);
        RawAttendanceEvent::whereKey($this->eventId)->update(['processing_status' => 'failed', 'validation_message' => 'Processing failed after retry attempts. See integration error log.']);
    }
}
