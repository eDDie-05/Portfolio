<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureComponent extends Model
{
    protected $fillable = [
        'salary_structure_id', 'code', 'name', 'component_type', 'calculation_method', 'value', 'is_taxable',
        'is_pensionable', 'is_statutory', 'is_recurring', 'effective_from', 'effective_to', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_taxable' => 'boolean',
            'is_pensionable' => 'boolean',
            'is_statutory' => 'boolean',
            'is_recurring' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }
}
