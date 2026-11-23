<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    protected $fillable = [
        'employee_id', 'uploaded_by', 'category', 'original_filename', 'storage_path', 'mime_type',
        'file_size', 'sha256', 'expires_on', 'employee_visible', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'expires_on' => 'immutable_date',
            'employee_visible' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
