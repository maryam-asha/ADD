<?php

use Add\Hr\Timesheets\Http\Controllers\SelfService\MyTimesheetController;
use Illuminate\Support\Facades\Route;

/*
 * My timesheets, each week addressed by its first day (Y-m-d).
 */
Route::get('cost-centers', [MyTimesheetController::class, 'costCenters'])->name('cost-centers');
Route::get('timesheets', [MyTimesheetController::class, 'index'])->name('timesheets.index');
Route::get('timesheets/{week}', [MyTimesheetController::class, 'show'])->name('timesheets.show');
Route::put('timesheets/{week}', [MyTimesheetController::class, 'save'])->name('timesheets.save');
Route::post('timesheets/{week}/submit', [MyTimesheetController::class, 'submit'])->name('timesheets.submit');
Route::post('timesheets/{week}/recall', [MyTimesheetController::class, 'recall'])->name('timesheets.recall');
