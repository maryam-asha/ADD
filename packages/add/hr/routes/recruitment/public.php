<?php

use Add\Hr\Recruitment\Http\Controllers\Public\CareersController;
use Illuminate\Support\Facades\Route;

/*
 * The careers page, mounted by Hr::publicRoutes() without authentication:
 * public openings taking applications, and applying with a CV.
 */
Route::get('careers/openings', [CareersController::class, 'index'])->name('careers.openings.index');
Route::get('careers/openings/{opening}', [CareersController::class, 'show'])->name('careers.openings.show');
Route::post('careers/openings/{opening}/applications', [CareersController::class, 'apply'])->name('careers.openings.apply');
