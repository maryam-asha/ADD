<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Time\Http\Controllers\Admin\AttendanceCardController;
use Add\Hr\Time\Http\Controllers\Admin\AttendanceController;
use Add\Hr\Time\Http\Controllers\Admin\AttendancePolicyController;
use Add\Hr\Time\Http\Controllers\Admin\PunchController;
use Add\Hr\Time\Http\Controllers\Admin\RosterController;
use Add\Hr\Time\Http\Controllers\Admin\ShiftTemplateController;
use Add\Hr\Time\Http\Controllers\Admin\TimePeriodController;
use Add\Hr\Time\Http\Controllers\Admin\WorkPatternController;
use Illuminate\Support\Facades\Route;

/*
 * Time and attendance. Settings are read with hr.attendance.view and
 * written with hr.settings.manage; the roster, cards, corrections and the
 * monthly lock each have their own ability.
 */

$settings = [
    'shift-templates' => [ShiftTemplateController::class, 'shiftTemplate'],
    'work-patterns' => [WorkPatternController::class, 'workPattern'],
    'attendance-policies' => [AttendancePolicyController::class, 'attendancePolicy'],
];

Route::middleware('can:'.Abilities::ATTENDANCE_VIEW)->group(function () use ($settings) {
    foreach ($settings as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['index', 'show'])->parameters([$uri => $parameter]);
    }

    Route::get('roster', [RosterController::class, 'index'])->name('roster.index');
    Route::get('attendance-days', [AttendanceController::class, 'days'])->name('attendance-days.index');
    Route::get('attendance-exceptions', [AttendanceController::class, 'exceptions'])->name('attendance-exceptions.index');
    Route::get('attendance-entries', [AttendanceController::class, 'entries'])->name('attendance-entries.index');
    Route::get('overtime-requests', [AttendanceController::class, 'overtime'])->name('overtime-requests.index');
    Route::get('employees/{employee}/attendance-punches', [PunchController::class, 'index'])->name('employees.attendance-punches.index');
    Route::get('employees/{employee}/attendance-card', [AttendanceCardController::class, 'show'])->name('employees.attendance-card.show');
    Route::get('time-periods', [TimePeriodController::class, 'index'])->name('time-periods.index');
});

Route::middleware('can:'.Abilities::SETTINGS_MANAGE)->group(function () use ($settings) {
    foreach ($settings as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['store', 'update', 'destroy'])->parameters([$uri => $parameter]);
    }
});

Route::middleware('can:'.Abilities::ROSTER_MANAGE)->group(function () {
    Route::put('roster', [RosterController::class, 'save'])->name('roster.save');
    Route::post('roster/copy-week', [RosterController::class, 'copyWeek'])->name('roster.copy-week');
});

Route::middleware('can:'.Abilities::ATTENDANCE_CARDS)->group(function () {
    Route::post('employees/{employee}/attendance-card', [AttendanceCardController::class, 'store'])->name('employees.attendance-card.store');
    Route::delete('employees/{employee}/attendance-card', [AttendanceCardController::class, 'destroy'])->name('employees.attendance-card.destroy');
});

Route::middleware('can:'.Abilities::ATTENDANCE_CORRECT)->group(function () {
    Route::post('employees/{employee}/attendance-punches', [PunchController::class, 'store'])->name('employees.attendance-punches.store');
    Route::post('attendance-exceptions/{exception}/resolve', [AttendanceController::class, 'resolve'])->name('attendance-exceptions.resolve');
    Route::post('attendance/reprocess', [AttendanceController::class, 'reprocess'])->name('attendance.reprocess');
});

Route::middleware('can:'.Abilities::ATTENDANCE_LOCK)->group(function () {
    Route::post('time-periods/{year}/{month}/lock', [TimePeriodController::class, 'lock'])->whereNumber(['year', 'month'])->name('time-periods.lock');
    Route::post('time-periods/{year}/{month}/unlock', [TimePeriodController::class, 'unlock'])->whereNumber(['year', 'month'])->name('time-periods.unlock');
});
