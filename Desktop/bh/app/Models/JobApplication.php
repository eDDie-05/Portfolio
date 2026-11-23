<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobApplication extends Model
{
    protected $fillable = [
        'job_vacancy_id', 'applicant_name', 'email', 'phone', 'cover_letter', 'resume_path',
        'resume_filename', 'resume_mime_type', 'resume_size', 'resume_sha256', 'stage', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return ['resume_size' => 'integer'];
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(JobVacancy::class, 'job_vacancy_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(RecruitmentInterview::class);
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(JobApplicationStageChange::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RecruitmentOffer::class);
    }

    public function onboardingCase(): HasOne
    {
        return $this->hasOne(OnboardingCase::class);
    }
}
