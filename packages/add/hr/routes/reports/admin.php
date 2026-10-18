<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * The combined HR reports and the dashboard. Money in them (payroll, loans)
 * shows only to holders of hr.payroll.view. Reports that belong to one
 * module (employee cost, end-of-service liability, time by cost center,
 * payroll variance) are mounted with that module.
 */
Route::middleware('can:'.Abilities::REPORTS_VIEW)->prefix('reports')->name('reports.')->group(function () {
    Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
    Route::get('headcount', [ReportController::class, 'headcount'])->name('headcount');
    Route::get('attendance-exceptions', [ReportController::class, 'exceptions'])->name('attendance-exceptions');
    Route::get('timesheet-compliance', [ReportController::class, 'timesheetCompliance'])->name('timesheet-compliance');
    Route::get('liabilities', [ReportController::class, 'liabilities'])->name('liabilities');
});
