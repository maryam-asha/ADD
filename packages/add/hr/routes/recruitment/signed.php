<?php

use Add\Hr\Recruitment\Http\Controllers\CvFileController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by Hr::signedRoutes() without authentication: a temporary
 * signature (CvVault::link()) is the only credential.
 */
Route::get('applications/{application}/cv', CvFileController::class)
    ->middleware('signed')
    ->name('applications.cv');
