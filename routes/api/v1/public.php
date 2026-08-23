<?php

use App\Http\Controllers\Api\V1\Public\CommunityMemberController;
use App\Http\Controllers\Api\V1\Public\ContactLinkController;
use App\Http\Controllers\Api\V1\Public\CurrencyController;
use App\Http\Controllers\Api\V1\Public\FounderController;
use App\Http\Controllers\Api\V1\Public\KioskController;
use App\Http\Controllers\Api\V1\Public\MemberDirectoryController;
use App\Http\Controllers\Api\V1\Public\PartnerController;
use App\Http\Controllers\Api\V1\Public\PlanController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated reads for the marketing site (and RSVP writes)
// — filled in per-resource as each is built.
Route::get('founders', [FounderController::class, 'index']);
Route::get('partners', [PartnerController::class, 'index']);
Route::get('community-members', [CommunityMemberController::class, 'index']);
Route::get('plans', [PlanController::class, 'index']);
Route::get('contact-links', [ContactLinkController::class, 'index']);
Route::get('currencies', [CurrencyController::class, 'index']);
Route::get('member-directory', [MemberDirectoryController::class, 'index'])->middleware('throttle:60,1');

// Literal "public/" segment per docs/decisions/kiosk-display.md — the
// aggregate kiosk endpoint is documented and tested at
// /api/v1/public/kiosk, distinct from this file's other routes (which are
// unauthenticated too, but don't carry the word "public" in their path).
Route::get('public/kiosk', [KioskController::class, 'show']);
