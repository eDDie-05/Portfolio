<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    protected $fillable = ['onboarding_case_id', 'assigned_to', 'title', 'description', 'due_on', 'status', 'completed_by', 'completed_at', 'completion_note'];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'completed_at' => 'immutable_datetime'];
    }

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
