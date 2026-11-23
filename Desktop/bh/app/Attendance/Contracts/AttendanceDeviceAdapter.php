<?php

namespace App\Attendance\Contracts;

use App\Attendance\ValueObjects\AdapterBatch;
use App\Attendance\ValueObjects\AdapterHealth;
use App\Models\AttendanceDevice;

interface AttendanceDeviceAdapter
{
    public function supports(AttendanceDevice $device): bool;

    public function testConnection(AttendanceDevice $device): AdapterHealth;

    public function retrieveAttendanceEvents(AttendanceDevice $device, ?string $cursor): AdapterBatch;
}
