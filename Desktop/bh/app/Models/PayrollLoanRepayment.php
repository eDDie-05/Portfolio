<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLoanRepayment extends Model
{
    protected $fillable = [
        'employee_payroll_loan_id', 'payroll_period_id', 'payroll_entry_id', 'amount', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollLoan::class, 'employee_payroll_loan_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }
}
