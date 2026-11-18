<?php

use Add\Hr\Time\Http\Controllers\SelfService\MyAttendanceController;
use Illuminate\Support\Facades\Route;

/*
 * My schedule and attendance, my own time entries and overtime, and my
 * direct reports' day.
 */
Route::get('schedule', [MyAttendanceController::class, 'schedule'])->name('schedule');
Route::get('attendance', [MyAttendanceController::class, 'days'])->name('attendance');
Route::get('attendance/entries', [MyAttendanceController::class, 'entries'])->name('attendance.entries.index');
Route::post('attendance/entries', [MyAttendanceController::class, 'storeEntry'])->name('attendance.entries.store');
Route::post('attendance/entries/{entry}/cancel', [MyAttendanceController::class, 'cancelEntry'])->name('attendance.entries.cancel');
Route::get('overtime', [MyAttendanceController::class, 'overtime'])->name('overtime.index');
Route::post('overtime', [MyAttendanceController::class, 'storeOvertime'])->name('overtime.store');
Route::post('overtime/{overtimeRequest}/cancel', [MyAttendanceController::class, 'cancelOvertime'])->name('overtime.cancel');
Route::get('team/attendance', [MyAttendanceController::class, 'team'])->name('team.attendance');
