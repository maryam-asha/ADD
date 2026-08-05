<?php

use App\Http\Controllers\Api\V1\Admin\CommunityMemberController;
use App\Http\Controllers\Api\V1\Admin\FounderController;
use App\Http\Controllers\Api\V1\Admin\PartnerController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

// Every route in this file already sits behind auth:sanctum + role:admin|staff
// — that's applied once, from the group wrapping this file in routes/api.php.
Route::get('me', [CurrentUserController::class, 'show']);

Route::apiResource('founders', FounderController::class);
Route::apiResource('partners', PartnerController::class);

// Multi-word resource name — Laravel's auto-derived {community_member}
// placeholder (snake_case) won't implicit-bind to a camelCase controller
// argument, so the parameter name is pinned explicitly here.
Route::apiResource('community-members', CommunityMemberController::class)
    ->parameters(['community-members' => 'communityMember']);

// Narrower than the group above: managing accounts and roles is admin-only,
// staff can't create or promote other accounts.
Route::middleware('role:admin')->group(function () {
    Route::apiResource('users', UserController::class)->except('destroy');
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::patch('users/{user}/role', [UserController::class, 'assignRole']);

    Route::get('roles', [RoleController::class, 'index']);
});
