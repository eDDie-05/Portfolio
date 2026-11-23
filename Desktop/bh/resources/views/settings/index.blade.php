@extends('layouts.app', ['title' => 'System settings'])
@section('crumb', 'Administration / System settings')
@section('content')
<div class="page-wrap">
    <div class="page-heading"><div><div class="eyebrow">ADMINISTRATION</div><h1>System settings</h1><p>Manage the organization identity, local time display, financial year, and payroll defaults used by new records.</p></div></div>
    <section class="panel events-panel">
        <div class="panel-heading"><div><h2>Organization and regional defaults</h2><p>Changes are audited and take effect on the next web request.</p></div></div>
        <form class="device-form" method="post" action="{{ route('settings.update') }}">
            @csrf
            @method('put')
            <div class="form-grid">
                <label>Organization name<input name="company_name" maxlength="160" value="{{ old('company_name', $settings->get('company_name', config('app.name'))) }}" required></label>
                <label>Display timezone<select name="display_timezone" required>@foreach($timezones as $timezone)<option value="{{ $timezone }}" @selected(old('display_timezone', $settings->get('display_timezone', config('attendance.display_timezone'))) === $timezone)>{{ $timezone }}</option>@endforeach</select></label>
                <label>Financial year starts in<select name="fiscal_year_start_month" required>@foreach(range(1, 12) as $month)<option value="{{ $month }}" @selected((int) old('fiscal_year_start_month', $settings->get('fiscal_year_start_month', 1)) === $month)>{{ \Carbon\CarbonImmutable::create()->month($month)->format('F') }}</option>@endforeach</select></label>
                <label>Default country code<input name="default_country_code" maxlength="2" pattern="[A-Z]{2}" value="{{ old('default_country_code', $settings->get('default_country_code', 'TZ')) }}" required></label>
                <label>Default currency<input name="default_currency" maxlength="3" pattern="[A-Z]{3}" value="{{ old('default_currency', $settings->get('default_currency', 'TZS')) }}" required></label>
                <label>Tanzania payroll region<select name="tanzania_payroll_jurisdiction" required><option value="mainland" @selected(old('tanzania_payroll_jurisdiction', $settings->get('tanzania_payroll_jurisdiction', 'mainland')) === 'mainland')>Tanzania Mainland</option><option value="zanzibar" @selected(old('tanzania_payroll_jurisdiction', $settings->get('tanzania_payroll_jurisdiction')) === 'zanzibar')>Zanzibar</option></select><small class="field-hint">Mainland and Zanzibar use different payroll rules.</small></label>
                <label>Skills Development Levy<select name="sdl_exempt" required><option value="0" @selected(old('sdl_exempt', $settings->get('sdl_exempt', '0')) === '0')>No confirmed exemption</option><option value="1" @selected(old('sdl_exempt', $settings->get('sdl_exempt', '0')) === '1')>Employer has a confirmed exemption</option></select><small class="field-hint">Mainland SDL is applied to employers with 10+ employees unless exempt.</small></label>
            </div>
            <div class="form-actions"><button class="button button-primary">Save system settings</button></div>
        </form>
    </section>
    <section class="panel events-panel"><div class="panel-heading"><div><h2>Connections and security</h2><p>Connected systems remain evidence-based. Device auto-sync requires a compatible model, firmware, network path, and configured adapter.</p></div></div><div class="interview-list"><a class="button button-light" href="{{ route('devices.index') }}">Open device connections</a><a class="button button-light" href="{{ route('reports.index') }}">Open reports</a><a class="button button-light" href="{{ route('payroll.index') }}">Open payroll setup</a></div></section>
</div>
@endsection
