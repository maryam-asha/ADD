<?php

use App\Http\Controllers\Api\V1\Member\GuestController;
use Illuminate\Support\Facades\Route;

// Every route in this file already sits behind auth:sanctum + role:member —
// applied once, from the group wrapping this file in routes/api.php. A
// company member is still just a `member` role-wise (D.8), so this group
// covers both individual and company-affiliated members with no split.
Route::get('guests', [GuestController::class, 'index']);
Route::post('guests', [GuestController::class, 'store']);
Route::delete('guests/{guest}', [GuestController::class, 'destroy']);
