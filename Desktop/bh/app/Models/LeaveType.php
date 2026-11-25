<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = ['name', 'description', 'deducts_balance', 'is_active', 'payroll_treatment', 'payroll_paid_fraction'];

    protected function casts(): array
    {
        return [
            'deducts_balance' => 'boolean',
            'is_active' => 'boolean',
            'payroll_paid_fraction' => 'decimal:4',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
