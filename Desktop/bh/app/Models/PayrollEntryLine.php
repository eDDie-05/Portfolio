<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntryLine extends Model
{
    protected $fillable = ['payroll_entry_id', 'salary_structure_component_id', 'payroll_adjustment_id', 'name', 'line_type', 'calculation_method', 'calculation_base', 'rate_percent', 'amount', 'is_taxable', 'is_statutory'];

    protected function casts(): array
    {
        return ['calculation_base' => 'decimal:2', 'rate_percent' => 'decimal:4', 'amount' => 'decimal:2', 'is_taxable' => 'boolean', 'is_statutory' => 'boolean'];
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryStructureComponent::class, 'salary_structure_component_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class);
    }
}
