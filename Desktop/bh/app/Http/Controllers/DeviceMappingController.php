<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\DeviceEmployeeMapping;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceMappingController extends Controller
{
    public function index(AttendanceDevice $device): View
    {
        $device->loadMissing('location');
        $this->authorizeDeviceScope($device);
        $mappings = DeviceEmployeeMapping::with('employee')->where('device_id', $device->id);

        if (auth()->user()->role === 'hr_manager') {
            $mappings->whereHas('employee', fn ($query) => $query->where('manager_id', auth()->id()));
        }

        return view('devices.mappings', [
            'device' => $device,
            'displayTimezone' => $device->location?->timezone ?? config('attendance.display_timezone'),
            'mappings' => $mappings->orderByDesc('valid_from')->paginate(30),
            'employees' => $this->accessibleEmployees($device->branch_id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $device->loadMissing('location');
        $this->authorizeDeviceScope($device);
        $data = $request->validate(['device_user_id' => ['required', 'string', 'max:120'], 'employee_id' => ['required', 'exists:users,id'], 'valid_from' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from']]);
        $employee = $this->accessibleEmployees($device->branch_id)->whereKey($data['employee_id'])->where('is_active', true)->firstOrFail();
        $timezone = $device->location?->timezone ?? config('attendance.display_timezone');
        $from = now()->parse($data['valid_from'], $timezone)->utc();
        $until = ! empty($data['valid_until']) ? now()->parse($data['valid_until'], $timezone)->utc() : null;

        DB::transaction(function () use ($device, $employee, $data, $from, $until, $request): void {
            $overlaps = DeviceEmployeeMapping::where('device_id', $device->id)->where('device_user_id', $data['device_user_id'])
                ->where(function ($q) use ($from) {
                    $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from);
                })
                ->where(function ($q) use ($until) {
                    if ($until) {
                        $q->where('valid_from', '<=', $until);
                    }
                })
                ->lockForUpdate()->get();
            if ($request->user()->role === 'hr_manager') {
                abort_unless($overlaps->every(fn (DeviceEmployeeMapping $mapping): bool => $mapping->employee()->where('manager_id', $request->user()->id)->exists()), 403);
            }

            foreach ($overlaps as $existing) {
                if ((int) $existing->employee_id === (int) $employee->id && $existing->valid_from?->equalTo($from)) {
                    return;
                }
                if ($existing->valid_from && $existing->valid_from->lt($from)) {
                    $existing->update(['valid_until' => $from->copy()->subSecond()]);
                } else {
                    abort(422, 'This device user ID already has a mapping during the requested period. Adjust the dates to avoid overlapping assignments.');
                }
            }
            $mapping = DeviceEmployeeMapping::create(['device_id' => $device->id, 'device_user_id' => $data['device_user_id'], 'employee_id' => $employee->id, 'valid_from' => $from, 'valid_until' => $until]);
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'device_employee_mapping.created', 'subject_type' => 'device_employee_mapping', 'subject_id' => $mapping->id, 'details' => json_encode(['device_code' => $device->device_code, 'device_user_id' => $data['device_user_id'], 'employee_no' => $employee->employee_no]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Employee mapping saved with its effective dates.');
    }

    public function end(Request $request, AttendanceDevice $device, DeviceEmployeeMapping $mapping): RedirectResponse
    {
        $this->authorizeDeviceScope($device);
        abort_unless($mapping->device_id === $device->id, 404);
        if (auth()->user()->role === 'hr_manager') {
            abort_unless($mapping->employee()->where('manager_id', auth()->id())->exists(), 403);
        }
        if (! $mapping->valid_until) {
            $mapping->update(['valid_until' => now()]);
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'device_employee_mapping.ended', 'subject_type' => 'device_employee_mapping', 'subject_id' => $mapping->id, 'details' => json_encode(['device_user_id' => $mapping->device_user_id]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('status', 'Mapping history retained and marked as ended.');
    }

    private function authorizeDeviceScope(AttendanceDevice $device): void
    {
        if (auth()->user()->role !== 'hr_manager') {
            return;
        }

        abort_unless($device->branch_id !== null && $this->accessibleEmployees()->where('branch_id', $device->branch_id)->exists(), 403);
    }

    private function accessibleEmployees(?int $branchId = null): Builder
    {
        return User::query()
            ->whereNotNull('employee_no')
            ->when(auth()->user()->role === 'hr_manager', fn ($query) => $query->where('manager_id', auth()->id()))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));
    }
}
