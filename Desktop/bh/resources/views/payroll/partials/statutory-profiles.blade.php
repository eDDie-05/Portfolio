<section class="panel events-panel statutory-profiles">
    <div class="panel-heading">
        <div>
            <h2>Tanzania employee statutory profiles</h2>
            <p>Set each employee’s confirmed fund membership, residency, and HESLB billing status before processing payroll.</p>
        </div>
    </div>
    <div class="compliance-note">
        <strong>Mainland automation</strong>
        <span>New TZS payrolls calculate resident or non-resident PAYE, employee pension contributions, enrolled NHIF, confirmed HESLB, and employer WCF. SDL is added for employers with 10 or more active employees unless a confirmed exemption is recorded. Choose the correct region in System settings; Zanzibar rules are not calculated using Mainland PAYE.</span>
    </div>
    <div class="interview-list">
        @forelse($employees as $employee)
            @php
                $profile = $employee->payroll_profile ?? [];
                $pensionFund = old('pension_fund', $profile['pension_fund'] ?? 'nssf');
            @endphp
            <details class="statutory-profile-row">
                <summary><span><strong>{{ $employee->name }}</strong><small>{{ $employee->employee_no }}</small></span><span class="profile-summary">{{ strtoupper($pensionFund) }} · {{ ($profile['nhif_enrolled'] ?? true) ? 'NHIF enrolled' : 'NHIF not enrolled' }} · {{ ($profile['heslb_billed'] ?? false) ? 'HESLB billed' : 'No HESLB billing' }}</span></summary>
                <form class="device-form" method="post" action="{{ route('payroll.employees.statutory-profile', $employee) }}">
                    @csrf
                    @method('put')
                    <div class="form-grid">
                        <label>PAYE residency<select name="tax_residency" required><option value="resident" @selected(old('tax_residency', $profile['tax_residency'] ?? 'resident') === 'resident')>Resident</option><option value="non_resident" @selected(old('tax_residency', $profile['tax_residency'] ?? 'resident') === 'non_resident')>Non-resident</option></select></label>
                        <label>Retirement scheme<select name="pension_fund" required><option value="nssf" @selected($pensionFund === 'nssf')>NSSF</option><option value="psssf" @selected($pensionFund === 'psssf')>PSSSF</option><option value="none" @selected($pensionFund === 'none')>No automatic pension deduction</option></select></label>
                        <label>Employee pension rate %<input type="number" name="pension_employee_rate" min="0" max="10" step="0.01" value="{{ old('pension_employee_rate', $profile['pension_employee_rate'] ?? ($pensionFund === 'psssf' ? '5' : ($pensionFund === 'none' ? '0' : '10'))) }}" required></label>
                        <label>Employer pension rate %<input type="number" name="pension_employer_rate" min="0" max="20" step="0.01" value="{{ old('pension_employer_rate', $profile['pension_employer_rate'] ?? ($pensionFund === 'psssf' ? '15' : ($pensionFund === 'none' ? '0' : '10'))) }}" required></label>
                    </div>
                    <div class="profile-checks"><label><input type="checkbox" name="nhif_enrolled" value="1" @checked(old('nhif_enrolled', $profile['nhif_enrolled'] ?? true))> NHIF member (3% employee and 3% employer)</label><label><input type="checkbox" name="heslb_billed" value="1" @checked(old('heslb_billed', $profile['heslb_billed'] ?? false))> HESLB confirmed this employee is billed (15% of basic salary)</label></div>
                    <div class="form-actions"><button class="button button-light button-small">Save statutory profile</button></div>
                </form>
            </details>
        @empty
            <div class="table-empty"><strong>No active employees</strong><span>Add employees before configuring statutory payroll profiles.</span></div>
        @endforelse
    </div>
    <div class="form-actions">{{ $employees->links() }}</div>
</section>
