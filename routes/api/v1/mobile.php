<?php

use App\Http\Controllers\Api\V1\Mobile\ErrorLogController;
use App\Http\Controllers\Api\V1\Mobile\HelpSupportController;
use App\Http\Controllers\Api\V1\Mobile\PrivacyPolicyController;
use App\Http\Controllers\Api\V1\Mobile\TermsOfUseController;
use Illuminate\Support\Facades\Route;

// Unauthenticated endpoints the member mobile app calls directly — distinct
// from public.php's marketing-site reads and from the auth flow. Errors can
// be reported before login, so this has no auth:sanctum wrapper; per-IP
// throttling is the actual defense here (see the design doc).
Route::post('/errors', [ErrorLogController::class, 'store'])->middleware('throttle:60,1');

// Help & Support: Markdown plus the contact rows, readable before login —
// someone who cannot sign in is exactly who needs it.
Route::get('/help-support', [HelpSupportController::class, 'show'])->middleware('throttle:60,1');

// The privacy policy and the terms of use, readable before login — sign-up
// shows them before there is an account.
Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show'])->middleware('throttle:60,1');
Route::get('/terms-of-use', [TermsOfUseController::class, 'show'])->middleware('throttle:60,1');
