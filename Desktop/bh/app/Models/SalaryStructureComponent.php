<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureComponent extends Model
{
    protected $fillable = ['salary_structure_id', 'name', 'component_type', 'calculation_method', 'value', 'is_taxable', 'is_statutory', 'is_active'];

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'is_taxable' => 'boolean', 'is_statutory' => 'boolean', 'is_active' => 'boolean'];
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }
}
