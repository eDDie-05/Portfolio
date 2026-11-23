<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingClearanceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'offboarding_case_id', 'assigned_to', 'completed_by', 'title', 'department', 'instructions',
        'due_on', 'status', 'completed_at', 'completion_note',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'completed_at' => 'immutable_datetime'];
    }

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
