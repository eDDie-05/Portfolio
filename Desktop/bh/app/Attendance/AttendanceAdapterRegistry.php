<?php

namespace App\Attendance;

use App\Attendance\Contracts\AttendanceDeviceAdapter;
use App\Models\AttendanceDevice;
use LogicException;

class AttendanceAdapterRegistry
{
    public function for(AttendanceDevice $device): ?AttendanceDeviceAdapter
    {
        if (! $device->adapter_key) {
            return null;
        }
        $class = config('attendance.adapters.'.$device->adapter_key);
        if (! $class) {
            return null;
        }
        if (! class_exists($class) || ! is_subclass_of($class, AttendanceDeviceAdapter::class)) {
            throw new LogicException('Configured attendance adapter must implement AttendanceDeviceAdapter.');
        }
        $adapter = app($class);

        return $adapter->supports($device) ? $adapter : null;
    }
}
