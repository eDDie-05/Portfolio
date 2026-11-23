<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ApplySystemSettings
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('system_settings')) {
            $settings = DB::table('system_settings')->pluck('value', 'key');
            config([
                'app.name' => $settings->get('company_name', config('app.name')),
                'attendance.display_timezone' => $settings->get('display_timezone', config('attendance.display_timezone')),
            ]);
            View::share('companyName', config('app.name'));
        }

        return $next($request);
    }
}
