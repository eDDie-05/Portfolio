<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RawAttendanceEvent extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            foreach (['device_id', 'device_user_id', 'event_identifier', 'event_timestamp', 'received_timestamp', 'event_type', 'raw_payload', 'source'] as $immutable) {
                if ($event->isDirty($immutable)) {
                    throw new LogicException("Raw attendance field {$immutable} is immutable.");
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Raw attendance events cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['event_timestamp' => 'immutable_datetime', 'received_timestamp' => 'immutable_datetime', 'raw_payload' => 'array'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
