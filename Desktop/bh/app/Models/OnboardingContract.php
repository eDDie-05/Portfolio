<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingContract extends Model
{
    protected $fillable = ['onboarding_case_id', 'version', 'issued_by', 'signed_document_id', 'original_path', 'original_filename', 'original_sha256', 'original_size', 'status', 'sent_at', 'signed_at', 'declined_at', 'response_note'];

    protected function casts(): array
    {
        return [
            'original_size' => 'integer',
            'version' => 'integer',
            'sent_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
        ];
    }

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class);
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'signed_document_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
