<?php

use App\Http\Controllers\Api\V1\Public\CommunityMemberController;
use App\Http\Controllers\Api\V1\Public\ContactLinkController;
use App\Http\Controllers\Api\V1\Public\FounderController;
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
Route::get('member-directory', [MemberDirectoryController::class, 'index'])->middleware('throttle:60,1');
