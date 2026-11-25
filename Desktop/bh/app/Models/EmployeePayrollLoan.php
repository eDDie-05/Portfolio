<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePayrollLoan extends Model
{
    protected $fillable = [
        'employee_id', 'requested_by', 'approved_by', 'disbursed_by', 'loan_type', 'currency', 'principal_amount', 'total_repayable',
        'installment_amount', 'repayment_starts_on', 'terms_reference', 'disbursement_reference', 'disbursed_at',
        'reason', 'review_note', 'status', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'total_repayable' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'repayment_starts_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'disbursed_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(PayrollLoanRepayment::class);
    }

    public function remainingBalance(): string
    {
        $repaidOrScheduled = '0.00';
        foreach ($this->repayments()->whereIn('status', ['scheduled', 'paid'])->get(['amount']) as $repayment) {
            $repaidOrScheduled = bcadd($repaidOrScheduled, $repayment->amount, 2);
        }

        return bccomp($this->total_repayable, $repaidOrScheduled, 2) > 0
            ? bcsub($this->total_repayable, $repaidOrScheduled, 2)
            : '0.00';
    }
}
