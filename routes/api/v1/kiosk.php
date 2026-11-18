<?php

use App\Domain\Booking\Services\KioskPairingService;
use App\Http\Controllers\Api\V1\Kiosk\KioskAttendanceController;
use App\Http\Controllers\Api\V1\Kiosk\KioskAuthController;
use App\Http\Controllers\Api\V1\Kiosk\KioskPairController;
use Illuminate\Support\Facades\Route;

// The endpoints the kiosk app calls
// (docs/decisions/coworking-self-service-sessions.md). A kiosk's setup takes a
// person: staff sign in here, sharing the dashboard login's attempt limit, and
// only then trade a single-use pairing code for the kiosk's secret. The token
// dies with the pairing, so a paired kiosk holds no token: it generates its
// codes offline and signs what it sends. The third throttle argument is a key
// prefix: without it every plain `throttle:` on an unauthenticated route shares
// one per-IP bucket.
Route::post('kiosk/auth/login', [KioskAuthController::class, 'login'])->middleware('throttle:login');

Route::post('kiosk/pair', [KioskPairController::class, 'pair'])->middleware([
    'throttle:5,1,kiosk-pair',
    'auth:sanctum',
    'abilities:'.KioskPairingService::OPERATOR_ABILITY,
    'permission:'.KioskPairingService::PAIR_PERMISSION,
]);

// Staff card scans for HR attendance (packages/add/hr): the kiosk signs the
// request with its pairing secret instead of holding a token
// (App\Domain\Hr\Kiosk\KioskRequestSignature). A batch carries a kiosk's
// offline queue, so the limit is per kiosk IP, not per scan.
Route::post('kiosk/attendance/punches', [KioskAttendanceController::class, 'store'])->middleware('throttle:60,1,kiosk-attendance');
