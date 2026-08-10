<?php

use Illuminate\Support\Facades\Route;

/*
 * Every endpoint lives under /api/v1 from day one, even though there is only
 * one version right now — introducing versioning after clients already
 * depend on unversioned paths is the expensive time to do it.
 */
Route::prefix('v1')->group(function () {
    require base_path('routes/api/v1/auth.php');
    require base_path('routes/api/v1/public.php');

    // 'active' is no longer listed here — EnsureUserIsActive is registered
    // globally in bootstrap/app.php, not per route group.
    Route::middleware(['auth:sanctum', 'role:admin|operations'])
        ->prefix('admin')
        ->group(base_path('routes/api/v1/admin.php'));

    Route::middleware(['auth:sanctum', 'role:member'])
        ->prefix('member')
        ->group(base_path('routes/api/v1/member.php'));
});
