<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentOffer extends Model
{
    protected $fillable = [
        'job_application_id', 'issued_by', 'letter_path', 'letter_filename', 'letter_sha256',
        'token_hash', 'expires_at', 'status', 'responded_at', 'response_note',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
