<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryStructure extends Model
{
    protected $fillable = ['created_by', 'name', 'country_code', 'currency', 'frequency', 'basic_salary', 'is_active'];

    protected function casts(): array
    {
        return ['basic_salary' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
