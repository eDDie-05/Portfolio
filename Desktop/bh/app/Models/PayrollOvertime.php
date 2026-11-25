<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollOvertime extends Model
{
    protected $table = 'payroll_overtime_records';

    protected $fillable = [
        'employee_id', 'created_by', 'approved_by', 'payroll_period_id', 'rule_id', 'worked_on', 'overtime_type',
        'hours', 'hourly_rate', 'multiplier', 'calculated_amount', 'status', 'reason', 'review_note', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'worked_on' => 'immutable_date',
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'multiplier' => 'decimal:3',
            'calculated_amount' => 'decimal:2',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PayrollOvertimeRule::class, 'rule_id');
    }
}
