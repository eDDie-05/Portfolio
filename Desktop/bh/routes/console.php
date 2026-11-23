<?php

use App\Models\AttendanceDevice;
use App\Models\Branch;
use App\Models\ComputerUser;
use App\Models\RegisteredComputer;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hrms:create-admin', function () {
    $name = env('HRMS_INITIAL_ADMIN_NAME') ?: $this->ask('Administrator name');
    $email = env('HRMS_INITIAL_ADMIN_EMAIL') ?: $this->ask('Administrator work email');
    $password = env('HRMS_INITIAL_ADMIN_PASSWORD') ?: $this->secret('Password (minimum 12 characters)');
    if (strlen((string) $password) < 12 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email and a password of at least 12 characters.');

        return self::FAILURE;
    }
    if (User::where('email', $email)->exists()) {
        $this->info('An account with that email already exists; no changes were made.');

        return self::SUCCESS;
    }
    $user = new User;
    $user->forceFill(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => 'system_admin', 'email_verified_at' => now()])->save();
    $this->info('Administrator created. Sign in at /login.');

    return self::SUCCESS;
})->purpose('Create the first HRMS administrator account');

Artisan::command('hrms:rotate-device-token {deviceCode}', function (string $deviceCode) {
    $device = AttendanceDevice::where('device_code', $deviceCode)->first();
    if (! $device) {
        $this->error('Unknown device code.');

        return self::FAILURE;
    }
    if (! $device->compatibility_verified_at) {
        $this->error('Compatibility must be verified and recorded before issuing an API credential.');

        return self::FAILURE;
    }
    $token = bin2hex(random_bytes(32));
    $device->forceFill(['api_token_hash' => hash('sha256', $token)])->save();
    $this->info('Copy this token now; it will not be shown again:');
    $this->line($token);

    return self::SUCCESS;
})->purpose('Issue a new one-time API credential for a registered attendance device');

Artisan::command('hrms:rotate-computer-token {computerCode}', function (string $computerCode) {
    $computer = RegisteredComputer::where('computer_code', $computerCode)->first();
    if (! $computer) {
        $this->error('Unknown computer code.');

        return self::FAILURE;
    }
    $token = bin2hex(random_bytes(32));
    $computer->forceFill(['credential_hash' => hash('sha256', $token)])->save();
    $this->info('Copy this token now; it will not be shown again:');
    $this->line($token);

    return self::SUCCESS;
})->purpose('Issue a one-time API credential for a registered Windows computer');

Artisan::command('hrms:register-computer {computerCode}', function (string $computerCode) {
    if (RegisteredComputer::where('computer_code', $computerCode)->exists()) {
        $this->error('That computer code already exists.');

        return self::FAILURE;
    }
    $hostname = $this->ask('Windows hostname');
    $branchId = $this->ask('Branch ID (leave blank for unassigned)');
    if ($branchId !== '' && ! Branch::whereKey($branchId)->where('is_active', true)->exists()) {
        $this->error('Branch ID not found.');

        return self::FAILURE;
    }
    RegisteredComputer::create(['computer_code' => $computerCode, 'hostname' => $hostname, 'branch_id' => $branchId ?: null, 'is_active' => true]);
    $this->info('Computer registered. Issue a credential with hrms:rotate-computer-token.');

    return self::SUCCESS;
})->purpose('Register a Windows endpoint in the HRMS');

Artisan::command('hrms:deactivate-computer {computerCode}', function (string $computerCode) {
    $computer = RegisteredComputer::where('computer_code', $computerCode)->first();
    if (! $computer) {
        $this->error('Unknown computer code.');

        return self::FAILURE;
    }
    $computer->forceFill(['is_active' => false, 'credential_hash' => null])->save();
    $this->info('Computer deactivated and its credential revoked.');

    return self::SUCCESS;
})->purpose('Deactivate a Windows computer and revoke its API credential');

Artisan::command('hrms:activate-computer {computerCode}', function (string $computerCode) {
    $computer = RegisteredComputer::where('computer_code', $computerCode)->first();
    if (! $computer) {
        $this->error('Unknown computer code.');

        return self::FAILURE;
    }
    $computer->forceFill(['is_active' => true, 'credential_hash' => null])->save();
    $this->info('Computer activated. Issue a new credential before the endpoint can communicate.');

    return self::SUCCESS;
})->purpose('Reactivate a Windows computer with its prior credential revoked');

Artisan::command('hrms:map-computer-user {computerCode}', function (string $computerCode) {
    $computer = RegisteredComputer::where('computer_code', $computerCode)->where('is_active', true)->first();
    if (! $computer) {
        $this->error('Unknown or inactive computer code.');

        return self::FAILURE;
    }
    $employeeNo = $this->ask('HRMS employee number');
    $employee = User::where('employee_no', $employeeNo)->where('is_active', true)->first();
    if (! $employee) {
        $this->error('No active employee has that HRMS number.');

        return self::FAILURE;
    }
    $account = $this->ask('Windows account (DOMAIN\\username)');
    $sid = $this->ask('Windows SID (optional; recommended)');
    if (trim($account) === '') {
        $this->error('Windows account is required.');

        return self::FAILURE;
    }
    if ($sid !== '' && ComputerUser::where('computer_id', $computer->id)->where('windows_sid', $sid)->where('windows_account', '!=', $account)->exists()) {
        $this->error('That SID is already mapped to another account on this computer.');

        return self::FAILURE;
    }
    $mapping = ComputerUser::updateOrCreate(['computer_id' => $computer->id, 'windows_account' => $account], ['employee_id' => $employee->id, 'windows_sid' => $sid ?: null]);
    DB::table('audit_logs')->insert(['user_id' => null, 'action' => 'computer_user.mapped', 'subject_type' => 'computer_user', 'subject_id' => $mapping->id, 'details' => json_encode(['computer_code' => $computer->computer_code, 'employee_no' => $employee->employee_no, 'windows_account' => $account]), 'created_at' => now(), 'updated_at' => now()]);
    $this->info('Windows account mapping saved.');

    return self::SUCCESS;
})->purpose('Map a Windows account or SID to an active HRMS employee');
