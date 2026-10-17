<?php

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Http\Controllers\Admin\EmployeeController;
use Add\Hr\Core\Http\Controllers\Admin\EmployeeRelationsController;
use Add\Hr\Core\Http\Controllers\Admin\EmployeeSensitiveController;
use Add\Hr\Core\Http\Controllers\Admin\JobGradeController;
use Add\Hr\Core\Http\Controllers\Admin\OrgUnitController;
use Add\Hr\Core\Http\Controllers\Admin\PositionController;
use Add\Hr\Core\Http\Controllers\Admin\WorkLocationController;
use Illuminate\Support\Facades\Route;

/*
 * Core HR administration. Mounted by Hr::adminRoutes() inside the host's own
 * authenticated group; each route checks its own ability.
 */

$organization = [
    'org-units' => [OrgUnitController::class, 'orgUnit'],
    'job-grades' => [JobGradeController::class, 'jobGrade'],
    'positions' => [PositionController::class, 'position'],
    'work-locations' => [WorkLocationController::class, 'workLocation'],
];

Route::middleware('can:'.Abilities::ORG_VIEW)->group(function () use ($organization) {
    foreach ($organization as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['index', 'show'])->parameters([$uri => $parameter]);
    }
});

Route::middleware('can:'.Abilities::ORG_MANAGE)->group(function () use ($organization) {
    foreach ($organization as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['store', 'update', 'destroy'])->parameters([$uri => $parameter]);
    }
});

// Employees
Route::get('employees', [EmployeeController::class, 'index'])
    ->middleware('can:'.Abilities::EMPLOYEES_VIEW)->name('employees.index');
Route::post('employees', [EmployeeController::class, 'store'])
    ->middleware('can:'.Abilities::EMPLOYEES_CREATE)->name('employees.store');
Route::get('employees/{employee}', [EmployeeController::class, 'show'])
    ->middleware('can:'.Abilities::EMPLOYEES_VIEW)->name('employees.show');
Route::patch('employees/{employee}', [EmployeeController::class, 'update'])
    ->middleware('can:'.Abilities::EMPLOYEES_UPDATE)->name('employees.update');
Route::get('employees/{employee}/sensitive', [EmployeeSensitiveController::class, 'show'])
    ->middleware('can:'.Abilities::EMPLOYEES_SENSITIVE)->name('employees.sensitive');
Route::put('employees/{employee}/contacts', [EmployeeRelationsController::class, 'contacts'])
    ->middleware('can:'.Abilities::EMPLOYEES_UPDATE)->name('employees.contacts');
Route::put('employees/{employee}/dependents', [EmployeeRelationsController::class, 'dependents'])
    ->middleware('can:'.Abilities::EMPLOYEES_UPDATE)->name('employees.dependents');
