<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['event_timestamp' => 'immutable_datetime', 'raw_payload' => 'array'];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(RegisteredComputer::class, 'computer_id');
    }
}
