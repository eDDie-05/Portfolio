<?php

use App\Http\Controllers\Api\AttendanceEventController;
use App\Http\Controllers\Api\ComputerEventController;
use Illuminate\Support\Facades\Route;

Route::middleware('integration.auth:device')->post('/attendance/devices/events', [AttendanceEventController::class, 'store'])->middleware('throttle:120,1');
Route::prefix('attendance/computers')->middleware('integration.auth:computer')->group(function () {
    Route::post('/events', [ComputerEventController::class, 'store'])->middleware('throttle:120,1');
    Route::post('/health', [ComputerEventController::class, 'health'])->middleware('throttle:30,1');
});
