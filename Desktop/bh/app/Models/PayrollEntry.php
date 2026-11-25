<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEntry extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_id', 'salary_structure_id', 'employee_number_snapshot', 'employee_name_snapshot',
        'department_snapshot', 'job_title_snapshot', 'tax_identifier_snapshot', 'tax_residency_snapshot', 'pension_fund_snapshot',
        'currency', 'basic_amount', 'gross_amount', 'deductions_amount',
        'benefits_amount', 'net_amount', 'employer_contributions_amount',
    ];

    protected function casts(): array
    {
        return ['basic_amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'deductions_amount' => 'decimal:2', 'benefits_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'employer_contributions_amount' => 'decimal:2'];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }
}
