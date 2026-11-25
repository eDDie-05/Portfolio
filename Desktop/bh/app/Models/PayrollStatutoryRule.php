<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollStatutoryRule extends Model
{
    protected $fillable = [
        'created_by', 'code', 'jurisdiction', 'version', 'effective_from', 'effective_to', 'employee_rate', 'employer_rate',
        'calculation_basis', 'threshold', 'ceiling', 'parameters', 'authority_reference', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'employee_rate' => 'decimal:4',
            'employer_rate' => 'decimal:4',
            'threshold' => 'decimal:2',
            'ceiling' => 'decimal:2',
            'parameters' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
