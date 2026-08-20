<?php

use App\Http\Controllers\Api\V1\Auth\AccountReactivationController;
use App\Http\Controllers\Api\V1\Auth\MemberAuthController;
use App\Http\Controllers\Api\V1\Auth\MemberPasswordController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Sign-up, in two steps. 'register' validates the whole profile and sends a
    // code without writing anything; 'register/verify' spends the code and
    // creates the account. Account creation only — a member who already has one
    // signs in at 'login' instead.
    Route::post('register', [MemberAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('register/verify', [MemberAuthController::class, 'verifyRegistration'])->middleware('throttle:10,1');

    // 'member-login' is a named limiter (AppServiceProvider) keyed on phone+IP
    // rather than IP alone — the plain throttle middleware can't see the body.
    Route::post('login', [MemberAuthController::class, 'login'])->middleware('throttle:member-login');

    // Unauthenticated: an expired access token is exactly when this is called,
    // so requiring one would make it unreachable. The refresh token in the body
    // is the credential.
    Route::post('refresh', [MemberAuthController::class, 'refresh'])->middleware('throttle:10,1');

    // Recovery for members (phone + WhatsApp code). Fortify owns the parallel
    // email-based reset for the operations dashboard; these two don't overlap.
    Route::post('password/forgot', [MemberPasswordController::class, 'forgot'])->middleware('throttle:10,1');
    Route::post('password/verify', [MemberPasswordController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('password/reset', [MemberPasswordController::class, 'reset'])->middleware('throttle:10,1');

    // The deactivated-only counterpart to admin-side unblocking: a member who
    // deactivated their own account (member/account/deactivate) can restore it
    // with a WhatsApp code, same as a password reset. A blocked account is
    // excluded on purpose — AccountReactivationController::memberFor() only
    // resolves a `deactivated` account, so this can never be the way a block
    // gets reversed; that stays an admin-only action.
    Route::post('account/reactivate', [AccountReactivationController::class, 'request'])->middleware('throttle:10,1');
    Route::post('account/reactivate/verify', [AccountReactivationController::class, 'verify'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        // Named so EnsureUserIsActive's global check (bootstrap/app.php) can
        // exempt it by name — a deactivated or blocked account must still be
        // able to end its own session. No 'active' middleware to nest around
        // 'me' here anymore; the check applies to every request already.
        Route::post('logout', [MemberAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [CurrentUserController::class, 'show']);
    });
});
