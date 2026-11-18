<?php

use App\Http\Controllers\Api\V1\Member\EventController;
use App\Http\Controllers\Api\V1\Member\HomeController;
use App\Http\Controllers\Api\V1\Member\PackageController;
use App\Http\Controllers\Api\V1\Member\SpaceController;
use Illuminate\Support\Facades\Route;

// The member app's visitor mode: the screens someone may look at before
// signing in. Every route here sits behind `member.optional`, applied once
// from routes/api.php — no credential reads as a visitor ($request->user()
// is null), a credential is held to auth:sanctum + role:member, so a member
// sees their own version (home's sections, `is_current`, their prices).
// Anything that writes, or is about the member's own records, stays in
// member.php. The terms of use, the privacy policy and help & support need
// no login at all and are in mobile.php.
//
// Registered after member.php, so its static segments (events/my-events)
// are matched before events/{event} here.

// The app's home screen in one read: the greeting, the unread count, the
// running co-working session, the banners (announcements placed on the
// app), today's bookings and events, and "Explore ADD" (the services put on
// the home screen; each one's page is services/{slug}). A visitor gets the
// greeting without a name, the banners and "Explore ADD"; the rest is empty.
Route::get('home', [HomeController::class, 'show']);

// Browse spaces for members with working hours, filtering and amenities.
Route::get('spaces', [SpaceController::class, 'index']);
Route::get('spaces/{space}', [SpaceController::class, 'show']);

// Browse the package catalog — "Packages" tab. Each entry carries
// `is_current` (always false for a visitor); the member's own subscription
// and usage is a separate call. `features` is capped at 3
// (+ more_features_count); packages/{plan} below is where a client reads
// one plan's full, untruncated details.
Route::get('packages', [PackageController::class, 'index']);
Route::get('packages/{plan}', [PackageController::class, 'show']);

// Mobile events calendar, categories, and details.
Route::get('events', [EventController::class, 'index']);
Route::get('events/categories', [EventController::class, 'categories']);
Route::get('events/statuses', [EventController::class, 'statuses']);
Route::get('events/{event}', [EventController::class, 'show']);
