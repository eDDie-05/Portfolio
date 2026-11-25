<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Bravado HR Management System</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/design-system.css') }}?v={{ filemtime(public_path('css/design-system.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ filemtime(public_path('css/login.css')) }}">
    @vite('resources/js/app.js')
</head>
<body class="login-page">
    <section class="login-card">
        <div class="login-brand">
            <img src="{{ asset('images/TabLogoFit.svg') }}" alt="Bravado Group">
            <div>
                <span>BRAVADO GROUP</span>
                <strong>HR Management System</strong>
            </div>
        </div>
        <div class="eyebrow">SECURE WORKSPACE</div>
        <h1>Welcome back</h1>
        <p>Sign in to manage attendance and device integrations.</p>
        <form method="post" action="{{ route('login.attempt') }}">
            @csrf
            <label for="email">Work email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
            @error('email')
                <small class="form-error">{{ $message }}</small>
            @enderror
            <label for="password">Password</label>
            <div class="login-password-control">
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button id="password-toggle" class="login-password-toggle" type="button" aria-controls="password" aria-label="Show password" aria-pressed="false">Show</button>
            </div>
            @error('password')
                <small class="form-error">{{ $message }}</small>
            @enderror
            <button class="button button-primary login-button" type="submit">Sign in <span>→</span></button>
        </form>
        <div class="login-note">Accounts are provisioned by a system administrator.</div>
        <a class="login-careers-link" href="{{ route('careers.index') }}">View open career opportunities →</a>
    </section>
    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('password-toggle');

        passwordToggle.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'text';
            passwordInput.type = isVisible ? 'password' : 'text';
            passwordToggle.textContent = isVisible ? 'Show' : 'Hide';
            passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            passwordToggle.setAttribute('aria-pressed', String(!isVisible));
        });
    </script>
</body>
</html>
