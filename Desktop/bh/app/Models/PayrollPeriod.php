<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'created_by', 'processed_by', 'approved_by', 'paid_by', 'country_code', 'currency', 'frequency', 'period_key',
        'starts_on', 'ends_on', 'pay_on', 'status', 'employee_count', 'gross_total', 'deductions_total', 'net_total', 'employer_contributions_total',
        'payment_reference', 'processed_at', 'approved_at', 'paid_at', 'closed_by', 'closed_at', 'statutory_rules_snapshot',
        'overtime_rules_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'pay_on' => 'immutable_date',
            'employee_count' => 'integer', 'gross_total' => 'decimal:2', 'deductions_total' => 'decimal:2',
            'net_total' => 'decimal:2', 'employer_contributions_total' => 'decimal:2', 'processed_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'statutory_rules_snapshot' => 'array',
            'overtime_rules_snapshot' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(PayrollOvertime::class);
    }

    public function loanRepayments(): HasMany
    {
        return $this->hasMany(PayrollLoanRepayment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }
}
