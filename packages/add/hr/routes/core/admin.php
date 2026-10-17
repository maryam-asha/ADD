<?php

use Add\Hr\Authorization\Abilities;
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
