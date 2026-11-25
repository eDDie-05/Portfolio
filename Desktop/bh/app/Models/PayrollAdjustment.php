<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAdjustment extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_id', 'created_by', 'category', 'line_type', 'description', 'amount',
        'is_taxable', 'is_pensionable',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_taxable' => 'boolean', 'is_pensionable' => 'boolean'];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
