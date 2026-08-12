<?php

use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BuildingController;
use App\Http\Controllers\Api\V1\Admin\CommunityMemberController;
use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\CompanyMemberController;
use App\Http\Controllers\Api\V1\Admin\ErrorLogController;
use App\Http\Controllers\Api\V1\Admin\ExchangeRateController;
use App\Http\Controllers\Api\V1\Admin\FounderController;
use App\Http\Controllers\Api\V1\Admin\PartnerController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\PrivateOfficeRequestController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

// Every route in this file already sits behind auth:sanctum + role:admin|operations
// — that's applied once, from the group wrapping this file in routes/api.php.
Route::get('me', [CurrentUserController::class, 'show']);

// Spatial Hierarchy — Branch is the top level (docs/decisions/district-removed.md).
Route::apiResource('branches', BranchController::class);
Route::apiResource('buildings', BuildingController::class);

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

// Narrower than the group above: managing accounts and roles is admin-only,
// operations can't create or promote other accounts.
Route::middleware('role:admin')->group(function () {
    Route::apiResource('users', UserController::class)->except('destroy');
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::patch('users/{user}/role', [UserController::class, 'assignRole']);

    Route::get('roles', [RoleController::class, 'index']);

    Route::delete('error-logs/{errorLog}', [ErrorLogController::class, 'destroy']);
});
