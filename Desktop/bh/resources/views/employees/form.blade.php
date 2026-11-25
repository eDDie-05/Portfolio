@extends('layouts.app', ['title' => $editing ? 'Edit employee' : 'Add employee'])
@section('crumb', 'People / ' . ($editing ? 'Edit employee' : 'Add employee'))
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div>
            <div class="eyebrow">PEOPLE / EMPLOYEE PROFILE</div>
            <h1>{{ $editing ? 'Update employee' : 'Add an employee' }}</h1>
            <p>Maintain identity, contact, and employment information for this employee.</p>
        </div>
        <a class="button button-light" href="{{ route('employees.index') }}">← &nbsp; Back to directory</a>
    </div>

    <form class="panel device-form" method="post" enctype="multipart/form-data" action="{{ $editing ? route('employees.update', $employee) : route('employees.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif
        <div class="form-section">
            <h2>Personal and contact details</h2>
            <p>Keep personal contact details current and limit access to authorized HR staff.</p>
            <div class="form-grid">
                <label>Full name<input name="name" required maxlength="255" value="{{ old('name', $employee->name) }}">@error('name')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Employee number <span class="muted">Optional for system accounts.</span><input name="employee_no" maxlength="80" value="{{ old('employee_no', $employee->employee_no) }}">@error('employee_no')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Work email<input type="email" name="email" required maxlength="255" value="{{ old('email', $employee->email) }}">@error('email')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Phone<input type="tel" name="phone" maxlength="60" value="{{ old('phone', $employee->phone) }}">@error('phone')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Gender<select name="gender"><option value="">Prefer not to say</option>@foreach(['male' => 'Male', 'female' => 'Female', 'non_binary' => 'Non-binary', 'prefer_not_to_say' => 'Prefer not to say', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('gender', $employee->gender) === $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $employee->date_of_birth?->format('Y-m-d')) }}">@error('date_of_birth')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label class="wide-field">Address<textarea name="address" rows="2" maxlength="3000">{{ old('address', $employee->address) }}</textarea>@error('address')<small class="form-error">{{ $message }}</small>@enderror</label>
            </div>
            <h3>Emergency contact</h3>
            <div class="form-grid">
                <label>Contact name<input name="emergency_contact_name" maxlength="255" value="{{ old('emergency_contact_name', $employee->emergency_contact_name) }}">@error('emergency_contact_name')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Contact phone<input type="tel" name="emergency_contact_phone" maxlength="60" value="{{ old('emergency_contact_phone', $employee->emergency_contact_phone) }}">@error('emergency_contact_phone')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Relationship<input name="emergency_contact_relationship" maxlength="80" value="{{ old('emergency_contact_relationship', $employee->emergency_contact_relationship) }}">@error('emergency_contact_relationship')<small class="form-error">{{ $message }}</small>@enderror</label>
            </div>
        </div>

        <div class="form-section">
            <h2>Employment details</h2>
            <p>Use approved department, branch, and reporting assignments. Dates do not apply statutory rules.</p>
            <div class="form-grid">
                @if(auth()->user()->role === 'hr_manager')
                    <label>Department<input value="{{ old('department', $employee->department ?? auth()->user()->department) }}" readonly><input type="hidden" name="department" value="{{ old('department', $employee->department ?? auth()->user()->department) }}"></label>
                @else
                    <label>Department<select name="department"><option value="">Unassigned</option>@foreach($departments as $department)<option value="{{ $department->name }}" @selected(old('department', $employee->department) === $department->name)>{{ $department->name }}</option>@endforeach</select>@error('department')<small class="form-error">{{ $message }}</small>@enderror</label>
                @endif
                @if(auth()->user()->role === 'hr_manager')
                    <label>Branch<input value="{{ $employee->branch?->name ?? auth()->user()->branch?->name ?? 'Unassigned' }}" readonly><input type="hidden" name="branch_id" value="{{ $employee->branch_id ?? auth()->user()->branch_id }}"></label>
                @else
                    <label>Branch<select name="branch_id"><option value="">Unassigned</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $employee->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<small class="form-error">{{ $message }}</small>@enderror</label>
                @endif
                @if(auth()->user()->role !== 'hr_manager')
                    <label>Reports to<select name="manager_id"><option value="">No manager assigned</option>@foreach($managers as $manager)<option value="{{ $manager->id }}" @selected(old('manager_id', $employee->manager_id) == $manager->id)>{{ $manager->name }}</option>@endforeach</select>@error('manager_id')<small class="form-error">{{ $message }}</small>@enderror</label>
                @else
                    <label>Reports to<input value="{{ auth()->user()->name }}" readonly></label>
                @endif
                <label>Job title<input name="job_title" maxlength="120" value="{{ old('job_title', $employee->job_title) }}">@error('job_title')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Employment type<select name="employment_type"><option value="">Not specified</option>@foreach(['permanent' => 'Permanent', 'fixed_term' => 'Fixed term', 'casual' => 'Casual', 'part_time' => 'Part time', 'intern' => 'Intern', 'consultant' => 'Consultant', 'other' => 'Other'] as $typeValue => $typeLabel)<option value="{{ $typeValue }}" @selected(old('employment_type', $employee->employment_type) === $typeValue)>{{ $typeLabel }}</option>@endforeach</select>@error('employment_type')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Joining date<input type="date" name="joined_on" value="{{ old('joined_on', $employee->joined_on?->format('Y-m-d')) }}">@error('joined_on')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Contract starts<input type="date" name="contract_starts_on" value="{{ old('contract_starts_on', $employee->contract_starts_on?->format('Y-m-d')) }}">@error('contract_starts_on')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Contract ends<input type="date" name="contract_ends_on" value="{{ old('contract_ends_on', $employee->contract_ends_on?->format('Y-m-d')) }}">@error('contract_ends_on')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Contract document <span class="muted">PDF, JPG or PNG, maximum 10 MB.</span><input type="file" name="contract_document" accept=".pdf,.jpg,.jpeg,.png"></label>
                <label>Performance score (0–100)<input type="number" name="performance_score" min="0" max="100" value="{{ old('performance_score', $employee->performance_score) }}"></label>
                <label>Satisfaction score (0–100)<input type="number" name="satisfaction_score" min="0" max="100" value="{{ old('satisfaction_score', $employee->satisfaction_score) }}"></label>
                @if($editing)
                    <label>Account status<select name="is_active"><option value="1" @selected(old('is_active', (int) $employee->is_active) == 1)>Active</option><option value="0" @selected(old('is_active', (int) $employee->is_active) == 0)>Inactive</option></select></label>
                    @if(auth()->user()->role === 'system_admin')
                        <label>System role<select name="role" required>@foreach(['employee' => 'Employee', 'hr_manager' => 'HR manager', 'hr_director' => 'HR director', 'attendance_officer' => 'Attendance officer', 'payroll_officer' => 'Payroll officer', 'finance_officer' => 'Finance officer', 'system_admin' => 'System administrator'] as $roleValue => $roleLabel)<option value="{{ $roleValue }}" @selected(old('role', $employee->role) === $roleValue)>{{ $roleLabel }}</option>@endforeach</select>@error('role')<small class="form-error">{{ $message }}</small>@enderror</label>
                        <label>Reset password <span class="muted">Leave blank to keep it. New passwords need 12+ characters, uppercase and lowercase letters, a number, and a symbol.</span><input type="password" name="password" minlength="12" autocomplete="new-password">@error('password')<small class="form-error">{{ $message }}</small>@enderror</label>
                        <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
                    @endif
                @else
                    @if(auth()->user()->role === 'system_admin')
                        <label>System role<select name="role" required>@foreach(['employee' => 'Employee', 'hr_manager' => 'HR manager', 'hr_director' => 'HR director', 'attendance_officer' => 'Attendance officer', 'payroll_officer' => 'Payroll officer', 'finance_officer' => 'Finance officer', 'system_admin' => 'System administrator'] as $roleValue => $roleLabel)<option value="{{ $roleValue }}" @selected(old('role', 'employee') === $roleValue)>{{ $roleLabel }}</option>@endforeach</select>@error('role')<small class="form-error">{{ $message }}</small>@enderror</label>
                    @endif
                    <label>Initial password <span class="muted">12+ characters with uppercase and lowercase letters, a number, and a symbol.</span><input type="password" name="password" minlength="12" required autocomplete="new-password">@error('password')<small class="form-error">{{ $message }}</small>@enderror</label>
                    <label>Confirm initial password<input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>
                @endif
            </div>
        </div>

        <div class="form-actions">
            <a class="button button-light" href="{{ route('employees.index') }}">Cancel</a>
            <button class="button button-primary" type="submit">{{ $editing ? 'Save changes' : 'Create employee' }}</button>
        </div>
    </form>
</div>
@endsection
