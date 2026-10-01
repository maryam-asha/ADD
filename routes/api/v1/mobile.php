<?php

use App\Http\Controllers\Api\V1\Mobile\ErrorLogController;
use Illuminate\Support\Facades\Route;

// Unauthenticated endpoints the member mobile app calls directly — distinct
// from public.php's marketing-site reads and from the auth flow. Errors can
// be reported before login, so this has no auth:sanctum wrapper; per-IP
// throttling is the actual defense here (see the design doc).
Route::post('/errors', [ErrorLogController::class, 'store'])->middleware('throttle:60,1');
