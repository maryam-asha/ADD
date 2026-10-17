<?php

use Add\Hr\Benefits\Http\Controllers\SelfService\MyBenefitsController;
use Illuminate\Support\Facades\Route;

/*
 * My benefits.
 */
Route::get('benefits', [MyBenefitsController::class, 'index'])->name('benefits.index');
