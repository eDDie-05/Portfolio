<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollOvertimeRule extends Model
{
    protected $fillable = [
        'created_by', 'country_code', 'currency', 'frequency', 'overtime_type', 'version', 'calculation_basis',
        'hours_per_period', 'multiplier', 'is_taxable', 'is_pensionable', 'effective_from', 'effective_to',
        'authority_reference', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hours_per_period' => 'decimal:2',
            'multiplier' => 'decimal:3',
            'is_taxable' => 'boolean',
            'is_pensionable' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(PayrollOvertime::class, 'rule_id');
    }
}
