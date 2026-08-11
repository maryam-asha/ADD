<?php

use App\Http\Controllers\Api\V1\Member\CompanyMemberController;
use App\Http\Controllers\Api\V1\Member\CompanyWalletAllocationController;
use App\Http\Controllers\Api\V1\Member\MembershipController;
use App\Http\Controllers\Api\V1\Member\PersonalProfileController;
use App\Http\Controllers\Api\V1\Member\PreferencesController;
use App\Http\Controllers\Api\V1\Member\ProfessionalProfileController;
use App\Http\Controllers\Api\V1\Member\PublicDirectoryConsentController;
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

// Self-service for a company admin managing their own company's members
// (CompanyPolicy::manageMembers) — checked in the controller, not by role
// middleware, since it depends on the company_user pivot, not a global role.
Route::patch('companies/{company}/members/{user}', [CompanyMemberController::class, 'updateDoorAccess']);
Route::patch('companies/{company}/members/{user}/admin', [CompanyMemberController::class, 'updateAdmin']);

// Same self-service precedent as above, for allocating from the company's
// wallet (CompanyPolicy::manageMembers) — see
// docs/decisions/wallet-points-categorization.md.
Route::post('companies/{company}/wallet-allocations', [CompanyWalletAllocationController::class, 'store']);

// A member buys a plan for themselves, or (via an optional company_id in the
// body, CompanyPolicy::manageMembers) a company admin buys it on behalf of
// their company — one endpoint, self-service checked in the controller.
Route::post('memberships', [MembershipController::class, 'store']);

// Unit 1 design (2026-08-09): both writable by the member at any time.
// preferred_language becoming writable here is a reversal of prior
// behavior — see docs/decisions/preferred-language-mutable.md.
Route::patch('preferences/currency', [PreferencesController::class, 'updateCurrency']);
Route::patch('preferences/language', [PreferencesController::class, 'updateLanguage']);

// Member personal profile endpoints
Route::get('profile/personal', [PersonalProfileController::class, 'show']);
Route::patch('profile/personal', [PersonalProfileController::class, 'update']);

// Member professional profile endpoints
Route::get('profile/professional', [ProfessionalProfileController::class, 'show']);
Route::patch('profile/professional', [ProfessionalProfileController::class, 'update']);

// Member consent to appear in a future public directory (Task 5 consumes this).
Route::patch('consents/public-directory', [PublicDirectoryConsentController::class, 'update']);
