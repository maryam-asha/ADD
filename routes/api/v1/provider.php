<?php

use App\Http\Controllers\Api\V1\Provider\ProviderCompanyController;
use App\Http\Controllers\Api\V1\Provider\ProviderInvoiceController;
use App\Http\Controllers\Api\V1\Provider\ProviderOfficeLeaseController;
use App\Http\Controllers\Api\V1\Provider\ProviderOfficeRequestController;
use App\Http\Controllers\Api\V1\Provider\ProviderPlanController;
use App\Http\Controllers\Api\V1\Provider\ProviderProfileController;
use App\Http\Controllers\Api\V1\Provider\ProviderRenewalController;
use App\Http\Controllers\Api\V1\Provider\ProviderReportController;
use App\Http\Controllers\Api\V1\Provider\ProviderRequestController;
use App\Http\Controllers\Api\V1\Provider\ProviderSummaryController;
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
Route::get('profile', [ProviderProfileController::class, 'show']);
Route::get('summary', [ProviderSummaryController::class, 'show']);

Route::get('companies', [ProviderCompanyController::class, 'index']);
Route::get('companies/{company}', [ProviderCompanyController::class, 'show']);
Route::delete('companies/{company}/members/{user}', [ProviderCompanyController::class, 'destroyMember']);
Route::get('office-requests', [ProviderOfficeRequestController::class, 'index']);
Route::post('office-requests', [ProviderOfficeRequestController::class, 'store']);
Route::get('office-leases', [ProviderOfficeLeaseController::class, 'index']);
Route::get('office-leases/{officeLease}', [ProviderOfficeLeaseController::class, 'show']);
Route::get('companies/{company}/office-leases', [ProviderOfficeLeaseController::class, 'forCompany']);
Route::patch('companies/{company}/membership/auto-renew', [ProviderRenewalController::class, 'membershipAutoRenew']);
Route::patch('office-leases/{officeLease}/auto-renew', [ProviderRenewalController::class, 'leaseAutoRenew']);

Route::get('plans', [ProviderPlanController::class, 'index']);

Route::get('requests', [ProviderRequestController::class, 'index']);
Route::post('requests', [ProviderRequestController::class, 'store']);
Route::get('requests/{providerRequest}', [ProviderRequestController::class, 'show']);
Route::patch('requests/{providerRequest}/cancel', [ProviderRequestController::class, 'cancel']);

Route::get('invoices', [ProviderInvoiceController::class, 'index']);
Route::get('invoices/{invoice}', [ProviderInvoiceController::class, 'show']);

Route::get('reports/invoices', [ProviderReportController::class, 'invoices']);
