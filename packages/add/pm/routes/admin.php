<?php

/*
 * Project management. Mounted by Pm::adminRoutes() inside the host's own
 * authenticated group; each route judges membership or its own ability.
 */

use Add\Pm\Authorization\Abilities;
use Add\Pm\Http\Controllers\MyTaskController;
use Add\Pm\Http\Controllers\PeopleController;
use Add\Pm\Http\Controllers\ProjectController;
use Add\Pm\Http\Controllers\TagController;
use Add\Pm\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('people', [PeopleController::class, 'index'])->name('people.index');

Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
Route::middleware('can:'.Abilities::PROJECTS_MANAGE)->group(function () {
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::put('projects/{project}/members', [ProjectController::class, 'replaceMembers'])->name('projects.members');
});

// Fixed paths come before `tasks/{task}` so "counts" is not read as an id.
Route::get('tasks', [MyTaskController::class, 'index'])->name('tasks.mine');
Route::get('tasks/counts', [MyTaskController::class, 'counts'])->name('tasks.counts');
Route::get('projects/{project}/tasks', [TaskController::class, 'index'])->name('projects.tasks.index');
Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
Route::post('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');

Route::get('tags', [TagController::class, 'index'])->name('tags.index');
Route::middleware('can:'.Abilities::PROJECTS_MANAGE)->group(function () {
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
});
