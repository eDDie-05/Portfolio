<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Attendance overview' }} · {{ $companyName ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @vite('resources/js/app.js')
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="{{ auth()->user()->role === 'employee' ? route('my-attendance') : (in_array(auth()->user()->role, ['payroll_officer','finance_officer'], true) ? route('payroll.index') : route('dashboard')) }}"><img src="{{ asset('images/TabLogoFit.svg') }}" alt="Bravado Group"><span>PEOPLE<br><b>& CULTURE</b></span></a>
        @include('layouts.partials.navigation')
        <div class="sidebar-bottom">@if(in_array(auth()->user()->role, ['system_admin','attendance_officer'], true))<div class="help-card"><div class="help-orb">?</div><strong>Need a hand?</strong><span>Open device connections and attendance imports.</span><a href="{{ route('devices.index') }}">View device setup <span>↗</span></a></div>@endif<div class="sidebar-user"><div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div><div><strong>{{ auth()->user()->name }}</strong><span>{{ str_replace('_', ' ', ucfirst(auth()->user()->role)) }}</span></div></div></div>
    </aside>
    <main class="main-area" id="main-content" tabindex="-1">
        <header class="topbar"><div class="crumb">{{ $companyName ?? config('app.name') }} <span>/</span> <b>@yield('crumb', 'Overview')</b></div><div class="top-actions"><details class="mobile-navigation"><summary>☰ Menu</summary><div class="mobile-navigation-panel">@include('layouts.partials.navigation')</div></details><span class="timezone"><span class="live-dot"></span> {{ $displayTimezone ?? config('attendance.display_timezone') }}</span><a class="logout-button" href="{{ route('account.edit') }}">My account</a><form class="topbar-logout" method="post" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit">Log out</button></form>@if(auth()->user()->profile_photo_path)<img class="avatar avatar-small" src="{{ route('account.profile-photo') }}" alt="{{ auth()->user()->name }}">@else<div class="avatar avatar-small">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>@endif</div></header>
        @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash warning" role="alert">{{ $errors->first() }}</div>@endif
        @yield('content')
        <footer class="footer"><span>© {{ now()->year }} {{ $companyName ?? config('app.name') }}</span><span>Confidential people and payroll information. Authorized business use only.</span><span>Tanzania HRMS</span></footer>
    </main>
</div>
</body>
</html>
