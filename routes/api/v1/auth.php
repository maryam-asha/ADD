<?php

use App\Http\Controllers\Api\V1\Auth\MemberAuthController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('otp/request', [MemberAuthController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('otp/verify', [MemberAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [MemberAuthController::class, 'logout']);
        Route::get('me', [CurrentUserController::class, 'show']);
    });
});
