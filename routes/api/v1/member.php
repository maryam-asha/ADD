<?php

use App\Http\Controllers\Api\V1\Member\AccessUnlockController;
use App\Http\Controllers\Api\V1\Member\AccountController;
use App\Http\Controllers\Api\V1\Member\ArrivalRequestController;
use App\Http\Controllers\Api\V1\Member\BookingController;
use App\Http\Controllers\Api\V1\Member\CompanyMemberController;
use App\Http\Controllers\Api\V1\Member\CompanyWalletAllocationController;
use App\Http\Controllers\Api\V1\Member\EventController;
use App\Http\Controllers\Api\V1\Member\MemberDeviceTokenController;
use App\Http\Controllers\Api\V1\Member\MemberNotificationController;
use App\Http\Controllers\Api\V1\Member\MemberServiceController;
use App\Http\Controllers\Api\V1\Member\MemberServiceRequestController;
use App\Http\Controllers\Api\V1\Member\MembershipController;
use App\Http\Controllers\Api\V1\Member\PackageController;
use App\Http\Controllers\Api\V1\Member\PaymentController;
use App\Http\Controllers\Api\V1\Member\PreferencesController;
use App\Http\Controllers\Api\V1\Member\PrivacyPolicyController;
use App\Http\Controllers\Api\V1\Member\ProfileController;
use App\Http\Controllers\Api\V1\Member\PublicDirectoryConsentController;
use App\Http\Controllers\Api\V1\Member\SpaceAvailabilityController;
use App\Http\Controllers\Api\V1\Member\SpaceController;
use App\Http\Controllers\Api\V1\Member\WalletController;
use Illuminate\Support\Facades\Route;

// Every route in this file already sits behind auth:sanctum + role:member —
// applied once, from the group wrapping this file in routes/api.php. A
// company member is still just a `member` role-wise (D.8), so this group
// covers both individual and company-affiliated members with no split.

// docs/decisions/wallet-points-categorization.md ("Routing for a user with
// both a personal wallet and a company membership") — the read side of the
// hybrid-routing choice: every currently-spendable wallet for a category,
// clearly labeled by source. No spend happens here.
Route::get('wallet/options', [WalletController::class, 'options']);

// Browse spaces for members with working hours, filtering and amenities.
Route::get('spaces', [SpaceController::class, 'index']);
Route::get('spaces/{space}', [SpaceController::class, 'show']);

// docs/decisions/booking-availability-and-calendar.md — the read side of
// the booking rules: which of the space's cells are free on a day. Read-only
// and advisory; it holds no slot, so POST bookings still re-checks under a
// row lock, through the same AvailabilityService. There is no member month
// calendar — the app builds it from the space details' setup — and no member
// quote: the member picks a start and an end cell rather than a length from
// a ladder (the desk keeps its quote in admin.php).
Route::get('spaces/{space}/availability/slots', [SpaceAvailabilityController::class, 'day']);

// docs/decisions/booking-creation-approval-extension.md — member-facing
// booking creation with slot granularity/buffer/approval rules.
Route::post('bookings', [BookingController::class, 'store']);
Route::post('bookings/{booking}/extend', [BookingController::class, 'extend']);

// The member's own bookings: the list with its tab counts, one booking's
// detail with the approval stepper, self-service cancellation (the same
// BookingCancellationService reception calls), and the confirmation
// screen's price breakdown and payment options, which creates nothing.
// Static segments before the wildcard so `preview` is not swallowed by
// `{booking}`.
Route::get('bookings', [BookingController::class, 'index']);
Route::post('bookings/preview', [BookingController::class, 'preview']);
// The multi-select date strip: several windows, one request, all or
// nothing. Partial success would leave the member part-charged for a
// booking they never got.
Route::post('bookings/batch', [BookingController::class, 'storeBatch']);
Route::get('bookings/{booking}', [BookingController::class, 'show']);
Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);

// Reception kiosk "I'm here" signal (docs/decisions/kiosk-display.md) —
// never changes booking/session state by itself.
Route::post('arrival-requests', [ArrivalRequestController::class, 'store']);

// QR-scan unlock channel — docs/decisions/qr-lock-unlock.md §4. The member
// scans the sticker fixed at the lock; the backend resolves qr_value to a
// device, checks for an active access_grants row, and calls
// TTLockClient::remoteUnlock() server-side.
Route::post('access/unlock', [AccessUnlockController::class, 'unlock'])->middleware('throttle:10,1');

// Self-service for a company admin managing their own company's members
// (CompanyPolicy::manageMembers) — checked in the controller, not by role
// middleware, since it depends on the company_user pivot, not a global role.
Route::patch('companies/{company}/members/{user}', [CompanyMemberController::class, 'updateDoorAccess']);
Route::patch('companies/{company}/members/{user}/admin', [CompanyMemberController::class, 'updateAdmin']);

// Same self-service precedent as above, for allocating from the company's
// wallet (CompanyPolicy::manageMembers) — see
// docs/decisions/wallet-points-categorization.md.
Route::post('companies/{company}/wallet-allocations', [CompanyWalletAllocationController::class, 'store']);

// Browse the package catalog — "Packages" tab. Each entry carries
// `is_current`; the member's own subscription and usage is a separate call.
// `features` is capped at 3 (+ more_features_count); packages/{plan} below
// is where a client reads one plan's full, untruncated details.
Route::get('packages', [PackageController::class, 'index']);
Route::get('packages/{plan}', [PackageController::class, 'show']);

// A member buys a plan for themselves, or (via an optional company_id in the
// body, CompanyPolicy::manageMembers) a company admin buys it on behalf of
// their company — one endpoint, self-service checked in the controller.
Route::post('memberships', [MembershipController::class, 'store']);

// The member's own active plan and this-cycle usage — "My Subscription" tab.
Route::get('memberships/current', [MembershipController::class, 'current']);

// Unit 1 design (2026-08-09): both writable by the member at any time.
// preferred_language becoming writable here is a reversal of prior
// behavior — see docs/decisions/preferred-language-mutable.md.
Route::patch('preferences/currency', [PreferencesController::class, 'updateCurrency']);
Route::patch('preferences/language', [PreferencesController::class, 'updateLanguage']);

// Combined member profile endpoint — account info (UserResource) plus the
// personal and professional profile domains in one payload, replacing the
// former split profile/personal + profile/professional routes.
Route::get('profile', [ProfileController::class, 'show']);
Route::patch('profile', [ProfileController::class, 'update']);

// Member consent to appear in a future public directory (Task 5 consumes this).
Route::get('consents/public-directory', [PublicDirectoryConsentController::class, 'show']);
Route::patch('consents/public-directory', [PublicDirectoryConsentController::class, 'update']);

// Account management
Route::delete('account', [AccountController::class, 'delete']);
Route::patch('account/deactivate', [AccountController::class, 'deactivate']);
Route::patch('account/password', [AccountController::class, 'changePassword']);
Route::post('profile/image', [AccountController::class, 'updateProfileImage']);

// Privacy Policy
Route::get('privacy-policy', [PrivacyPolicyController::class, 'show']);
Route::post('privacy-policy/consent', [PrivacyPolicyController::class, 'consent']);

// Notifications — in-app notification inbox (database channel).
Route::get('notifications', [MemberNotificationController::class, 'index']);
Route::patch('notifications/{id}/read', [MemberNotificationController::class, 'markAsRead']);
Route::post('notifications/read-all', [MemberNotificationController::class, 'markAllAsRead']);
Route::get('notifications/unread-count', [MemberNotificationController::class, 'unreadCount']);

// Device token — register/remove the FCM token used for push notifications.
Route::put('device-token', [MemberDeviceTokenController::class, 'update']);
Route::delete('device-token', [MemberDeviceTokenController::class, 'destroy']);

// Mobile events calendar, categories, and details for members
Route::get('events', [EventController::class, 'index']);
Route::get('events/categories', [EventController::class, 'categories']);
Route::get('events/statuses', [EventController::class, 'statuses']);
Route::get('events/my-events', [EventController::class, 'myEvents']);
Route::get('events/{event}', [EventController::class, 'show']);
Route::get('events/{event}/registration-form', [EventController::class, 'registrationForm']);
Route::post('events/{event}/register', [EventController::class, 'register']);

// ADD Services — catalog, sections (axes), flat list, and request submission
Route::get('services', [MemberServiceController::class, 'index']);
Route::get('services/axes', [MemberServiceController::class, 'axes']);
Route::get('services/axes/{axis}', [MemberServiceController::class, 'axisServices']);
Route::get('services/all', [MemberServiceController::class, 'all']);
Route::get('services/{slug}', [MemberServiceController::class, 'show']);
// The form and submission endpoints are keyed on the service id, like events' own
// registration-form, while the catalog reads above stay on the slug.
Route::get('services/{id}/registration-form', [MemberServiceController::class, 'registrationForm'])->whereNumber('id');
Route::post('services/{id}/requests', [MemberServiceController::class, 'submitRequest'])->whereNumber('id');

// Member's own service request history
Route::get('service-requests', [MemberServiceRequestController::class, 'index']);
Route::get('service-requests/{serviceRequest}', [MemberServiceRequestController::class, 'show']);

// Card payments (docs/paymera.md). The app asks for a payment URL here,
// opens it in a WebView, and polls `payments/{payment}` when the WebView
// closes — it never talks to Paymera directly, so the API key stays server
// side. `show` re-syncs with the gateway on read, which is safe to poll:
// SyncPaymentStatus locks and refuses to re-process a settled payment.
Route::get('payments', [PaymentController::class, 'index']);
Route::post('payments', [PaymentController::class, 'store']);
Route::get('payments/{payment}', [PaymentController::class, 'show']);
