<?php

use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BuildingController;
use App\Http\Controllers\Api\V1\Admin\BusinessHourController;
use App\Http\Controllers\Api\V1\Admin\BusinessHourExceptionController;
use App\Http\Controllers\Api\V1\Admin\CommunityMemberController;
use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\CompanyMemberController;
use App\Http\Controllers\Api\V1\Admin\DeviceCapabilityController;
use App\Http\Controllers\Api\V1\Admin\DeviceController;
use App\Http\Controllers\Api\V1\Admin\ErrorLogController;
use App\Http\Controllers\Api\V1\Admin\ExchangeRateController;
use App\Http\Controllers\Api\V1\Admin\FloorController;
use App\Http\Controllers\Api\V1\Admin\FounderController;
use App\Http\Controllers\Api\V1\Admin\PartnerController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\PrivacyPolicyController;
use App\Http\Controllers\Api\V1\Admin\PrivateOfficeRequestController;
use App\Http\Controllers\Api\V1\Admin\Reception\BookingReceptionController;
use App\Http\Controllers\Api\V1\Admin\Reception\WalkInSessionController;
use App\Http\Controllers\Api\V1\Admin\ResourceController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SeatDeskController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\SpaceController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\ZoneController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

// Every route in this file already sits behind auth:sanctum + role:admin|operations
// — that's applied once, from the group wrapping this file in routes/api.php.
Route::get('me', [CurrentUserController::class, 'show']);

// Privacy Policy
Route::get('privacy-policy', [PrivacyPolicyController::class, 'show']);
Route::patch('privacy-policy', [PrivacyPolicyController::class, 'update']);

// Settings — global key/value config (docs/decisions/settings-key-value-store.md).
Route::get('settings', [SettingController::class, 'index']);

// Spatial Hierarchy — Branch is the top level (docs/decisions/district-removed.md).
// destroy() is admin-only for all 9 of these (see the role:admin group below):
// a Branch delete cascades through the DB's own FKs and can wipe out every
// Building/Floor/Zone/Space/Resource/SeatDesk/Device beneath it in one request.
Route::apiResource('branches', BranchController::class)->except('destroy');
Route::apiResource('buildings', BuildingController::class)->except('destroy');
Route::apiResource('floors', FloorController::class)->except('destroy');
Route::apiResource('zones', ZoneController::class)->except('destroy');
Route::apiResource('spaces', SpaceController::class)->except('destroy');
Route::patch('spaces/{space}/status', [SpaceController::class, 'updateStatus']);
Route::apiResource('resources', ResourceController::class)->except('destroy');
Route::patch('resources/{resource}/status', [ResourceController::class, 'updateStatus']);

// Multi-word resource name — same reason as community-members above.
Route::apiResource('seats-desks', SeatDeskController::class)
    ->parameters(['seats-desks' => 'seatDesk'])
    ->except('destroy');

Route::apiResource('devices', DeviceController::class)->except('destroy');

// Multi-word resource name — same reason as community-members above.
Route::apiResource('device-capabilities', DeviceCapabilityController::class)
    ->parameters(['device-capabilities' => 'deviceCapability'])
    ->except('destroy');

// Business hours — per-branch recurring weekly schedule
// (docs/decisions/business-hours.md). Multi-word resource name — same
// reason as community-members above.
Route::apiResource('business-hours', BusinessHourController::class)
    ->parameters(['business-hours' => 'businessHour'])
    ->except('destroy');

// Multi-word resource name — same reason as community-members above.
Route::apiResource('business-hour-exceptions', BusinessHourExceptionController::class)
    ->parameters(['business-hour-exceptions' => 'businessHourException'])
    ->except('destroy');

Route::apiResource('founders', FounderController::class);
Route::apiResource('partners', PartnerController::class);
Route::apiResource('plans', PlanController::class);

Route::get('exchange-rates', [ExchangeRateController::class, 'index']);
Route::post('exchange-rates', [ExchangeRateController::class, 'store']);

// Multi-word resource name — Laravel's auto-derived {community_member}
// placeholder (snake_case) won't implicit-bind to a camelCase controller
// argument, so the parameter name is pinned explicitly here.
Route::apiResource('community-members', CommunityMemberController::class)
    ->parameters(['community-members' => 'communityMember']);

// Multi-word resource name — same reason as community-members above.
Route::apiResource('private-office-requests', PrivateOfficeRequestController::class)
    ->parameters(['private-office-requests' => 'privateOfficeRequest']);

// Companies: not a generic apiResource — no destroy (contractual entities
// deactivate, they don't hard-delete, same reasoning as UserController), and
// "update" is specifically the status transition, not a free-form PATCH.
Route::get('companies', [CompanyController::class, 'index']);
Route::post('companies', [CompanyController::class, 'store']);
Route::get('companies/{company}', [CompanyController::class, 'show']);
Route::patch('companies/{company}/status', [CompanyController::class, 'updateStatus']);

Route::get('companies/{company}/members', [CompanyMemberController::class, 'index']);
Route::post('companies/{company}/members', [CompanyMemberController::class, 'store']);
Route::patch('companies/{company}/members/{user}', [CompanyMemberController::class, 'updateDoorAccess']);
Route::patch('companies/{company}/members/{user}/admin', [CompanyMemberController::class, 'updateAdmin']);
Route::delete('companies/{company}/members/{user}', [CompanyMemberController::class, 'destroy']);

// Mobile client crash/error reports (ingested unauthenticated from
// routes/api/v1/mobile.php) — viewable by both roles, but deleting one is
// admin-only, same narrower-than-the-group pattern as below.
Route::get('error-logs', [ErrorLogController::class, 'index']);
Route::get('error-logs/{errorLog}', [ErrorLogController::class, 'show']);

// Reception Operations (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
// — check-in, check-out, cancellation and payment settlement for bookings.
// No narrower role gate than the file's own admin|operations group: both do
// all of it.
Route::post('reception/bookings/{booking}/check-in', [BookingReceptionController::class, 'checkIn']);
Route::post('reception/bookings/{booking}/check-out', [BookingReceptionController::class, 'checkOut']);
Route::post('reception/bookings/{booking}/cancel', [BookingReceptionController::class, 'cancel']);
Route::post('reception/bookings/{booking}/settle-payment', [BookingReceptionController::class, 'settlePayment']);

Route::post('reception/walk-ins', [WalkInSessionController::class, 'store']);
Route::post('reception/walk-ins/{walkinSession}/check-out', [WalkInSessionController::class, 'checkOut']);
Route::post('reception/walk-ins/{walkinSession}/settle-payment', [WalkInSessionController::class, 'settlePayment']);

// Narrower than the group above: managing accounts and roles is admin-only,
// operations can't create or promote other accounts.
Route::middleware('role:admin')->group(function () {
    Route::apiResource('users', UserController::class)->except('destroy');
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::patch('users/{user}/role', [UserController::class, 'assignRole']);

    Route::get('roles', [RoleController::class, 'index']);

    Route::delete('error-logs/{errorLog}', [ErrorLogController::class, 'destroy']);

    // Spatial Hierarchy destroys are admin-only — a Branch delete cascades
    // through the DB's own FKs down to every Building/Floor/Zone/Space/
    // Resource/SeatDesk/Device beneath it in one request.
    Route::delete('branches/{branch}', [BranchController::class, 'destroy']);
    Route::delete('buildings/{building}', [BuildingController::class, 'destroy']);
    Route::delete('floors/{floor}', [FloorController::class, 'destroy']);
    Route::delete('zones/{zone}', [ZoneController::class, 'destroy']);
    Route::delete('spaces/{space}', [SpaceController::class, 'destroy']);
    Route::delete('resources/{resource}', [ResourceController::class, 'destroy']);
    Route::delete('seats-desks/{seatDesk}', [SeatDeskController::class, 'destroy']);
    Route::delete('devices/{device}', [DeviceController::class, 'destroy']);
    Route::delete('device-capabilities/{deviceCapability}', [DeviceCapabilityController::class, 'destroy']);
    Route::delete('business-hours/{businessHour}', [BusinessHourController::class, 'destroy']);
    Route::delete('business-hour-exceptions/{businessHourException}', [BusinessHourExceptionController::class, 'destroy']);

    Route::patch('settings/{key}', [SettingController::class, 'update']);
});
