<?php

namespace App\Http\Middleware;

use App\Models\AttendanceDevice;
use App\Models\RegisteredComputer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next, string $kind = 'device'): Response
    {
        abort_unless(app()->environment('local', 'testing') || $request->isSecure(), 400, 'HTTPS is required.');
        $code = $request->header('X-Device-Code');
        $token = $request->bearerToken();
        abort_unless($code && $token, 401, 'Device credentials are required.');

        if ($kind === 'device') {
            $record = AttendanceDevice::where('device_code', $code)->where('is_active', true)->first();
            abort_unless($record && $record->api_token_hash && hash_equals($record->api_token_hash, hash('sha256', $token)), 401, 'Invalid device credentials.');
            abort_unless($record->compatibility_verified_at, 403, 'Device compatibility has not been verified by an administrator.');
            $record->forceFill(['last_seen_at' => now()])->save();
            $request->attributes->set('integration_record', $record);
        } else {
            $record = RegisteredComputer::where('computer_code', $code)->where('is_active', true)->first();
            abort_unless($record && $record->credential_hash && hash_equals($record->credential_hash, hash('sha256', $token)), 401, 'Invalid computer credentials.');
            $record->forceFill(['last_seen_at' => now()])->save();
            $request->attributes->set('integration_record', $record);
        }

        return $next($request);
    }
}
