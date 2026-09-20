<?php

use App\Http\Controllers\Api\V1\Admin\AdminEventAttendeeController;
use App\Http\Controllers\Api\V1\Admin\AdminEventRegistrationFieldController;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\AdminServiceRequestController;
use App\Http\Controllers\Api\V1\Admin\AdminServiceTypeController;
use App\Http\Controllers\Api\V1\Admin\AnnouncementController;
use App\Http\Controllers\Api\V1\Admin\BookingCalendarController;
use App\Http\Controllers\Api\V1\Admin\BookingController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BuildingController;
use App\Http\Controllers\Api\V1\Admin\BusinessHourController;
use App\Http\Controllers\Api\V1\Admin\BusinessHourExceptionController;
use App\Http\Controllers\Api\V1\Admin\CommunityMemberController;
use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\CompanyMemberController;
use App\Http\Controllers\Api\V1\Admin\ContactLinkController;
use App\Http\Controllers\Api\V1\Admin\CurrencyController;
use App\Http\Controllers\Api\V1\Admin\DeviceCapabilityController;
use App\Http\Controllers\Api\V1\Admin\DeviceController;
use App\Http\Controllers\Api\V1\Admin\ErrorLogController;
use App\Http\Controllers\Api\V1\Admin\EventCategoryController;
use App\Http\Controllers\Api\V1\Admin\EventController;
use App\Http\Controllers\Api\V1\Admin\ExchangeRateController;
use App\Http\Controllers\Api\V1\Admin\ExchangeRateSuggestionController;
use App\Http\Controllers\Api\V1\Admin\FloorController;
use App\Http\Controllers\Api\V1\Admin\FounderController;
use App\Http\Controllers\Api\V1\Admin\MediaUploadController;
use App\Http\Controllers\Api\V1\Admin\PartnerController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\PlanModuleController;
use App\Http\Controllers\Api\V1\Admin\PrivacyPolicyController;
use App\Http\Controllers\Api\V1\Admin\PrivateOfficeRequestController;
use App\Http\Controllers\Api\V1\Admin\Reception\AccessActivationController;
use App\Http\Controllers\Api\V1\Admin\Reception\ArrivalRequestController as ReceptionArrivalRequestController;
use App\Http\Controllers\Api\V1\Admin\Reception\BookingReceptionController;
use App\Http\Controllers\Api\V1\Admin\Reception\CashRegisterSessionController;
use App\Http\Controllers\Api\V1\Admin\Reception\ReceptionSessionsController;
use App\Http\Controllers\Api\V1\Admin\Reception\WalkInSessionController;
use App\Http\Controllers\Api\V1\Admin\Reception\WalletTopUpController;
use App\Http\Controllers\Api\V1\Admin\ResourceController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SeatDeskController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\SiteInquiryController;
use App\Http\Controllers\Api\V1\Admin\SpaceController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\ZoneController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

// Every route in this file already sits behind auth:sanctum + role:admin|operations
// — that's applied once, from the group wrapping this file in routes/api.php.
// One documented exception: Branches opts out of that role: check below via
// Route::withoutMiddleware('role:admin|operations') and enforces
// permission:branches.* middleware per route instead
// (docs/decisions/rbac-permission-pilot.md) — every other resource in this
// file is unaffected.
Route::get('me', [CurrentUserController::class, 'show']);

// Privacy Policy
Route::get('privacy-policy', [PrivacyPolicyController::class, 'show']);
Route::patch('privacy-policy', [PrivacyPolicyController::class, 'update']);

// Settings — global key/value config (docs/decisions/settings-key-value-store.md).
Route::get('settings', [SettingController::class, 'index']);

// Spatial Hierarchy — Branch is the top level (docs/decisions/district-removed.md).
// destroy() is admin-only for the remaining 8 of these (see the role:admin
// group below): a delete cascades through the DB's own FKs and can wipe out
// every Building/Floor/Zone/Space/Resource/SeatDesk/Device beneath it in one
// request. Branches' own routes (including destroy) are registered entirely
// separately, in the permission-gated block below — they were never part of
// an apiResource line here, and aren't in that role:admin group either.

// Pilot: permission-based enforcement (docs/decisions/rbac-permission-pilot.md,
// written after this lands). A custom role must be able to reach these
// actions on the strength of its granted permissions alone — not by matching
// a hardcoded role name — so the coarse role:admin|operations check applied
// by routes/api.php's group is stripped for exactly this resource.
// auth:sanctum and abilities:dashboard, applied by that same group, are
// untouched.
Route::withoutMiddleware('role:admin|operations')->group(function () {
    Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view')->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:branches.view')->name('branches.show');
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.create')->name('branches.store');
    Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.update')->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:branches.delete')->name('branches.destroy');
});

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
Route::post('devices/{device}/regenerate-qr-value', [DeviceController::class, 'regenerateQrValue']);

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
Route::apiResource('plan-modules', PlanModuleController::class)
    ->parameters(['plan-modules' => 'planModule']);

// Contact Links — public org content (social/app-store/website/phone
// links), admin-managed. Same permission tier as Founders/Partners (no
// narrower role:admin group) — see
// docs/decisions/profile-fields-completion-score-contact-links.md.
Route::apiResource('contact-links', ContactLinkController::class)
    ->parameters(['contact-links' => 'contactLink']);

// Reception kiosk banner content (docs/decisions/kiosk-display.md). Same
// permission tier as Founders/Partners/ContactLinks (no narrower role:admin
// group).
Route::apiResource('announcements', AnnouncementController::class);
Route::apiResource('event-categories', EventCategoryController::class)
    ->parameters(['event-categories' => 'eventCategory']);
Route::get('events/rooms', [EventController::class, 'rooms']);
Route::get('events/statuses', [EventController::class, 'statuses']);
Route::apiResource('events', EventController::class);

// Per-event registration form definition, and the attendee roster with its
// approve/reject decisions. All nested under an already-bound event, so
// they never collide with the apiResource above.
Route::get('events/{event}/registration-fields', [AdminEventRegistrationFieldController::class, 'index']);
Route::post('events/{event}/registration-fields', [AdminEventRegistrationFieldController::class, 'store']);
Route::patch('events/{event}/registration-fields/{field}', [AdminEventRegistrationFieldController::class, 'update']);
Route::delete('events/{event}/registration-fields/{field}', [AdminEventRegistrationFieldController::class, 'destroy']);

Route::get('events/{event}/attendees', [AdminEventAttendeeController::class, 'index']);
Route::get('events/{event}/attendees/pending', [AdminEventAttendeeController::class, 'pending']);
Route::post('events/{event}/attendees/reject-pending', [AdminEventAttendeeController::class, 'rejectPending']);
Route::post('events/{event}/attendees/{attendee}/approve', [AdminEventAttendeeController::class, 'approve']);
Route::post('events/{event}/attendees/{attendee}/reject', [AdminEventAttendeeController::class, 'reject']);

Route::get('exchange-rates', [ExchangeRateController::class, 'index']);
Route::post('exchange-rates', [ExchangeRateController::class, 'store']);
Route::post('exchange-rates/sync', [ExchangeRateController::class, 'sync']);
Route::get('exchange-rates/suggestion', [ExchangeRateSuggestionController::class, 'show']);
Route::post('exchange-rates/suggestion/{exchangeRateSuggestion}/dismiss', [ExchangeRateSuggestionController::class, 'dismiss']);

// Currencies — admin-managed lookup replacing the old hardcoded USD/SYP
// enum (docs/decisions/multi-currency-support.md). Not a generic
// apiResource: no destroy (a currency deactivates via status, it never
// hard-deletes — plans/spaces/bookings/users all carry a real FK to
// currencies.code), same reasoning UserController uses for its own
// deviations. Same permission tier as exchange-rates above (no narrower
// role:admin group) — this is finance-admin config, kept consistent with
// its sibling.
Route::get('currencies', [CurrencyController::class, 'index']);
Route::post('currencies', [CurrencyController::class, 'store']);
Route::get('currencies/{currency}', [CurrencyController::class, 'show']);
Route::patch('currencies/{currency}', [CurrencyController::class, 'update']);
Route::patch('currencies/{currency}/status', [CurrencyController::class, 'updateStatus']);
Route::patch('currencies/{currency}/base', [CurrencyController::class, 'updateBase']);

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

// docs/decisions/booking-availability-and-calendar.md — the operations
// calendar (spaces as rows, days as cells, occupancy per cell) and the
// general bookings index. Read-only: every write verb lives on the
// reception routes below. Occupancy is computed from the same
// BusinessHoursService periods the member calendar uses, so the two can
// never disagree about what "open" means.
Route::get('bookings', [BookingController::class, 'index']);
Route::get('bookings/{booking}', [BookingController::class, 'show']);
Route::get('calendar', [BookingCalendarController::class, 'index']);
Route::get('calendar/occupancy', [BookingCalendarController::class, 'occupancy']);

// The slot grid and duration ladder for one space, so the desk's booking
// form offers the windows BookingCreationService will actually accept
// instead of a free-form clock that learns the rules one 422 at a time.
// Same AvailabilityBrowseService the member routes call -- the read side
// and the write side cannot disagree because there is one of each.
Route::get('spaces/{space}/availability/slots', [BookingCalendarController::class, 'slots']);
Route::get('spaces/{space}/availability/quote', [BookingCalendarController::class, 'quote']);

// Reception Operations (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
// — check-in, check-out, cancellation and payment settlement for bookings.
// No narrower role gate than the file's own admin|operations group: both do
// all of it.
Route::get('reception/bookings/pending-approval', [BookingReceptionController::class, 'pendingApproval']);
// Reception booking a member in at the desk, and the calendar's "book it
// for them" action. Reuses BookingCreationService, so every availability
// rule the member endpoint enforces applies here too.
Route::post('reception/bookings', [BookingReceptionController::class, 'store']);
Route::post('reception/bookings/{booking}/check-in', [BookingReceptionController::class, 'checkIn']);
Route::post('reception/bookings/{booking}/check-out', [BookingReceptionController::class, 'checkOut']);
Route::post('reception/bookings/{booking}/cancel', [BookingReceptionController::class, 'cancel']);
Route::post('reception/bookings/{booking}/settle-payment', [BookingReceptionController::class, 'settlePayment']);
Route::post('reception/bookings/{booking}/approve', [BookingReceptionController::class, 'approve']);
Route::post('reception/bookings/{booking}/reject', [BookingReceptionController::class, 'reject']);
Route::post('reception/bookings/{booking}/extend', [BookingReceptionController::class, 'extend']);
// Access-grant read surface for reception (final-review C3b) — resolves the
// grant a given booking needs activated, since there was previously no way
// to discover a grant's id from its booking.
Route::get('reception/bookings/{booking}/access-grant', [AccessActivationController::class, 'forBooking']);

Route::post('reception/walk-ins', [WalkInSessionController::class, 'store']);
Route::post('reception/walk-ins/{walkinSession}/check-out', [WalkInSessionController::class, 'checkOut']);
Route::post('reception/walk-ins/{walkinSession}/settle-payment', [WalkInSessionController::class, 'settlePayment']);

Route::get('reception/sessions/active', [ReceptionSessionsController::class, 'active']);

Route::get('reception/arrival-requests', [ReceptionArrivalRequestController::class, 'index']);
Route::post('reception/arrival-requests/{arrivalRequest}/confirm', [ReceptionArrivalRequestController::class, 'confirm']);
Route::post('reception/arrival-requests/{arrivalRequest}/reject', [ReceptionArrivalRequestController::class, 'reject']);

Route::get('reception/wallet-balance', [WalletTopUpController::class, 'balance']);
// Registered before the top-up route itself: should wallet-top-ups ever grow
// a wildcard segment (`{topUp}`), a literal /preview declared after it would
// be swallowed as an id.
Route::post('reception/wallet-top-ups/preview', [WalletTopUpController::class, 'preview']);
Route::post('reception/wallet-top-ups', [WalletTopUpController::class, 'store']);
Route::post('reception/access-grants/{accessGrant}/activate', [AccessActivationController::class, 'activate']);

// Cash Register Sessions per staff member per shift (S6-BE-03 / PRD 5.9)
Route::get('reception/cash-register-sessions', [CashRegisterSessionController::class, 'index']);
Route::get('reception/cash-register-sessions/current', [CashRegisterSessionController::class, 'current']);
Route::post('reception/cash-register-sessions/open', [CashRegisterSessionController::class, 'open']);
Route::post('reception/cash-register-sessions/{cashRegisterSession}/close', [CashRegisterSessionController::class, 'close']);

// Site Inquiries from public website (S7-BE-06)
Route::get('site-inquiries', [SiteInquiryController::class, 'index']);
Route::get('site-inquiries/{siteInquiry}', [SiteInquiryController::class, 'show']);
Route::patch('site-inquiries/{siteInquiry}', [SiteInquiryController::class, 'update']);

// File & Media Upload
Route::post('media/upload', [MediaUploadController::class, 'upload']);

// Reading users is permitted for operations (e.g. reception member search) & admin
Route::get('users', [UserController::class, 'index']);
Route::get('users/{user}', [UserController::class, 'show']);

// Narrower than the group above: creating, mutating accounts, and managing roles is admin-only
Route::middleware('role:admin')->group(function () {
    Route::post('users', [UserController::class, 'store']);
    Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update']);
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::patch('users/{user}/role', [UserController::class, 'assignRole']);

    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    Route::get('permissions', [RoleController::class, 'permissions']);

    Route::delete('error-logs/{errorLog}', [ErrorLogController::class, 'destroy']);

    // Spatial Hierarchy destroys are admin-only for the remaining 8 of these
    // — a delete cascades through the DB's own FKs down to every Building/
    // Floor/Zone/Space/Resource/SeatDesk/Device beneath it in one request.
    // Branches' own destroy moved to the permission-gated block above
    // (branches.delete) — see the pilot comment there.
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

    // Notification broadcast — admin-only, sends push + in-app notifications
    // to targeted users (all, by role, or specific user IDs).
    Route::post('notifications/broadcast', [AdminNotificationController::class, 'broadcast']);

    // ADD Service Types — CRUD for service catalog + form fields
    Route::get('service-types', [AdminServiceTypeController::class, 'index']);
    Route::post('service-types', [AdminServiceTypeController::class, 'store']);
    Route::get('service-types/{serviceType}', [AdminServiceTypeController::class, 'show']);
    Route::patch('service-types/{serviceType}', [AdminServiceTypeController::class, 'update']);
    Route::delete('service-types/{serviceType}', [AdminServiceTypeController::class, 'destroy']);
    Route::get('service-types/{serviceType}/fields', [AdminServiceTypeController::class, 'indexFields']);
    Route::post('service-types/{serviceType}/fields', [AdminServiceTypeController::class, 'storeField']);
    Route::patch('service-types/{serviceType}/fields/{field}', [AdminServiceTypeController::class, 'updateField']);
    Route::delete('service-types/{serviceType}/fields/{field}', [AdminServiceTypeController::class, 'destroyField']);

    // ADD Service Requests — staff view all, filter by branch/status/type
    Route::get('service-requests', [AdminServiceRequestController::class, 'index']);
    Route::get('service-requests/{serviceRequest}', [AdminServiceRequestController::class, 'show']);
    Route::patch('service-requests/{serviceRequest}', [AdminServiceRequestController::class, 'update']);
});
