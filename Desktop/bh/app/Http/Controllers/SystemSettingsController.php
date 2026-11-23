<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SystemSettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.index', [
            'settings' => SystemSetting::query()->pluck('value', 'key'),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'display_timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'default_country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'default_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'tanzania_payroll_jurisdiction' => ['required', Rule::in(['mainland', 'zanzibar'])],
            'sdl_exempt' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $before = SystemSetting::query()->pluck('value', 'key')->all();

            foreach ($data as $key => $value) {
                SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value, 'updated_by' => $request->user()->id]);
            }

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => 'system.settings_updated',
                'subject_type' => SystemSetting::class,
                'subject_id' => null,
                'details' => json_encode(['before' => $before, 'after' => $data]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'System settings saved and applied to new requests.');
    }
}
