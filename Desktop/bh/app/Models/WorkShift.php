<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'starts_at', 'ends_at', 'break_minutes', 'grace_period_minutes', 'days_of_week', 'is_active'])]
class WorkShift extends Model
{
    protected function casts(): array
    {
        return ['days_of_week' => 'array', 'is_active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }
}
