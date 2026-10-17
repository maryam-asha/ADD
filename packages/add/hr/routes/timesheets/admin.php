<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Timesheets\Http\Controllers\Admin\CostAllocationController;
use Add\Hr\Timesheets\Http\Controllers\Admin\CostCenterController;
use Add\Hr\Timesheets\Http\Controllers\Admin\TimeReportController;
use Add\Hr\Timesheets\Http\Controllers\Admin\TimesheetController;
use Illuminate\Support\Facades\Route;

/*
 * Timesheets and cost allocation. Reads need hr.timesheets.view; cost
 * centers are settings (hr.settings.manage); an employee's cost split needs
 * hr.timesheets.manage. Timesheets are decided through the approval engine.
 */
Route::middleware('can:'.Abilities::TIMESHEETS_VIEW)->group(function () {
    Route::apiResource('cost-centers', CostCenterController::class)->only(['index', 'show'])->parameters(['cost-centers' => 'costCenter']);
    Route::get('employees/{employee}/cost-allocations', [CostAllocationController::class, 'index'])->name('employees.cost-allocations.index');
    Route::get('timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
    Route::get('timesheets/compliance', [TimesheetController::class, 'compliance'])->name('timesheets.compliance');
    Route::get('timesheets/{timesheet}', [TimesheetController::class, 'show'])->name('timesheets.show');
    Route::get('reports/time-by-cost-center', [TimeReportController::class, 'byCostCenter'])->name('reports.time-by-cost-center');
});

Route::middleware('can:'.Abilities::SETTINGS_MANAGE)->group(function () {
    Route::apiResource('cost-centers', CostCenterController::class)->only(['store', 'update', 'destroy'])->parameters(['cost-centers' => 'costCenter']);
});

Route::put('employees/{employee}/cost-allocations', [CostAllocationController::class, 'replace'])
    ->middleware('can:'.Abilities::TIMESHEETS_MANAGE)->name('employees.cost-allocations.replace');
