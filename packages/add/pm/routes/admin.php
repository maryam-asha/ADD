<?php

/*
 * Project management. Mounted by Pm::adminRoutes() inside the host's own
 * authenticated group; each route judges membership or its own ability.
 */

use Add\Pm\Http\Controllers\PeopleController;
use Illuminate\Support\Facades\Route;

Route::get('people', [PeopleController::class, 'index'])->name('people.index');
