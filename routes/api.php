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
    //
    // 'abilities:dashboard' is the isolation boundary between the two client
    // surfaces, and it is separate from 'role' on purpose. The role says what
    // its holder may do; the ability says which surface issued the credential
    // being presented. A person who is genuinely both a member and an operator
    // passes the role check with an app token — so only the ability keeps that
    // token, minted behind a single factor, out of the operations API. The
    // dashboard's own session satisfies this automatically (Sanctum gives a
    // session-authenticated user a TransientToken that allows every ability).
    Route::middleware(['auth:sanctum', 'abilities:dashboard', 'role:admin|operations'])
        ->prefix('admin')
        ->group(base_path('routes/api/v1/admin.php'));

    Route::middleware(['auth:sanctum', 'role:member'])
        ->prefix('member')
        ->group(base_path('routes/api/v1/member.php'));
});
