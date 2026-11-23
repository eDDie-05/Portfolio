<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingDocumentRequest extends Model
{
    protected $fillable = ['onboarding_case_id', 'requested_by', 'employee_document_id', 'category', 'instructions', 'due_on', 'status', 'submitted_at'];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'submitted_at' => 'immutable_datetime'];
    }

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }
}
