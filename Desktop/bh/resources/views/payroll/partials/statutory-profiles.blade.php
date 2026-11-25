<section class="panel events-panel statutory-profiles">
    <div class="panel-heading">
        <div>
            <h2>Tanzania employee statutory profiles</h2>
            <p>Record confirmed statutory membership and payment details. Contribution rates are managed separately as effective-dated employer rules.</p>
        </div>
    </div>
    <div class="compliance-note">
        <strong>Payroll data handling</strong>
        <span>Only configured, effective-dated statutory rule versions are used. Confirm membership and identifiers from authorized records. Tax and bank identifiers are not written to the audit log.</span>
    </div>
    <div class="interview-list">
        @forelse($employees as $employee)
            @php
                $profile = $employee->payroll_profile ?? [];
                $pensionFund = old('pension_fund', $profile['pension_fund'] ?? 'none');
                $payrollStatus = old('payroll_status', $profile['payroll_status'] ?? 'active');
            @endphp
            <details class="statutory-profile-row">
                <summary><span><strong>{{ $employee->name }}</strong><small>{{ $employee->employee_no }}</small></span><span class="profile-summary">{{ $payrollStatus === 'excluded' ? 'Excluded from payroll' : 'Included in payroll' }} · {{ strtoupper($pensionFund) }} · {{ ($profile['nhif_enrolled'] ?? false) ? 'NHIF enrolled' : 'NHIF not confirmed' }} · {{ ($profile['heslb_billed'] ?? false) ? 'HESLB billed' : 'No HESLB billing' }}</span></summary>
                <form class="device-form" method="post" action="{{ route('payroll.employees.statutory-profile', $employee) }}">
                    @csrf
                    @method('put')
                    <div class="form-grid">
                        <label>Payroll participation<select name="payroll_status" required><option value="active" @selected($payrollStatus === 'active')>Included in payroll</option><option value="excluded" @selected($payrollStatus === 'excluded')>Explicitly excluded</option></select></label>
                        <label>PAYE residency<select name="tax_residency" required><option value="resident" @selected(old('tax_residency', $profile['tax_residency'] ?? 'resident') === 'resident')>Resident</option><option value="non_resident" @selected(old('tax_residency', $profile['tax_residency'] ?? 'resident') === 'non_resident')>Non-resident</option></select></label>
                        <label>Retirement scheme<select name="pension_fund" required><option value="nssf" @selected($pensionFund === 'nssf')>NSSF</option><option value="psssf" @selected($pensionFund === 'psssf')>PSSSF</option><option value="none" @selected($pensionFund === 'none')>No pension contribution</option></select></label>
                        <label>TIN / tax identifier<input name="tax_identifier" maxlength="40" value="{{ old('tax_identifier', $employee->tax_identifier) }}"></label>
                        <label>Pension membership number<input name="pension_member_number" maxlength="60" value="{{ old('pension_member_number', $employee->pension_member_number) }}"></label>
                        <label>NHIF membership number<input name="nhif_member_number" maxlength="60" value="{{ old('nhif_member_number', $employee->nhif_member_number) }}"></label>
                        <label>HESLB account number<input name="heslb_account_number" maxlength="60" value="{{ old('heslb_account_number', $employee->heslb_account_number) }}"></label>
                        <label>Bank name<input name="bank_name" maxlength="120" value="{{ old('bank_name', $employee->bank_name) }}"></label>
                        <label>Bank branch<input name="bank_branch" maxlength="120" value="{{ old('bank_branch', $employee->bank_branch) }}"></label>
                        <label>Bank account number<input name="bank_account_number" maxlength="80" value="{{ old('bank_account_number', $employee->bank_account_number) }}"></label>
                    </div>
                    <div class="profile-checks"><label><input type="checkbox" name="nhif_enrolled" value="1" @checked(old('nhif_enrolled', $profile['nhif_enrolled'] ?? false))> NHIF membership confirmed</label><label><input type="checkbox" name="heslb_billed" value="1" @checked(old('heslb_billed', $profile['heslb_billed'] ?? false))> HESLB billing confirmed</label></div>
                    <div class="form-actions"><button class="button button-light button-small">Save statutory profile</button></div>
                </form>
            </details>
        @empty
            <div class="table-empty"><strong>No active employees</strong><span>Add employees before configuring statutory payroll profiles.</span></div>
        @endforelse
    </div>
    <div class="form-actions">{{ $employees->links() }}</div>
</section>
