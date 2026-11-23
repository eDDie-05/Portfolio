<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAttendanceEvent;
use App\Models\AttendanceDevice;
use App\Models\DeviceEmployeeMapping;
use App\Models\RawAttendanceEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttendanceCsvImportController extends Controller
{
    public function store(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $device->loadMissing('location');
        $timezone = $device->location?->timezone ?? config('attendance.display_timezone');
        $request->validate(['attendance_file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);
        $path = $request->file('attendance_file')->getRealPath();
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return back()->withErrors(['attendance_file' => 'The uploaded file could not be read.']);
        }
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return back()->withErrors(['attendance_file' => 'The file is empty.']);
        }
        $headers = array_map(fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $headers);
        if (count(array_unique($headers)) !== count($headers)) {
            fclose($handle);

            return back()->withErrors(['attendance_file' => 'The CSV contains duplicate column names.']);
        }
        foreach (['device_user_id', 'event_timestamp'] as $required) {
            if (! in_array($required, $headers, true)) {
                fclose($handle);

                return back()->withErrors(['attendance_file' => "Required CSV column missing: {$required}"]);
            }
        }

        $counts = ['accepted' => 0, 'duplicates' => 0, 'unmatched' => 0, 'failed' => 0];
        $line = 1;
        DB::transaction(function () use ($handle, $headers, $device, $timezone, &$counts, &$line): void {
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 10001) {
                    $counts['failed']++;
                    DB::table('integration_error_logs')->insert(['source_type' => 'attendance_device', 'source_id' => $device->id, 'error_code' => 'csv_row_limit', 'message' => 'CSV import stopped after 10,000 data rows; remaining rows were not imported.', 'context' => json_encode(['next_line' => $line]), 'created_at' => now(), 'updated_at' => now()]);
                    break;
                }
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if (count($values) > count($headers)) {
                    $counts['failed']++;
                    DB::table('integration_error_logs')->insert(['source_type' => 'attendance_device', 'source_id' => $device->id, 'error_code' => 'csv_column_overflow', 'message' => 'CSV line '.$line.' contains more fields than the header row.', 'context' => json_encode(['line' => $line, 'values' => $values]), 'created_at' => now(), 'updated_at' => now()]);

                    continue;
                }
                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = $values[$index] ?? null;
                }
                $validator = Validator::make($row, ['device_user_id' => ['required', 'string', 'max:120'], 'event_timestamp' => ['required', 'date'], 'event_identifier' => ['nullable', 'string', 'max:190'], 'event_type' => ['nullable', 'string', 'max:80']]);
                if ($validator->fails()) {
                    $counts['failed']++;
                    DB::table('integration_error_logs')->insert(['source_type' => 'attendance_device', 'source_id' => $device->id, 'error_code' => 'csv_row_invalid', 'message' => 'CSV line '.$line.': '.$validator->errors()->first(), 'context' => json_encode(['line' => $line, 'row' => $row]), 'created_at' => now(), 'updated_at' => now()]);

                    continue;
                }
                $timestamp = now()->parse($row['event_timestamp'], $timezone)->utc();
                $identifier = $row['event_identifier'] ?: hash('sha256', json_encode([$device->id, $row, $line], JSON_THROW_ON_ERROR));
                if (RawAttendanceEvent::where('device_id', $device->id)->where('event_identifier', $identifier)->exists()) {
                    $counts['duplicates']++;

                    continue;
                }
                $rawEvent = RawAttendanceEvent::create(['device_id' => $device->id, 'employee_id' => null, 'device_user_id' => $row['device_user_id'], 'event_identifier' => $identifier, 'event_timestamp' => $timestamp, 'received_timestamp' => now()->utc(), 'event_type' => $row['event_type'] ?: null, 'raw_payload' => ['csv_line' => $line, 'columns' => $row], 'source' => 'csv_import', 'processing_status' => 'pending']);
                ProcessAttendanceEvent::dispatch($rawEvent->id)->afterCommit();
                $counts['accepted']++;
                if (! DeviceEmployeeMapping::where('device_id', $device->id)->where('device_user_id', $row['device_user_id'])->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $timestamp))->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $timestamp))->exists()) {
                    $counts['unmatched']++;
                }
            }
            $device->syncLogs()->create(['started_at' => now(), 'finished_at' => now(), 'status' => $counts['failed'] ? 'partial_failure' : 'success', 'records_received' => $counts['accepted'], 'records_failed' => $counts['failed'] + $counts['unmatched'], 'message' => 'CSV import: '.json_encode($counts)]);
            if ($counts['accepted'] > 0) {
                $device->forceFill(['last_successful_sync_at' => now()])->save();
            }
        });
        fclose($handle);
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'attendance.csv_imported', 'subject_type' => 'attendance_device', 'subject_id' => $device->id, 'details' => json_encode($counts), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        $message = "Import complete: {$counts['accepted']} accepted, {$counts['duplicates']} duplicates, {$counts['unmatched']} unmatched, {$counts['failed']} invalid rows. Invalid row details were logged.";

        return back()->with($counts['failed'] ? 'warning' : 'status', $message);
    }
}
