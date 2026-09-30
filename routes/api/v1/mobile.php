<?php

use App\Http\Controllers\Api\V1\Mobile\ErrorLogController;
use App\Http\Controllers\Api\V1\Mobile\PaymentMethodsController;
use Illuminate\Support\Facades\Route;

// Unauthenticated endpoints the member mobile app calls directly — distinct
// from public.php's marketing-site reads and from the auth flow. Errors can
// be reported before login, so this has no auth:sanctum wrapper; per-IP
// throttling is the actual defense here (see the design doc).
Route::post('/errors', [ErrorLogController::class, 'store'])->middleware('throttle:60,1');

// The payment methods the app may offer, readable before login; a member's
// token (optional) adds their wallet balance (docs/decisions/unified-payments.md).
Route::get('/payment-methods', [PaymentMethodsController::class, 'index'])->middleware('throttle:60,1');
