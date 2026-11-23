<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'first_in_at' => 'immutable_datetime', 'last_out_at' => 'immutable_datetime'];
    }
}
