<?php

use App\Http\Controllers\Api\V1\Auth\MemberAuthController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('otp/request', [MemberAuthController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('otp/verify', [MemberAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        // Named so EnsureUserIsActive's global check (bootstrap/app.php) can
        // exempt it by name — a deactivated or blocked account must still be
        // able to end its own session. No 'active' middleware to nest around
        // 'me' here anymore; the check applies to every request already.
        Route::post('logout', [MemberAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [CurrentUserController::class, 'show']);
    });
});
