<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceAdapterRegistry;
use App\Models\AttendanceDevice;
use App\Models\Branch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceDeviceController extends Controller
{
    public function index(): View
    {
        $devices = AttendanceDevice::with('location')->orderBy('device_code')->get();

        return view('devices.index', [
            'devices' => $devices,
            'connectedCount' => $devices->where('integration_status', 'connected')->where('is_active', true)->count(),
            'verifiedCount' => $devices->whereNotNull('compatibility_verified_at')->where('is_active', true)->count(),
            'lastSuccessfulSync' => $devices->pluck('last_successful_sync_at')->filter()->sortDesc()->first(),
        ]);
    }

    public function create(): View
    {
        return view('devices.form', ['device' => new AttendanceDevice, 'branches' => Branch::where('is_active', true)->orderBy('name')->get(), 'editing' => false]);
    }

    public function edit(AttendanceDevice $device): View
    {
        return view('devices.form', ['device' => $device, 'branches' => Branch::where('is_active', true)->orderBy('name')->get(), 'editing' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['integration_status'] = 'awaiting_configuration';
        $data['is_active'] = true;
        $device = AttendanceDevice::create($data);
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'device.created', 'subject_type' => 'attendance_device', 'subject_id' => $device->id, 'details' => json_encode(['device_code' => $device->device_code]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('devices.index')->with('status', 'Device registered as awaiting configuration.');
    }

    public function update(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $before = $device->only(['device_code', 'branch_id', 'model', 'serial_number', 'firmware_version', 'ip_address', 'port', 'connection_type', 'adapter_key', 'is_active']);
        $data = $this->validated($request, $device->id);
        $data['integration_status'] = 'awaiting_configuration';
        $data['compatibility_verified_at'] = null;
        $data['compatibility_verified_by'] = null;
        $data['verification_notes'] = null;
        $data['api_token_hash'] = null;
        $data['is_active'] = $request->boolean('is_active', true);
        $device->update($data);
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'device.updated', 'subject_type' => 'attendance_device', 'subject_id' => $device->id, 'details' => json_encode(['before' => $before, 'after' => $device->fresh()->only(array_keys($before))]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('devices.index')->with('status', 'Device details saved. Compatibility still needs verification.');
    }

    public function destroy(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $device->update(['is_active' => false, 'api_token_hash' => null]);
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'device.deactivated', 'subject_type' => 'attendance_device', 'subject_id' => $device->id, 'details' => json_encode(['device_code' => $device->device_code]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('devices.index')->with('status', 'Device deactivated and its API credential revoked.');
    }

    public function verify(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $data = $request->validate(['confirmed' => ['accepted'], 'verification_notes' => ['required', 'string', 'min:20', 'max:3000']]);
        abort_unless($device->model && $device->serial_number && $device->firmware_version && $device->connection_type && $device->connection_type !== 'pending', 422, 'Complete the model, serial, firmware, and connection method first.');
        $device->forceFill(['compatibility_verified_at' => now(), 'compatibility_verified_by' => $request->user()->id, 'verification_notes' => $data['verification_notes'], 'integration_status' => 'configured'])->save();
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'device.compatibility_verified', 'subject_type' => 'attendance_device', 'subject_id' => $device->id, 'details' => json_encode(['notes' => $data['verification_notes']]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('devices.index')->with('status', 'Compatibility attestation recorded. Connectivity still needs to be confirmed.');
    }

    public function connectivity(AttendanceDevice $device, AttendanceAdapterRegistry $adapters): RedirectResponse
    {
        $started = now();
        $adapter = $device->compatibility_verified_at ? $adapters->for($device) : null;
        if (! $adapter) {
            $device->syncLogs()->create(['started_at' => $started, 'finished_at' => now(), 'status' => 'skipped', 'records_received' => 0, 'message' => 'No compatible verified protocol adapter is configured for this device.']);

            return back()->with('status', 'Connection check skipped. No verified protocol adapter is configured for this device.');
        }
        try {
            $result = $adapter->testConnection($device);
            $device->syncLogs()->create(['started_at' => $started, 'finished_at' => now(), 'status' => $result->reachable ? 'success' : 'failed', 'records_received' => 0, 'message' => $result->message]);
            if ($result->reachable) {
                $device->forceFill(['last_seen_at' => now()])->save();
            } else {
                DB::table('integration_error_logs')->insert(['source_type' => 'attendance_device', 'source_id' => $device->id, 'error_code' => 'connectivity_check_failed', 'message' => $result->message, 'context' => json_encode($result->details), 'created_at' => now(), 'updated_at' => now()]);
            }

            return back()->with($result->reachable ? 'status' : 'warning', $result->message);
        } catch (\Throwable $exception) {
            $device->syncLogs()->create(['started_at' => $started, 'finished_at' => now(), 'status' => 'failed', 'records_received' => 0, 'message' => 'Adapter error: '.$exception->getMessage()]);
            DB::table('integration_error_logs')->insert(['source_type' => 'attendance_device', 'source_id' => $device->id, 'error_code' => 'adapter_exception', 'message' => $exception->getMessage(), 'context' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);

            return back()->with('warning', 'Connectivity check failed. See integration error logs for details.');
        }
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'device_code' => ['required', 'string', 'max:80', 'unique:attendance_devices,device_code'.($ignoreId ? ','.$ignoreId : '')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)], 'manufacturer' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'], 'serial_number' => ['nullable', 'string', 'max:120', 'unique:attendance_devices,serial_number'.($ignoreId ? ','.$ignoreId : '')],
            'firmware_version' => ['nullable', 'string', 'max:120'], 'ip_address' => ['nullable', 'ip'], 'port' => ['nullable', 'integer', 'between:1,65535'],
            'connection_type' => ['nullable', 'in:pending,adms,sdk,tcp_ip,windows_service,csv_import'], 'adapter_key' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
