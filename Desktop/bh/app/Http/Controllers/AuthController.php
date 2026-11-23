<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        if (! $request->user()->is_active) {
            Auth::logout();

            return back()->withErrors(['email' => 'This account is inactive.']);
        }
        if (! in_array($request->user()->role, ['system_admin', 'hr_director', 'hr_manager', 'attendance_officer', 'payroll_officer', 'finance_officer', 'employee'], true)) {
            Auth::logout();

            return back()->withErrors(['email' => 'Your account does not have access to this workspace.']);
        }

        $fallbackRoute = match ($request->user()->role) {
            'employee' => route('my-attendance'),
            'payroll_officer', 'finance_officer' => route('payroll.index'),
            default => route('dashboard'),
        };
        if ($request->user()->must_change_password) {
            $fallbackRoute = route('account.edit');
        }

        return redirect()->intended($fallbackRoute);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
