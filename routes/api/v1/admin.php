<?php

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Services\Models\ServiceType;
use App\Http\Controllers\Api\V1\Admin\AdminEventAttendeeController;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\AdminServiceRequestController;
use App\Http\Controllers\Api\V1\Admin\AdminServiceTypeController;
use App\Http\Controllers\Api\V1\Admin\AmenityController;
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
use App\Http\Controllers\Api\V1\Admin\CompanyProviderController;
use App\Http\Controllers\Api\V1\Admin\ContactLinkController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CoworkingPoolController;
use App\Http\Controllers\Api\V1\Admin\CoworkingWindowController;
use App\Http\Controllers\Api\V1\Admin\CurrencyController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DashboardDeviceTokenController;
use App\Http\Controllers\Api\V1\Admin\DashboardStatsController;
use App\Http\Controllers\Api\V1\Admin\DeviceCapabilityController;
use App\Http\Controllers\Api\V1\Admin\DeviceController;
use App\Http\Controllers\Api\V1\Admin\DiscountReportController;
use App\Http\Controllers\Api\V1\Admin\ErrorLogController;
use App\Http\Controllers\Api\V1\Admin\EventCategoryController;
use App\Http\Controllers\Api\V1\Admin\EventController;
use App\Http\Controllers\Api\V1\Admin\ExchangeRateController;
use App\Http\Controllers\Api\V1\Admin\ExchangeRateSuggestionController;
use App\Http\Controllers\Api\V1\Admin\FloorController;
use App\Http\Controllers\Api\V1\Admin\FounderController;
use App\Http\Controllers\Api\V1\Admin\HelpSupportController;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Admin\InvoicePaymentController;
use App\Http\Controllers\Api\V1\Admin\ItemSaleController;
use App\Http\Controllers\Api\V1\Admin\KioskController;
use App\Http\Controllers\Api\V1\Admin\KioskPairingController;
use App\Http\Controllers\Api\V1\Admin\MediaUploadController;
use App\Http\Controllers\Api\V1\Admin\MemberProfileController;
use App\Http\Controllers\Api\V1\Admin\MembershipController;
use App\Http\Controllers\Api\V1\Admin\NotificationInboxController;
use App\Http\Controllers\Api\V1\Admin\OfferReportController;
use App\Http\Controllers\Api\V1\Admin\OfficeLeaseController;
use App\Http\Controllers\Api\V1\Admin\PartnerController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\PaymentMethodController as AdminPaymentMethodController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\PlanModuleController;
use App\Http\Controllers\Api\V1\Admin\PrivacyPolicyController;
use App\Http\Controllers\Api\V1\Admin\PrivateOfficeRequestController;
use App\Http\Controllers\Api\V1\Admin\ProviderController;
use App\Http\Controllers\Api\V1\Admin\ProviderRequestController;
use App\Http\Controllers\Api\V1\Admin\ProviderUserController;
use App\Http\Controllers\Api\V1\Admin\Reception\AccessActivationController;
use App\Http\Controllers\Api\V1\Admin\Reception\ArrivalRequestController as ReceptionArrivalRequestController;
use App\Http\Controllers\Api\V1\Admin\Reception\BookingReceptionController;
use App\Http\Controllers\Api\V1\Admin\Reception\CashRegisterSessionController;
use App\Http\Controllers\Api\V1\Admin\Reception\ItemSaleController as ReceptionItemSaleController;
use App\Http\Controllers\Api\V1\Admin\Reception\PlanPurchaseController;
use App\Http\Controllers\Api\V1\Admin\Reception\ReceptionPaymentController;
use App\Http\Controllers\Api\V1\Admin\Reception\ReceptionSessionsController;
use App\Http\Controllers\Api\V1\Admin\Reception\ReceptionSummaryController;
use App\Http\Controllers\Api\V1\Admin\Reception\ResourceConsumptionController;
use App\Http\Controllers\Api\V1\Admin\Reception\SessionEndRequestController;
use App\Http\Controllers\Api\V1\Admin\Reception\WalkInSessionController;
use App\Http\Controllers\Api\V1\Admin\Reception\WalletTopUpController;
use App\Http\Controllers\Api\V1\Admin\RenewalController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ResourceTypeController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SaleItemController;
use App\Http\Controllers\Api\V1\Admin\SeatDeskController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\SiteInquiryController;
use App\Http\Controllers\Api\V1\Admin\SpaceController;
use App\Http\Controllers\Api\V1\Admin\SpaceTypeAmenityController;
use App\Http\Controllers\Api\V1\Admin\TermsOfUseController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\ZoneController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Routing\FormFieldRoutes;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Support\Facades\Route;

// Every route in this file sits behind auth:sanctum + abilities:dashboard,
// applied once by the group wrapping this file in routes/api.php — and no
// role check. Each route names the permission that reaches it (Spatie
// `permission:` middleware, `|` = any of), so any role, whatever its name,
// reaches exactly what its permissions grant
// (docs/decisions/rbac-permission-pilot.md, 2026-10-27). These middleware
// are also the only source of permission names: PermissionSyncService reads
// them from the registered routes, so a permission that guards nothing
// cannot exist. tests/Guards/AdminRoutesArePermissionGatedTest fails on a
// route here without one — `me`, `media/upload` and the account's own
// notifications inbox and browser push token are the only exceptions.

// An apiResource's verbs, each behind its module's permission: index/show →
// `<module>.view`, store → `.create`, update → `.update`, destroy → `.delete`.
$permitted = fn (PendingResourceRegistration $resource, string $module): PendingResourceRegistration => $resource
    ->middlewareFor(['index', 'show'], "permission:{$module}.view")
    ->middlewareFor('store', "permission:{$module}.create")
    ->middlewareFor('update', "permission:{$module}.update")
    ->middlewareFor('destroy', "permission:{$module}.delete");

// The routes with no resource of their own: the account's own record, the
// upload every content form uses, and the account's own notifications (what
// reaches it by the permissions it holds) with the browser they are pushed
// to. Any staff account — one holding at least one permission — reaches
// them (App\Http\Middleware\EnsureStaffAccount).
Route::get('me', [CurrentUserController::class, 'show'])->middleware('staff');
Route::post('media/upload', [MediaUploadController::class, 'upload'])->middleware('staff');
Route::get('notifications', [NotificationInboxController::class, 'index'])->middleware('staff');
Route::get('notifications/unread-count', [NotificationInboxController::class, 'unreadCount'])->middleware('staff');
Route::patch('notifications/{id}/read', [NotificationInboxController::class, 'markAsRead'])->middleware('staff');
Route::post('notifications/read-all', [NotificationInboxController::class, 'markAllAsRead'])->middleware('staff');
Route::put('device-token', [DashboardDeviceTokenController::class, 'update'])->middleware('staff');
Route::delete('device-token', [DashboardDeviceTokenController::class, 'destroy'])->middleware('staff');

// The member app's static pages: Privacy Policy, Terms of Use, Help & Support.
Route::get('privacy-policy', [PrivacyPolicyController::class, 'show'])->middleware('permission:app-content.view');
Route::patch('privacy-policy', [PrivacyPolicyController::class, 'update'])->middleware('permission:app-content.update');
Route::get('terms-of-use', [TermsOfUseController::class, 'show'])->middleware('permission:app-content.view');
Route::patch('terms-of-use', [TermsOfUseController::class, 'update'])->middleware('permission:app-content.update');
Route::get('help-support', [HelpSupportController::class, 'show'])->middleware('permission:app-content.view');
Route::patch('help-support', [HelpSupportController::class, 'update'])->middleware('permission:app-content.update');

// Settings — global key/value config (docs/decisions/settings-key-value-store.md).
Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
Route::patch('settings/{key}', [SettingController::class, 'update'])->middleware('permission:settings.update');

// Spatial Hierarchy — Branch is the top level (docs/decisions/district-removed.md).
// A destroy here cascades through the DB's own FKs and can wipe out every
// Building/Floor/Zone/Space/Resource/SeatDesk/Device beneath it in one
// request, so `<module>.delete` is its own grant — given to admin, not to
// operations (which never had these destroys).
Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view')->name('branches.index');
Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:branches.view')->name('branches.show');
Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.create')->name('branches.store');
Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.update')->name('branches.update');
Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:branches.delete')->name('branches.destroy');

$permitted(Route::apiResource('buildings', BuildingController::class), 'buildings');
$permitted(Route::apiResource('floors', FloorController::class), 'floors');
$permitted(Route::apiResource('zones', ZoneController::class), 'zones');
$permitted(Route::apiResource('spaces', SpaceController::class), 'spaces');
Route::patch('spaces/{space}/status', [SpaceController::class, 'updateStatus'])->middleware('permission:spaces.update');
Route::patch('spaces/{space}/amenities', [SpaceController::class, 'updateAmenities'])->middleware('permission:spaces.update');

// The amenity catalog (Wi-Fi, café access, a stage — what a space offers
// without being equipment to track; equipment is an inventory asset).
$permitted(Route::apiResource('amenities', AmenityController::class), 'amenities');

// The type -> amenity config matrix (which catalog amenities are even
// selectable for a given SpaceType) — a fixed 4-row config, not a
// paginated CRUD resource, so it isn't a Route::apiResource line. Part of
// the amenity catalog, so behind its permissions.
Route::get('space-type-amenities', [SpaceTypeAmenityController::class, 'index'])->middleware('permission:amenities.view');
Route::put('space-type-amenities/{spaceType}', [SpaceTypeAmenityController::class, 'update'])->middleware('permission:amenities.update');

// Multi-word resource name — same reason as community-members below.
$permitted(Route::apiResource('seats-desks', SeatDeskController::class)
    ->parameters(['seats-desks' => 'seatDesk']), 'seats-desks');

$permitted(Route::apiResource('devices', DeviceController::class), 'devices');
Route::post('devices/{device}/regenerate-qr-value', [DeviceController::class, 'regenerateQrValue'])->middleware('permission:devices.update');

// Multi-word resource name — same reason as community-members below.
$permitted(Route::apiResource('device-capabilities', DeviceCapabilityController::class)
    ->parameters(['device-capabilities' => 'deviceCapability']), 'device-capabilities');

// Business hours — per-branch recurring weekly schedule
// (docs/decisions/business-hours.md). Multi-word resource name — same
// reason as community-members below.
$permitted(Route::apiResource('business-hours', BusinessHourController::class)
    ->parameters(['business-hours' => 'businessHour']), 'business-hours');

// Multi-word resource name — same reason as community-members below.
$permitted(Route::apiResource('business-hour-exceptions', BusinessHourExceptionController::class)
    ->parameters(['business-hour-exceptions' => 'businessHourException']), 'business-hour-exceptions');

$permitted(Route::apiResource('founders', FounderController::class), 'founders');
$permitted(Route::apiResource('partners', PartnerController::class), 'partners');
$permitted(Route::apiResource('plans', PlanController::class), 'plans');
$permitted(Route::apiResource('plan-modules', PlanModuleController::class)
    ->parameters(['plan-modules' => 'planModule']), 'plan-modules');

// Discount coupons (docs/decisions/pricing-offers-and-coupons.md): looking
// one up, and who used it, is `coupons.view`; creating, changing and
// deleting one are their own grants — a code is money off.
Route::get('coupons', [CouponController::class, 'index'])->middleware('permission:coupons.view');
Route::get('coupons/{coupon}', [CouponController::class, 'show'])->middleware('permission:coupons.view');
Route::get('coupons/{coupon}/redemptions', [CouponController::class, 'redemptions'])->middleware('permission:coupons.view');
Route::get('coupons/{coupon}/revisions', [CouponController::class, 'revisions'])->middleware('permission:coupons.view');
Route::post('coupons', [CouponController::class, 'store'])->middleware('permission:coupons.create');
Route::match(['put', 'patch'], 'coupons/{coupon}', [CouponController::class, 'update'])->middleware('permission:coupons.update');
Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->middleware('permission:coupons.delete');

// Reports — one grant, `reports.view`, for all four.
// What was given off, and saved, over a period — every operation with its
// status; totals over the ones that count
// (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
Route::get('reports/discounts', [DiscountReportController::class, 'index'])->middleware('permission:reports.view');

// Which offers (or, `type=coupon`, coupons) ran in a period, on the terms
// they had then, and what each gave — from every version an admin saved
// (CouponHistory), so a changed or deleted offer still shows where it ran.
Route::get('reports/offers', [OfferReportController::class, 'index'])->middleware('permission:reports.view');

// Invoice reports (docs/decisions/provider-reports.md).
Route::get('reports/revenue', [ReportController::class, 'revenue'])->middleware('permission:reports.view');
Route::get('reports/providers', [ReportController::class, 'providers'])->middleware('permission:reports.view');

// Resource-quota catalog (docs/decisions/resource-quota-design.md). A type
// may name the inventory stock it uses up (printing: paper), issued when
// reception records consumption.
$permitted(Route::apiResource('resource-types', ResourceTypeController::class)
    ->parameters(['resource-types' => 'resourceType']), 'resource-types');

// Selling stock at reception: what may be sold and its price in points
// (the stock itself is the inventory's), and the sales made.
$permitted(Route::apiResource('sale-items', SaleItemController::class)
    ->parameters(['sale-items' => 'saleItem']), 'sale-items');
Route::get('item-sales', [ItemSaleController::class, 'index'])->middleware('permission:item-sales.view');
Route::get('item-sales/{itemSale}', [ItemSaleController::class, 'show'])->middleware('permission:item-sales.view');

// Contact Links — public org content (social/app-store/website/phone
// links), admin-managed — see
// docs/decisions/profile-fields-completion-score-contact-links.md.
$permitted(Route::apiResource('contact-links', ContactLinkController::class)
    ->parameters(['contact-links' => 'contactLink']), 'contact-links');

// Banner content for the reception kiosk and the member app's home
// (docs/decisions/kiosk-display.md). `options` before the resource, so
// `{announcement}` does not swallow it.
Route::get('announcements/options', [AnnouncementController::class, 'options'])->middleware('permission:announcements.view');
$permitted(Route::apiResource('announcements', AnnouncementController::class), 'announcements');
$permitted(Route::apiResource('event-categories', EventCategoryController::class)
    ->parameters(['event-categories' => 'eventCategory']), 'event-categories');
Route::get('events/rooms', [EventController::class, 'rooms'])->middleware('permission:events.view');
Route::get('events/statuses', [EventController::class, 'statuses'])->middleware('permission:events.view');
$permitted(Route::apiResource('events', EventController::class), 'events');

// Per-event registration form definition, and the attendee roster with its
// approve/reject decisions. All nested under an already-bound event, so
// they never collide with the apiResource above. The field editor is the
// shared one every form owner gets (App\Http\Routing\FormFieldRoutes):
// reading it is `events.view`, changing it `events.update`. Deciding on
// attendees, and letting them in at the door, is its own grant.
FormFieldRoutes::register('events/{event}/registration-fields', Event::class, FormPurpose::Registration, 'events.view', 'events.update');

Route::get('events/{event}/attendees', [AdminEventAttendeeController::class, 'index'])->middleware('permission:events.view');
Route::get('events/{event}/attendees/pending', [AdminEventAttendeeController::class, 'pending'])->middleware('permission:events.view');
Route::post('events/{event}/attendees/reject-pending', [AdminEventAttendeeController::class, 'rejectPending'])->middleware('permission:events.manage_attendees');
Route::post('events/{event}/attendees/{attendee}/approve', [AdminEventAttendeeController::class, 'approve'])->middleware('permission:events.manage_attendees');
Route::post('events/{event}/attendees/{attendee}/reject', [AdminEventAttendeeController::class, 'reject'])->middleware('permission:events.manage_attendees');
// The door: approved, and for a paid event paid (docs/decisions/unified-payments.md).
Route::post('events/{event}/attendees/{attendee}/check-in', [AdminEventAttendeeController::class, 'checkIn'])->middleware('permission:events.manage_attendees');

// Exchange rates: reading the rates and today's suggestion is
// `exchange-rates.view`; recording a rate — by hand, by sync, or by
// dismissing the suggestion — is `exchange-rates.create`.
Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->middleware('permission:exchange-rates.view');
Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->middleware('permission:exchange-rates.create');
Route::post('exchange-rates/sync', [ExchangeRateController::class, 'sync'])->middleware('permission:exchange-rates.create');
Route::get('exchange-rates/suggestion', [ExchangeRateSuggestionController::class, 'show'])->middleware('permission:exchange-rates.view');
Route::post('exchange-rates/suggestion/{exchangeRateSuggestion}/dismiss', [ExchangeRateSuggestionController::class, 'dismiss'])->middleware('permission:exchange-rates.create');

// Currencies — admin-managed lookup replacing the old hardcoded USD/SYP
// enum (docs/decisions/multi-currency-support.md). Not a generic
// apiResource: no destroy (a currency deactivates via status, it never
// hard-deletes — plans/spaces/bookings/users all carry a real FK to
// currencies.code), same reasoning UserController uses for its own
// deviations. Status and base changes are `currencies.update`.
Route::get('currencies', [CurrencyController::class, 'index'])->middleware('permission:currencies.view');
Route::post('currencies', [CurrencyController::class, 'store'])->middleware('permission:currencies.create');
Route::get('currencies/{currency}', [CurrencyController::class, 'show'])->middleware('permission:currencies.view');
Route::patch('currencies/{currency}', [CurrencyController::class, 'update'])->middleware('permission:currencies.update');
Route::patch('currencies/{currency}/status', [CurrencyController::class, 'updateStatus'])->middleware('permission:currencies.update');
Route::patch('currencies/{currency}/base', [CurrencyController::class, 'updateBase'])->middleware('permission:currencies.update');

// Multi-word resource name — Laravel's auto-derived {community_member}
// placeholder (snake_case) won't implicit-bind to a camelCase controller
// argument, so the parameter name is pinned explicitly here.
$permitted(Route::apiResource('community-members', CommunityMemberController::class)
    ->parameters(['community-members' => 'communityMember']), 'community-members');

// Multi-word resource name — same reason as community-members above.
$permitted(Route::apiResource('private-office-requests', PrivateOfficeRequestController::class)
    ->parameters(['private-office-requests' => 'privateOfficeRequest']), 'private-office-requests');

// Companies: not a generic apiResource — no destroy (contractual entities
// deactivate, they don't hard-delete, same reasoning as UserController), and
// "update" is specifically the status transition, not a free-form PATCH.
Route::get('companies', [CompanyController::class, 'index'])->middleware('permission:companies.view');
// Creating a company contracts its first lease, so it is that permission
// (docs/decisions/office-leases.md).
Route::post('companies', [CompanyController::class, 'store'])->middleware('permission:office-leases.create');
Route::get('companies/{company}', [CompanyController::class, 'show'])->middleware('permission:companies.view');
Route::patch('companies/{company}/status', [CompanyController::class, 'updateStatus'])->middleware('permission:companies.update_status');

Route::get('companies/{company}/members', [CompanyMemberController::class, 'index'])->middleware('permission:companies.view');
Route::post('companies/{company}/members', [CompanyMemberController::class, 'store'])->middleware('permission:companies.manage_members');
Route::patch('companies/{company}/members/{user}', [CompanyMemberController::class, 'updateDoorAccess'])->middleware('permission:companies.manage_members');
Route::patch('companies/{company}/members/{user}/admin', [CompanyMemberController::class, 'updateAdmin'])->middleware('permission:companies.manage_members');
Route::delete('companies/{company}/members/{user}', [CompanyMemberController::class, 'destroy'])->middleware('permission:companies.manage_members');

// Service providers (docs/decisions/service-providers.md): each route names
// its permission, so deciding provider requests can be given to any role.
Route::get('providers', [ProviderController::class, 'index'])->middleware('permission:providers.view');
Route::post('providers', [ProviderController::class, 'store'])->middleware('permission:providers.create');
Route::get('providers/{provider}', [ProviderController::class, 'show'])->middleware('permission:providers.view');
Route::patch('providers/{provider}', [ProviderController::class, 'update'])->middleware('permission:providers.update');
Route::post('providers/{provider}/users/{user}', [ProviderUserController::class, 'store'])->middleware('permission:providers.update');
Route::delete('providers/{provider}/users/{user}', [ProviderUserController::class, 'destroy'])->middleware('permission:providers.update');
Route::patch('providers/{provider}/status', [ProviderController::class, 'updateStatus'])->middleware('permission:providers.status');
Route::patch('companies/{company}/provider', [CompanyProviderController::class, 'update'])->middleware('permission:providers.transfer_company');
Route::get('provider-requests', [ProviderRequestController::class, 'index'])->middleware('permission:provider-requests.view');
Route::get('provider-requests/{providerRequest}', [ProviderRequestController::class, 'show'])->middleware('permission:provider-requests.view');
Route::post('provider-requests/{providerRequest}/approve', [ProviderRequestController::class, 'approve'])->middleware('permission:provider-requests.approve');
Route::post('provider-requests/{providerRequest}/reject', [ProviderRequestController::class, 'reject'])->middleware('permission:provider-requests.reject');

// Provider invoicing (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md).
Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view');
Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view');
Route::post('invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])->middleware('permission:invoices.record_payment');
Route::post('invoice-payments/{invoicePayment}/void', [InvoicePaymentController::class, 'void'])->middleware('permission:invoices.void_payment');
Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoices.cancel');

// Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md).
Route::post('private-office-requests/{privateOfficeRequest}/lease', [OfficeLeaseController::class, 'store'])->middleware('permission:office-leases.create');
Route::get('office-leases', [OfficeLeaseController::class, 'index'])->middleware('permission:office-leases.view');
Route::get('office-leases/{officeLease}', [OfficeLeaseController::class, 'show'])->middleware('permission:office-leases.view');
Route::post('office-leases/{officeLease}/terminate', [OfficeLeaseController::class, 'terminate'])->middleware('permission:office-leases.terminate');

// Auto-renewal (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
Route::get('renewals/stopped', [RenewalController::class, 'stopped'])->middleware('permission:renewals.manage');
Route::post('memberships/{membership}/renew', [RenewalController::class, 'renewMembership'])->middleware('permission:renewals.manage');
Route::post('office-leases/{officeLease}/renew', [RenewalController::class, 'renewLease'])->middleware('permission:renewals.manage');
Route::patch('memberships/{membership}/auto-renew', [RenewalController::class, 'membershipAutoRenew'])->middleware('permission:renewals.manage');
Route::patch('office-leases/{officeLease}/auto-renew', [RenewalController::class, 'leaseAutoRenew'])->middleware('permission:renewals.manage');

// Mobile client crash/error reports (ingested unauthenticated from
// routes/api/v1/mobile.php) — reading them and deleting one are separate
// grants.
Route::get('error-logs', [ErrorLogController::class, 'index'])->middleware('permission:error-logs.view');
Route::get('error-logs/{errorLog}', [ErrorLogController::class, 'show'])->middleware('permission:error-logs.view');
Route::delete('error-logs/{errorLog}', [ErrorLogController::class, 'destroy'])->middleware('permission:error-logs.delete');

// One member's whole file for the dashboard's member profile: a summary,
// then sessions, wallet history, memberships, service requests and event
// registrations. Bookings and payments use bookings?user_id= and
// payments?user_id=. Read-only.
Route::prefix('members/{user}')->whereNumber('user')->middleware('permission:members.view')->group(function () {
    Route::get('summary', [MemberProfileController::class, 'summary']);
    Route::get('sessions', [MemberProfileController::class, 'sessions']);
    Route::get('wallet-transactions', [MemberProfileController::class, 'walletTransactions']);
    Route::get('memberships', [MemberProfileController::class, 'memberships']);
    Route::get('service-requests', [MemberProfileController::class, 'serviceRequests']);
    Route::get('event-registrations', [MemberProfileController::class, 'eventRegistrations']);
});

// docs/decisions/booking-availability-and-calendar.md — the operations
// calendar (spaces as rows, days as cells, occupancy per cell) and the
// general bookings index. Read-only: every write verb lives on the
// reception routes below. Occupancy is computed from the same
// BusinessHoursService periods the member calendar uses, so the two can
// never disagree about what "open" means.
Route::get('bookings', [BookingController::class, 'index'])->middleware('permission:bookings.view');
Route::get('bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
Route::get('calendar', [BookingCalendarController::class, 'index'])->middleware('permission:bookings.view');
Route::get('calendar/occupancy', [BookingCalendarController::class, 'occupancy'])->middleware('permission:bookings.view');

// The cells of one space on one date, so the desk's booking form offers
// the windows BookingCreationService will actually accept instead of a
// free-form clock that learns the rules one 422 at a time. Same
// AvailabilityBrowseService the member routes call -- the read side and the
// write side cannot disagree because there is one of each. No duration and
// no quote: the desk picks a run of cells, like the member app. Read by the
// calendar and by the desk's booking form, so either grant reaches it.
Route::get('spaces/{space}/availability/slots', [BookingCalendarController::class, 'slots'])->middleware('permission:bookings.view|reception.book');

// The branch's co-working seat pool (docs/decisions/coworking-seat-pool.md):
// its seats taken and free over time, and the rooms lent to co-working when
// the co-working space is overloaded. Part of a space's set-up, so behind
// the spaces permissions.
Route::get('branches/{branch}/coworking-pool', [CoworkingPoolController::class, 'show'])->middleware('permission:spaces.view');
Route::get('spaces/{space}/coworking-windows', [CoworkingWindowController::class, 'index'])->middleware('permission:spaces.view');
Route::post('spaces/{space}/coworking-windows', [CoworkingWindowController::class, 'store'])->middleware('permission:spaces.update');
Route::patch('coworking-windows/{coworkingWindow}', [CoworkingWindowController::class, 'update'])->middleware('permission:spaces.update');
Route::delete('coworking-windows/{coworkingWindow}', [CoworkingWindowController::class, 'destroy'])->middleware('permission:spaces.update');

// Reception Operations (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
// — check-in, check-out, cancellation and payment settlement for bookings.
// One grant per kind of desk work: seeing the desk (`reception.view`),
// letting people in and out (`reception.check_in`), booking for them
// (`reception.book`), deciding approvals (`reception.approve`), taking
// money (`reception.settle_payment`) and recording consumption.
Route::get('reception/bookings/pending-approval', [BookingReceptionController::class, 'pendingApproval'])->middleware('permission:reception.view');
// Reception booking a member in at the desk, and the calendar's "book it
// for them" action. Reuses BookingCreationService, so every availability
// rule the member endpoint enforces applies here too.
Route::post('reception/bookings', [BookingReceptionController::class, 'store'])->middleware('permission:reception.book');
// What that booking would cost — points and money, from the space's hourly
// rate and the hours chosen — before the desk picks how it is paid.
Route::post('reception/bookings/quote', [BookingReceptionController::class, 'quote'])->middleware('permission:reception.book');
Route::post('reception/bookings/{booking}/check-in', [BookingReceptionController::class, 'checkIn'])->middleware('permission:reception.check_in');
Route::post('reception/bookings/{booking}/check-out', [BookingReceptionController::class, 'checkOut'])->middleware('permission:reception.check_in');
Route::post('reception/bookings/{booking}/cancel', [BookingReceptionController::class, 'cancel'])->middleware('permission:reception.book');
Route::post('reception/bookings/{booking}/settle-payment', [BookingReceptionController::class, 'settlePayment'])->middleware('permission:reception.settle_payment');
Route::post('reception/bookings/{booking}/approve', [BookingReceptionController::class, 'approve'])->middleware('permission:reception.approve');
Route::post('reception/bookings/{booking}/reject', [BookingReceptionController::class, 'reject'])->middleware('permission:reception.approve');
Route::post('reception/bookings/{booking}/extend', [BookingReceptionController::class, 'extend'])->middleware('permission:reception.book');
Route::get('reception/bookings/{booking}/extension-options', [BookingReceptionController::class, 'extensionOptions'])->middleware('permission:reception.view');
// Access-grant read surface for reception (final-review C3b) — resolves the
// grant a given booking needs activated, since there was previously no way
// to discover a grant's id from its booking.
Route::get('reception/bookings/{booking}/access-grant', [AccessActivationController::class, 'forBooking'])->middleware('permission:reception.view');

Route::post('reception/walk-ins', [WalkInSessionController::class, 'store'])->middleware('permission:reception.check_in');
Route::post('reception/walk-ins/{walkinSession}/check-out', [WalkInSessionController::class, 'checkOut'])->middleware('permission:reception.check_in');
Route::post('reception/walk-ins/{walkinSession}/settle-payment', [WalkInSessionController::class, 'settlePayment'])->middleware('permission:reception.settle_payment');

// Settle anything a member owes with the money handed over (overpayment
// stays as points), and list everything they owe (docs/decisions/unified-payments.md).
Route::post('reception/payments/settle', [ReceptionPaymentController::class, 'settle'])->middleware('permission:reception.settle_payment');
Route::post('reception/item-sales', [ReceptionItemSaleController::class, 'store'])->middleware('permission:item-sales.create');
Route::get('reception/outstanding', [ReceptionPaymentController::class, 'outstanding'])->middleware('permission:reception.view');

// The desk's day at a glance: counts and what needs it next
// (the reception desk screen).
Route::get('reception/summary', [ReceptionSummaryController::class, 'show'])->middleware('permission:reception.view');
Route::get('reception/sessions/active', [ReceptionSessionsController::class, 'active'])->middleware('permission:reception.view');
Route::get('reception/sessions/{type}/{id}/report', [ReceptionSessionsController::class, 'report'])
    ->whereIn('type', ['booking', 'walkin'])
    ->whereNumber('id')
    ->middleware('permission:reception.view');

// A member's "end session" press, confirmed here
// (docs/decisions/coworking-self-service-sessions.md).
Route::post('reception/session-end-requests/{sessionEndRequest}/confirm', [SessionEndRequestController::class, 'confirm'])->middleware('permission:reception.check_in');

Route::get('reception/arrival-requests', [ReceptionArrivalRequestController::class, 'index'])->middleware('permission:reception.view');
Route::post('reception/arrival-requests/{arrivalRequest}/confirm', [ReceptionArrivalRequestController::class, 'confirm'])->middleware('permission:reception.check_in');
Route::post('reception/arrival-requests/{arrivalRequest}/reject', [ReceptionArrivalRequestController::class, 'reject'])->middleware('permission:reception.check_in');

// A wallet's balance is read by the top-up screen and the member profile.
Route::get('reception/wallet-balance', [WalletTopUpController::class, 'balance'])->middleware('permission:wallet-top-ups.view|members.view');
Route::post('reception/wallet-top-ups', [WalletTopUpController::class, 'store'])->middleware('permission:wallet-top-ups.create');

// A plan sold for cash at the desk, the way a wallet is topped up: the
// plan's price exactly, in the currency chosen, becomes points in the
// owner's wallet and is spent on the plan at once (docs/decisions/unified-payments.md).
Route::post('reception/plan-purchases/preview', [PlanPurchaseController::class, 'preview'])->middleware('permission:plan-purchases.create');
Route::post('reception/plan-purchases', [PlanPurchaseController::class, 'store'])->middleware('permission:plan-purchases.create');
Route::post('reception/access-grants/{accessGrant}/activate', [AccessActivationController::class, 'activate'])->middleware('permission:reception.check_in');

// Manual-entry recording of natural-unit resource consumption (Printing,
// Internet, ...) against a member/company's active membership's
// PlanResourceGrant (docs/decisions/resource-quota-design.md). Same
// user_id/company_id owner-resolution pattern as wallet-top-ups above.
Route::post('reception/resource-consumptions', [ResourceConsumptionController::class, 'store'])->middleware('permission:reception.record_consumption');

// Cash Register Sessions per staff member per shift (S6-BE-03 / PRD 5.9)
Route::get('reception/cash-register-sessions', [CashRegisterSessionController::class, 'index'])->middleware('permission:cash-register.view');
Route::get('reception/cash-register-sessions/current', [CashRegisterSessionController::class, 'current'])->middleware('permission:cash-register.view');
Route::post('reception/cash-register-sessions/open', [CashRegisterSessionController::class, 'open'])->middleware('permission:cash-register.manage');
Route::post('reception/cash-register-sessions/{cashRegisterSession}/close', [CashRegisterSessionController::class, 'close'])->middleware('permission:cash-register.manage');

// Site Inquiries from public website (S7-BE-06)
Route::get('site-inquiries', [SiteInquiryController::class, 'index'])->middleware('permission:site-inquiries.view');
Route::get('site-inquiries/{siteInquiry}', [SiteInquiryController::class, 'show'])->middleware('permission:site-inquiries.view');
Route::patch('site-inquiries/{siteInquiry}', [SiteInquiryController::class, 'update'])->middleware('permission:site-inquiries.update');

// Reading users is also the desk's member search, so any of these reaches it.
Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view|members.view|reception.view');
Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view|members.view|reception.view');

// Creating and changing accounts, and managing roles, are each their own
// grant — whoever holds `users.assign_role` or `roles.update` can hand out
// any permission, so these stay with admin unless deliberately given.
Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.update_status');
Route::patch('users/{user}/role', [UserController::class, 'assignRole'])->middleware('permission:users.assign_role');

Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');

// Activating a plan for someone with no wallet debit is a money-adjacent
// grant, so it is its own permission (`memberships.create`), apart from
// reading them.
Route::get('memberships', [MembershipController::class, 'index'])->middleware('permission:memberships.view');
Route::post('memberships', [MembershipController::class, 'store'])->middleware('permission:memberships.create');
Route::put('memberships/{membership}/desks', [MembershipController::class, 'assignDesks'])->middleware('permission:memberships.create');

// Notification broadcast — sends push + in-app notifications to targeted
// users (all, by role, or specific user IDs).
Route::post('notifications/broadcast', [AdminNotificationController::class, 'broadcast'])->middleware('permission:notifications.broadcast');

// ADD Service Types — CRUD for the service catalog; the request-form
// field editor is the shared one every form owner gets (reading it is
// `service-types.view`, changing it `service-types.update`).
Route::get('service-types', [AdminServiceTypeController::class, 'index'])->middleware('permission:service-types.view');
Route::post('service-types', [AdminServiceTypeController::class, 'store'])->middleware('permission:service-types.create');
Route::get('service-types/{serviceType}', [AdminServiceTypeController::class, 'show'])->middleware('permission:service-types.view');
Route::patch('service-types/{serviceType}', [AdminServiceTypeController::class, 'update'])->middleware('permission:service-types.update');
Route::delete('service-types/{serviceType}', [AdminServiceTypeController::class, 'destroy'])->middleware('permission:service-types.delete');
FormFieldRoutes::register('service-types/{serviceType}/fields', ServiceType::class, FormPurpose::Request, 'service-types.view', 'service-types.update');

// ADD Service Requests — staff view all, filter by branch/status/type
Route::get('service-requests', [AdminServiceRequestController::class, 'index'])->middleware('permission:service-requests.view');
Route::get('service-requests/{serviceRequest}', [AdminServiceRequestController::class, 'show'])->middleware('permission:service-requests.view');
Route::patch('service-requests/{serviceRequest}', [AdminServiceRequestController::class, 'update'])->middleware('permission:service-requests.update');

// Kiosk devices that start self-service coworking sessions
// (docs/decisions/coworking-self-service-sessions.md). Pairing a kiosk, or
// revoking its secret, is its own grant: a kiosk's pairing is a credential.
$permitted(Route::apiResource('kiosks', KioskController::class), 'kiosks');
Route::post('kiosks/{kiosk}/pairing-code', [KioskPairingController::class, 'issue'])->middleware('permission:kiosks.pair');
Route::delete('kiosks/{kiosk}/secret', [KioskPairingController::class, 'revoke'])->middleware('permission:kiosks.pair');

/*
 * Card payments (docs/paymera.md). Reading the ledger is `payments.view`;
 * the reversal — which moves real money at the bank — is its own grant,
 * `payments.reverse`.
 *
 * There is no store/update/destroy: a payment is created by a member
 * paying and settled by the gateway, never edited by hand.
 */
Route::get('payments', [AdminPaymentController::class, 'index'])->middleware('permission:payments.view')->name('payments.index');
// Totals over the same filters as the list; before payments/{payment}, which would take it for an id.
Route::get('payments/summary', [AdminPaymentController::class, 'summary'])->middleware('permission:payments.view')->name('payments.summary');
// Every payment method in config, enabled or not, with how it has been used. Read-only.
Route::get('payment-methods', [AdminPaymentMethodController::class, 'index'])->middleware('permission:payment-methods.view')->name('payment-methods.index');
Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])->middleware('permission:payments.view')->name('payments.show');
Route::post('payments/{payment}/reverse', [AdminPaymentController::class, 'reverse'])
    ->middleware('permission:payments.reverse')
    ->name('payments.reverse');

// The dashboard's home page: one section per permission, so each role sees
// the sections its permissions grant — admin all of them.
Route::get('dashboard/operations', [DashboardController::class, 'operations'])->middleware('permission:dashboard.operations');
Route::get('dashboard/finance', [DashboardController::class, 'finance'])->middleware('permission:dashboard.finance');
Route::get('dashboard/members', [DashboardController::class, 'members'])->middleware('permission:dashboard.members');
Route::get('dashboard/spaces', [DashboardController::class, 'spaces'])->middleware('permission:dashboard.spaces');
// One statistics card each, behind its section's permission.
Route::get('dashboard/stats/bookings-by-status', [DashboardStatsController::class, 'bookingsByStatus'])->middleware('permission:dashboard.operations');
Route::get('dashboard/stats/service-requests', [DashboardStatsController::class, 'serviceRequests'])->middleware('permission:dashboard.operations');
Route::get('dashboard/stats/daily-bookings', [DashboardStatsController::class, 'dailyBookings'])->middleware('permission:dashboard.operations');
Route::get('dashboard/stats/daily-members', [DashboardStatsController::class, 'dailyMembers'])->middleware('permission:dashboard.members');
Route::get('dashboard/stats/app-users', [DashboardStatsController::class, 'appUsers'])->middleware('permission:dashboard.members');
