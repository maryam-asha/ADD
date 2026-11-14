<?php

/*
 * Project management. Mounted by Pm::adminRoutes() inside the host's own
 * authenticated group; each route judges membership or its own ability.
 */

use Add\Pm\Authorization\Abilities;
use Add\Pm\Http\Controllers\PeopleController;
use Add\Pm\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('people', [PeopleController::class, 'index'])->name('people.index');

Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
Route::middleware('can:'.Abilities::PROJECTS_MANAGE)->group(function () {
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::put('projects/{project}/members', [ProjectController::class, 'replaceMembers'])->name('projects.members');
});
