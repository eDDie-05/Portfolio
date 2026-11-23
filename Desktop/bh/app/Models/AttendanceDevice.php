<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDevice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_successful_sync_at' => 'immutable_datetime', 'compatibility_verified_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AttendanceSyncLog::class, 'device_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RawAttendanceEvent::class, 'device_id');
    }
}
