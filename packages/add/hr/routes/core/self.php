<?php

use Add\Hr\Core\Http\Controllers\SelfService\SelfServiceController;
use Illuminate\Support\Facades\Route;

/*
 * Self-service, mounted by Hr::selfServiceRoutes() behind `hr.employee`.
 * No ability checks: an active employee record is the permission, so every
 * employee — whatever their role — reaches exactly their own data.
 */
Route::get('/', [SelfServiceController::class, 'profile'])->name('profile');
Route::get('events', [SelfServiceController::class, 'events'])->name('events');
Route::get('documents', [SelfServiceController::class, 'documents'])->name('documents.index');
Route::post('documents/{document}/link', [SelfServiceController::class, 'documentLink'])->name('documents.link');
Route::put('contacts', [SelfServiceController::class, 'contacts'])->name('contacts');
Route::get('team', [SelfServiceController::class, 'team'])->name('team');
