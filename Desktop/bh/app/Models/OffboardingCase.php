<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OffboardingCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id', 'owner_id', 'created_by', 'completed_by', 'reason', 'reason_details', 'notice_received_on',
        'last_working_day', 'status', 'exit_interview_status', 'exit_interview_at', 'exit_interview_note',
        'final_payroll_status', 'final_payroll_note', 'account_deactivated_at', 'completed_at', 'cancelled_at', 'cancellation_note',
    ];

    protected function casts(): array
    {
        return [
            'notice_received_on' => 'immutable_date',
            'last_working_day' => 'immutable_date',
            'exit_interview_at' => 'immutable_datetime',
            'account_deactivated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function clearances(): HasMany
    {
        return $this->hasMany(OffboardingClearanceItem::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
