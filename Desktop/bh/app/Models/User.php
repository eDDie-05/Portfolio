<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden([
    'password', 'remember_token', 'tax_identifier', 'pension_member_number', 'nhif_member_number',
    'heslb_account_number', 'bank_name', 'bank_branch', 'bank_account_number',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organizationalDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    public function offboardingCases(): HasMany
    {
        return $this->hasMany(OffboardingCase::class, 'employee_id');
    }

    public function salaryAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryAssignment::class, 'employee_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'immutable_date',
            'joined_on' => 'immutable_date',
            'contract_starts_on' => 'immutable_date',
            'contract_ends_on' => 'immutable_date',
            'payroll_profile' => 'array',
            'tax_identifier' => 'encrypted',
            'pension_member_number' => 'encrypted',
            'nhif_member_number' => 'encrypted',
            'heslb_account_number' => 'encrypted',
            'bank_account_number' => 'encrypted',
        ];
    }
}
