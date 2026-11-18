<?php

use Add\Hr\Leave\Http\Controllers\SelfService\MyLeaveController;
use Add\Hr\Leave\Http\Controllers\SelfService\TeamLeaveController;
use Illuminate\Support\Facades\Route;

/*
 * The employee's own leave, and their direct reports' calendar.
 */
Route::get('leave/balances', [MyLeaveController::class, 'balances'])->name('leave.balances');
Route::get('leave/ledger', [MyLeaveController::class, 'ledger'])->name('leave.ledger');
Route::post('leave/quote', [MyLeaveController::class, 'quote'])->name('leave.quote');
Route::get('leave/requests', [MyLeaveController::class, 'index'])->name('leave.requests.index');
Route::post('leave/requests', [MyLeaveController::class, 'store'])->name('leave.requests.store');
Route::post('leave/requests/{leaveRequest}/cancel', [MyLeaveController::class, 'cancel'])->name('leave.requests.cancel');

Route::get('team/leave', [TeamLeaveController::class, 'index'])->name('team.leave');
