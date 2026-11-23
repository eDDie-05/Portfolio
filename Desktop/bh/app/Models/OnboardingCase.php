<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OnboardingCase extends Model
{
    protected $fillable = ['job_application_id', 'employee_id', 'assigned_to', 'created_by', 'start_on', 'status', 'completed_at'];

    protected function casts(): array
    {
        return ['start_on' => 'immutable_date', 'completed_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class);
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(OnboardingDocumentRequest::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(OnboardingContract::class)->latestOfMany();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(OnboardingContract::class)->latest('version');
    }
}
