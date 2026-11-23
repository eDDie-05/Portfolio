<div class="app-navigation">
    <div class="nav-label">WORKSPACE</div>
    <nav>
        @if(auth()->user()->role === 'employee')<a class="nav-item {{ request()->routeIs('my-attendance') ? 'active' : '' }}" href="{{ route('my-attendance') }}"><span class="nav-icon">◫</span> My attendance</a>@elseif(in_array(auth()->user()->role, ['payroll_officer','finance_officer'], true))<a class="nav-item {{ request()->routeIs('payroll.*') ? 'active' : '' }}" href="{{ route('payroll.index') }}"><span class="nav-icon">¤</span> Payroll overview</a>@else<a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="nav-icon">◫</span> Overview</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','hr_manager','attendance_officer'], true))<a class="nav-item" href="{{ route('dashboard') }}#events"><span class="nav-icon">▦</span> Attendance events</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','hr_manager'], true))<a class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}" href="{{ route('employees.index') }}"><span class="nav-icon">♙</span> People</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director'], true))<a class="nav-item {{ request()->routeIs('recruitment.*') ? 'active' : '' }}" href="{{ route('recruitment.index') }}"><span class="nav-icon">▣</span> Recruitment</a>@endif
        <a class="nav-item {{ request()->routeIs('onboarding.mine') ? 'active' : '' }}" href="{{ route('onboarding.mine') }}"><span class="nav-icon">✓</span> My onboarding tasks</a>
        @if(auth()->user()->role === 'employee')<a class="nav-item {{ request()->routeIs('onboarding.documents.mine') ? 'active' : '' }}" href="{{ route('onboarding.documents.mine') }}"><span class="nav-icon">▤</span> Onboarding documents</a>@endif
        @if(auth()->user()->role === 'employee')<a class="nav-item {{ request()->routeIs('payroll.mine', 'payroll.payslip.mine') ? 'active' : '' }}" href="{{ route('payroll.mine') }}"><span class="nav-icon">¤</span> My payslips</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','hr_manager'], true))<a class="nav-item {{ request()->routeIs('onboarding.*') ? 'active' : '' }}" href="{{ route('onboarding.index') }}"><span class="nav-icon">✓</span> Onboarding</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','hr_manager'], true))<a class="nav-item {{ request()->routeIs('probation.*') ? 'active' : '' }}" href="{{ route('probation.index') }}"><span class="nav-icon">◷</span> Probation reviews</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','hr_manager'], true))<a class="nav-item {{ request()->routeIs('offboarding.*') ? 'active' : '' }}" href="{{ route('offboarding.index') }}"><span class="nav-icon">↗</span> Offboarding</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','payroll_officer','finance_officer'], true))<a class="nav-item {{ request()->routeIs('payroll.*') ? 'active' : '' }}" href="{{ route('payroll.index') }}"><span class="nav-icon">¤</span> Payroll</a>@endif
        @if(auth()->user()->role === 'employee')<a class="nav-item {{ request()->routeIs('offboarding.mine') ? 'active' : '' }}" href="{{ route('offboarding.mine') }}"><span class="nav-icon">↗</span> My offboarding</a>@endif
        @if(auth()->user()->role === 'employee')<a class="nav-item {{ request()->routeIs('leave.index') ? 'active' : '' }}" href="{{ route('leave.index') }}"><span class="nav-icon">◷</span> My leave</a>@endif
        <a class="nav-item {{ request()->routeIs('announcements.*') ? 'active' : '' }}" href="{{ route('announcements.index') }}"><span class="nav-icon">▣</span> Announcements</a>
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','hr_manager'], true))<a class="nav-item {{ request()->routeIs('leave.approvals') ? 'active' : '' }}" href="{{ route('leave.approvals') }}"><span class="nav-icon">✓</span> Leave approvals</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director'], true))<a class="nav-item {{ request()->routeIs('leave.settings') ? 'active' : '' }}" href="{{ route('leave.settings') }}"><span class="nav-icon">⚙</span> Leave settings</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director'], true))<a class="nav-item {{ request()->routeIs('departments.*') ? 'active' : '' }}" href="{{ route('departments.index') }}"><span class="nav-icon">▤</span> Departments</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director'], true))<a class="nav-item {{ request()->routeIs('branches.*') ? 'active' : '' }}" href="{{ route('branches.index') }}"><span class="nav-icon">⌖</span> Branches</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director'], true))<a class="nav-item {{ request()->routeIs('shifts.*') ? 'active' : '' }}" href="{{ route('shifts.index') }}"><span class="nav-icon">◷</span> Shifts & schedules</a>@endif
    </nav>
    <div class="nav-label nav-label-spaced">OPERATIONS</div>
    <nav>
        @if(in_array(auth()->user()->role, ['system_admin','attendance_officer'], true))<a class="nav-item {{ request()->routeIs('devices.*') ? 'active' : '' }}" href="{{ route('devices.index') }}"><span class="nav-icon">▤</span> Devices</a>@endif
        @if(in_array(auth()->user()->role, ['system_admin','hr_director','payroll_officer','finance_officer'], true))<a class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}"><span class="nav-icon">▧</span> Reports</a>@endif
        @if(auth()->user()->role === 'system_admin')<a class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}"><span class="nav-icon">⚙</span> System settings</a>@endif
    </nav>
</div>
