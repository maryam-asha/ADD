<?php

use Add\Hr\Core\Http\Controllers\DocumentFileController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by Hr::signedRoutes() without authentication: a temporary
 * signature (DocumentVault::link()) is the only credential.
 */
Route::get('documents/{document}/file', DocumentFileController::class)
    ->middleware('signed')
    ->name('documents.file');
