<?php

use App\Http\Controllers\Api\V1\Provider\ProviderCompanyController;
use App\Http\Controllers\Api\V1\Provider\ProviderOfficeRequestController;
use App\Http\Controllers\Api\V1\Provider\ProviderPlanController;
use App\Http\Controllers\Api\V1\Provider\ProviderRequestController;
use Illuminate\Support\Facades\Route;

/*
 * Service providers (docs/decisions/service-providers.md). Everything here
 * sits behind auth:sanctum + abilities:dashboard + role:provider +
 * provider.active, applied once by the group wrapping this file in
 * routes/api.php. Every route on a {company} calls authorizeCompany()
 * (ProviderPolicy, 404 when not this provider's) — guarded by
 * ProviderRoutesStayIsolatedTest. Never point a route here at an Admin\ or
 * Member\ controller.
 */
Route::get('companies', [ProviderCompanyController::class, 'index']);
Route::get('companies/{company}', [ProviderCompanyController::class, 'show']);
Route::delete('companies/{company}/members/{user}', [ProviderCompanyController::class, 'destroyMember']);
Route::get('office-requests', [ProviderOfficeRequestController::class, 'index']);
Route::post('office-requests', [ProviderOfficeRequestController::class, 'store']);

Route::get('plans', [ProviderPlanController::class, 'index']);

Route::get('requests', [ProviderRequestController::class, 'index']);
Route::post('requests', [ProviderRequestController::class, 'store']);
Route::get('requests/{providerRequest}', [ProviderRequestController::class, 'show']);
Route::patch('requests/{providerRequest}/cancel', [ProviderRequestController::class, 'cancel']);
