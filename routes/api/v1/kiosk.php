<?php

use App\Http\Controllers\Api\V1\Kiosk\KioskPairController;
use Illuminate\Support\Facades\Route;

// Unauthenticated endpoints the kiosk app calls
// (docs/decisions/coworking-self-service-sessions.md). The kiosk holds no
// token: the single-use pairing code is the proof here, and after pairing
// the kiosk never calls in — it generates its codes offline.
Route::post('kiosk/pair', [KioskPairController::class, 'pair'])->middleware('throttle:5,1');
