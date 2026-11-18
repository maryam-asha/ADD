<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Leave\Http\Controllers\Admin\CoverageRuleController;
use Add\Hr\Leave\Http\Controllers\Admin\EmployeeLeaveController;
use Add\Hr\Leave\Http\Controllers\Admin\HolidayController;
use Add\Hr\Leave\Http\Controllers\Admin\LeaveCalendarController;
use Add\Hr\Leave\Http\Controllers\Admin\LeavePolicyController;
use Add\Hr\Leave\Http\Controllers\Admin\LeaveRequestController;
use Add\Hr\Leave\Http\Controllers\Admin\LeaveTypeController;
use Illuminate\Support\Facades\Route;

/*
 * Leave administration: settings (types, policies, holidays, coverage) are
 * read with hr.leave.view and written with hr.settings.manage; acting on an
 * employee's leave needs hr.leave.manage.
 */

$settings = [
    'leave-types' => [LeaveTypeController::class, 'leaveType'],
    'leave-policies' => [LeavePolicyController::class, 'leavePolicy'],
    'holidays' => [HolidayController::class, 'holiday'],
    'coverage-rules' => [CoverageRuleController::class, 'coverageRule'],
];

Route::middleware('can:'.Abilities::LEAVE_VIEW)->group(function () use ($settings) {
    foreach ($settings as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['index', 'show'])->parameters([$uri => $parameter]);
    }

    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave-requests.show');
    Route::get('leave-calendar', [LeaveCalendarController::class, 'index'])->name('leave-calendar.index');
    Route::get('employees/{employee}/leave-balances', [EmployeeLeaveController::class, 'balances'])->name('employees.leave-balances');
    Route::get('employees/{employee}/leave-ledger', [EmployeeLeaveController::class, 'ledger'])->name('employees.leave-ledger');
});

Route::middleware('can:'.Abilities::SETTINGS_MANAGE)->group(function () use ($settings) {
    foreach ($settings as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['store', 'update', 'destroy'])->parameters([$uri => $parameter]);
    }
});

Route::middleware('can:'.Abilities::LEAVE_MANAGE)->group(function () {
    Route::post('employees/{employee}/leave-requests', [LeaveRequestController::class, 'store'])->name('employees.leave-requests.store');
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');
    Route::post('employees/{employee}/leave-adjustments', [EmployeeLeaveController::class, 'adjust'])->name('employees.leave-adjustments');
});
