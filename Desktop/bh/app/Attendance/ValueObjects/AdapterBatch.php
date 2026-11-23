<?php

namespace App\Attendance\ValueObjects;

final readonly class AdapterBatch
{
    /** @param list<array{device_user_id:string,event_timestamp:string,event_identifier?:?string,event_type?:?string,payload?:array}> $events */
    public function __construct(public array $events, public ?string $nextCursor = null, public bool $hasMore = false) {}
}
