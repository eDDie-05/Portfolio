<?php

namespace App\Attendance\ValueObjects;

final readonly class AdapterHealth
{
    public function __construct(public bool $reachable, public string $message, public array $details = []) {}
}
