@extends('layouts.app', ['title' => 'Statutory payroll settings'])
@section('crumb', 'Finance / Payroll / Statutory settings')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div>
            <div class="eyebrow">PAYROLL / CONFIGURATION</div>
            <h1>Statutory rule versions</h1>
            <p>Configure verified rates for {{ ucfirst($jurisdiction) }} payroll. No statutory rates are preloaded or assumed.</p>
        </div>
        <a class="button button-light" href="{{ route('payroll.index') }}">Back to payroll</a>
    </div>
    @if($errors->any())<div class="flash warning" role="alert">{{ $errors->first() }}</div>@endif
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
    <section class="panel events-panel">
        <div class="panel-heading">
            <div>
                <h2>Add a verified statutory rule</h2>
                <p>Rules are effective-dated and versioned. Supply the official authority/reference used to verify every value. A changed rule creates a new version; payroll snapshots preserve the exact version used.</p>
            </div>
        </div>
        <div class="compliance-note">
            <strong>PAYE band format</strong>
            <span>Enter JSON with annual progressive bands, ordered by increasing limit, and use null for the final upper limit. Include a verified non_resident_rate. Do not copy example rates from this screen.</span>
        </div>
        <form class="device-form" method="post" action="{{ route('payroll.settings.statutory.store') }}">
            @csrf
            <div class="form-grid">
                <label>Statutory code
                    <select name="code" required>
                        @foreach($codes as $code)<option value="{{ $code }}" @selected(old('code') === $code)>{{ $code }}</option>@endforeach
                    </select>
                </label>
                <label>Jurisdiction
                    <select name="jurisdiction" required>
                        <option value="mainland" @selected(old('jurisdiction', $jurisdiction) === 'mainland')>Tanzania Mainland</option>
                        <option value="zanzibar" @selected(old('jurisdiction', $jurisdiction) === 'zanzibar')>Zanzibar</option>
                    </select>
                </label>
                <label>Effective from<input type="date" name="effective_from" value="{{ old('effective_from') }}" required></label>
                <label>Effective through (optional)<input type="date" name="effective_to" value="{{ old('effective_to') }}"></label>
                <label>Employee rate (%)<input type="number" name="employee_rate" min="0" max="100" step="0.0001" value="{{ old('employee_rate') }}"></label>
                <label>Employer rate (%)<input type="number" name="employer_rate" min="0" max="100" step="0.0001" value="{{ old('employer_rate') }}"></label>
                <label>Contribution calculation basis
                    <select name="calculation_basis">
                        <option value="">Not applicable to PAYE</option>
                        <option value="basic" @selected(old('calculation_basis') === 'basic')>Basic salary</option>
                        <option value="gross" @selected(old('calculation_basis') === 'gross')>Gross earnings</option>
                        <option value="taxable_pay" @selected(old('calculation_basis') === 'taxable_pay')>Taxable pay</option>
                    </select>
                </label>
                <label>Exempt threshold (optional)<input type="number" name="threshold" min="0" step="0.01" value="{{ old('threshold') }}"></label>
                <label>Maximum assessable base (optional)<input type="number" name="ceiling" min="0.01" step="0.01" value="{{ old('ceiling') }}"></label>
                <label class="form-field-wide">Rule parameters (JSON)
                    <textarea name="parameters" rows="5" spellcheck="false">{{ old('parameters') }}</textarea>
                    <small>For pension rules include <code>paye_deductible</code> as true or false. For SDL include verified <code>minimum_employee_count</code>. PAYE requires <code>bands</code> and <code>non_resident_rate</code>.</small>
                </label>
                <label class="form-field-wide">Official authority / source reference
                    <input name="authority_reference" maxlength="255" value="{{ old('authority_reference') }}" placeholder="Law, official notice, or verified employer instruction" required>
                </label>
            </div>
            <div class="form-actions"><button class="button button-primary">Save rule version</button></div>
        </form>
    </section>
    <section class="panel events-panel">
        <div class="panel-heading"><div><h2>Version history</h2><p>Inactive and superseded rules remain available for audit; a payroll period stores a frozen rule snapshot.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>CODE / VERSION</th><th>JURISDICTION</th><th>EFFECTIVE DATES</th><th>EMPLOYEE RATE</th><th>EMPLOYER RATE</th><th>BASIS / SOURCE</th><th>STATUS</th><th></th></tr></thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <td>{{ $rule->code }} · v{{ $rule->version }}</td>
                            <td>{{ ucfirst($rule->jurisdiction) }}</td>
                            <td>{{ $rule->effective_from->format('M j, Y') }} – {{ $rule->effective_to?->format('M j, Y') ?? 'Open' }}</td>
                            <td>{{ $rule->employee_rate ?? 'Progressive' }}{{ $rule->employee_rate !== null ? '%' : '' }}</td>
                            <td>{{ $rule->employer_rate ?? '—' }}{{ $rule->employer_rate !== null ? '%' : '' }}</td>
                            <td>{{ $rule->calculation_basis ? str_replace('_', ' ', $rule->calculation_basis) : 'Progressive bands' }}<small>{{ $rule->authority_reference }}</small></td>
                            <td>{{ $rule->is_active ? 'Active' : 'Inactive' }}</td>
                            <td>
                                @if($rule->is_active)
                                    <form method="post" action="{{ route('payroll.settings.statutory.deactivate', $rule) }}">
                                        @csrf
                                        <button class="button button-light button-small">Deactivate</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="table-empty">No statutory rules are configured. Configure verified rules before processing Tanzania payroll.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rules->links() }}
    </section>
    <section class="panel events-panel">
        <div class="panel-heading">
            <div>
                <h2>Approved overtime rules</h2>
                <p>Overtime multipliers, hourly divisors, tax treatment, and pension treatment are employer-configured. Verify each value against the applicable contract and policy before activation.</p>
            </div>
        </div>
        <form class="device-form" method="post" action="{{ route('payroll.settings.overtime.store') }}">
            @csrf
            <div class="form-grid">
                <label>Country code<input name="country_code" pattern="[A-Z]{2}" maxlength="2" value="{{ old('country_code', 'TZ') }}" required></label>
                <label>Currency<input name="currency" pattern="[A-Z]{3}" maxlength="3" value="{{ old('currency', 'TZS') }}" required></label>
                <label>Frequency<select name="frequency" required><option value="monthly">Monthly</option><option value="biweekly">Biweekly</option><option value="weekly">Weekly</option></select></label>
                <label>Overtime category<select name="overtime_type" required>@foreach($overtimeTypes as $type)<option value="{{ $type }}">{{ ucfirst($type) }}</option>@endforeach</select></label>
                <label>Hourly base<select name="calculation_basis" required><option value="basic">Basic salary</option><option value="gross">Gross earnings before overtime</option><option value="taxable_pay">Taxable earnings before overtime</option></select></label>
                <label>Hours per pay period<input type="number" name="hours_per_period" min="0.01" step="0.01" required></label>
                <label>Multiplier<input type="number" name="multiplier" min="0.001" step="0.001" required></label>
                <label>Taxable<select name="is_taxable" required><option value="1">Yes</option><option value="0">No</option></select></label>
                <label>Pensionable<select name="is_pensionable" required><option value="0">No</option><option value="1">Yes</option></select></label>
                <label>Effective from<input type="date" name="effective_from" required></label>
                <label>Effective through (optional)<input type="date" name="effective_to"></label>
                <label class="form-field-wide">Verified authority / employer policy reference<input name="authority_reference" maxlength="255" required></label>
            </div>
            <div class="form-actions"><button class="button button-primary">Save overtime rule</button></div>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>CODE / VERSION</th><th>PAY FREQUENCY</th><th>EFFECTIVE DATES</th><th>BASE / MULTIPLIER</th><th>TAX / PENSION</th><th>REFERENCE</th><th>STATUS</th><th></th></tr></thead>
                <tbody>
                    @forelse($overtimeRules as $rule)
                        <tr>
                            <td>{{ $rule->country_code }} {{ $rule->currency }} · {{ ucfirst($rule->overtime_type) }} · v{{ $rule->version }}</td>
                            <td>{{ ucfirst($rule->frequency) }}</td>
                            <td>{{ $rule->effective_from->format('M j, Y') }} – {{ $rule->effective_to?->format('M j, Y') ?? 'Open' }}</td>
                            <td>{{ str_replace('_', ' ', $rule->calculation_basis) }} · {{ $rule->hours_per_period }} hrs · ×{{ $rule->multiplier }}</td>
                            <td>{{ $rule->is_taxable ? 'Taxable' : 'Not taxable' }} · {{ $rule->is_pensionable ? 'Pensionable' : 'Not pensionable' }}</td>
                            <td>{{ $rule->authority_reference }}</td>
                            <td>{{ $rule->is_active ? 'Active' : 'Inactive' }}</td>
                            <td>@if($rule->is_active)<form method="post" action="{{ route('payroll.settings.overtime.deactivate', $rule) }}">@csrf<button class="button button-light button-small">Deactivate</button></form>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="table-empty">No overtime rules are configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $overtimeRules->links() }}
    </section>
</div>
@endsection
