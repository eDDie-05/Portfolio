<div>
    <!-- Waste no more time arguing what a good man should be, be one. - Marcus Aurelius -->
</div>
@extends('layouts.app', ['title' => 'My account'])
@section('crumb', 'My account')
@section('content')
<div class="page-wrap">
    <div class="page-heading">
        <div><div class="eyebrow">ACCOUNT / SECURITY</div><h1>My account</h1><p>Update your profile details and password.</p></div>
    </div>
    <form class="panel device-form" method="post" enctype="multipart/form-data" action="{{ route('account.update') }}">
        @csrf
        @method('PUT')
        <div class="form-section">
            <h2>Sign-in information</h2>
            <div class="form-grid">
                <label>Full name<input name="name" required maxlength="255" value="{{ old('name', $user->name) }}" autocomplete="name">@error('name')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Login email<input type="email" name="email" required maxlength="255" value="{{ old('email', $user->email) }}" autocomplete="username">@error('email')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Current password (only needed to change password)<input type="password" name="current_password" autocomplete="current-password">@error('current_password')<small class="form-error">{{ $message }}</small>@enderror</label>
            </div>
        </div>
        <div class="form-section">
            <h2>Change password</h2>
            <p>Leave both new password fields blank to keep your current password. New passwords need 12+ characters, uppercase and lowercase letters, a number, and a symbol.</p>
            <div class="form-grid">
                <label>New password<input type="password" name="password" minlength="12" autocomplete="new-password">@error('password')<small class="form-error">{{ $message }}</small>@enderror</label>
                <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
            </div>
        </div>
        <div class="form-actions"><a class="button button-light" href="{{ url()->previous() }}">Cancel</a><button class="button button-primary" type="submit">Save account changes</button></div>
    </form>
</div>
@endsection
