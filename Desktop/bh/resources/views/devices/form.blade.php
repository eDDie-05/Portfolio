@extends('layouts.app', ['title' => $editing ? 'Edit device' : 'Register device'])
@section('crumb', 'Devices / ' . ($editing ? 'Edit' : 'Register'))
@section('content')
<div class="page-wrap"><div class="page-heading"><div><div class="eyebrow">INTEGRATIONS / ATTENDANCE DEVICES</div><h1>{{ $editing ? 'Update device details' : 'Register an attendance device' }}</h1><p>Record verified hardware details. Saving a device does not activate an integration.</p></div><a class="button button-light" href="{{ route('devices.index') }}">← &nbsp; Back to devices</a></div>
    <div class="notice"><span class="notice-icon">i</span><div><strong>Do not guess the model or communication protocol</strong><span>Leave unknown fields blank until the device label, firmware screen, or manufacturer documentation confirms them.</span></div></div>
    <form class="panel device-form" method="post" action="{{ $editing ? route('devices.update', $device) : route('devices.store') }}">@csrf @if($editing) @method('PUT') @endif
        <div class="form-section"><h2>Device identity</h2><p>Use the code printed in your internal device register.</p><div class="form-grid">
            <label>Device code <input name="device_code" required maxlength="80" value="{{ old('device_code', $device->device_code) }}" placeholder="e.g. ZKT-003">@error('device_code')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Manufacturer <input name="manufacturer" required maxlength="120" value="{{ old('manufacturer', $device->manufacturer ?: 'ZKTeco') }}">@error('manufacturer')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Branch <select name="branch_id"><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $device->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Model number <input name="model" maxlength="120" value="{{ old('model', $device->model) }}" placeholder="Confirm from device label">@error('model')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Serial number <input name="serial_number" maxlength="120" value="{{ old('serial_number', $device->serial_number) }}">@error('serial_number')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Firmware version <input name="firmware_version" maxlength="120" value="{{ old('firmware_version', $device->firmware_version) }}"></label>
        </div></div>
        <div class="form-section"><h2>Connection details</h2><p>Only use connection settings verified for this exact model and firmware.</p><div class="form-grid">
            <label>Connection method <select name="connection_type"><option value="pending">Not confirmed</option>@foreach(['adms'=>'ADMS / Push','sdk'=>'Licensed SDK','tcp_ip'=>'Documented TCP/IP','windows_service'=>'Windows connector','csv_import'=>'File import'] as $value=>$label)<option value="{{ $value }}" @selected(old('connection_type', $device->connection_type) === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>IP address <input name="ip_address" value="{{ old('ip_address', $device->ip_address) }}" placeholder="Only if network connection is supported">@error('ip_address')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Port <input name="port" type="number" min="1" max="65535" value="{{ old('port', $device->port) }}" placeholder="Documented port only">@error('port')<small class="form-error">{{ $message }}</small>@enderror</label>
            <label>Adapter key <input name="adapter_key" maxlength="80" value="{{ old('adapter_key', $device->adapter_key) }}" placeholder="Verified adapter identifier"></label>
            @if($editing)<label>Device status <select name="is_active"><option value="1" @selected(old('is_active', (int)$device->is_active) == 1)>Active</option><option value="0" @selected(old('is_active', (int)$device->is_active) == 0)>Deactivated</option></select></label>@endif
            <label class="wide-field">Notes <textarea name="notes" rows="3" maxlength="3000">{{ old('notes', $device->notes) }}</textarea></label>
        </div></div><div class="form-actions"><a class="button button-light" href="{{ route('devices.index') }}">Cancel</a><button class="button button-primary" type="submit">{{ $editing ? 'Save device details' : 'Register device' }}</button></div>
    </form>
</div>
@endsection
