<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\ComputerEvent;
use App\Models\RawAttendanceEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MyAttendanceController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'employee.password_changed', 'subject_type' => 'employee', 'subject_id' => $request->user()->id, 'details' => json_encode([]), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Password changed successfully.');
    }

    public function __invoke(): View
    {
        $user = auth()->user()->loadMissing('branch');
        abort_unless($user->employee_no, 403);

        return view('employees.mine', [
            'employee' => $user,
            'displayTimezone' => $user->branch?->timezone ?? config('attendance.display_timezone'),
            'records' => AttendanceRecord::where('employee_id', $user->id)->latest('attendance_date')->paginate(20),
            'events' => RawAttendanceEvent::with('device.location')->where('employee_id', $user->id)->latest('event_timestamp')->paginate(20, ['*'], 'events_page'),
            'computerEvents' => ComputerEvent::with('computer.branch')->where('employee_id', $user->id)->latest('event_timestamp')->paginate(20, ['*'], 'computer_events_page'),
        ]);
    }
}
