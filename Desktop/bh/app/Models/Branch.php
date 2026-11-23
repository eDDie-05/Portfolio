<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $guarded = ['id'];

    public function devices(): HasMany
    {
        return $this->hasMany(AttendanceDevice::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function registeredComputers(): HasMany
    {
        return $this->hasMany(RegisteredComputer::class);
    }
}
