<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOfficeLeaseRequest;
use App\Http\Requests\Admin\TerminateOfficeLeaseRequest;
use App\Http\Resources\OfficeLeaseResource;
use Illuminate\Http\JsonResponse;

/**
 * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 * Permission-gated per route (office-leases.*). A new company's first lease
 * is made by CompanyController::store instead.
 */
class OfficeLeaseController extends Controller
{
    public function terminate(TerminateOfficeLeaseRequest $request, OfficeLease $officeLease, OfficeLeaseService $leases): JsonResponse
    {
        try {
            $leases->terminate($officeLease, $request->validated('terminated_on'), $request->validated('reason'), $request->user());
        } catch (LeasingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.lease.terminated')]);
    }

    public function store(StoreOfficeLeaseRequest $request, PrivateOfficeRequest $privateOfficeRequest, OfficeLeaseService $leases): JsonResponse
    {
        try {
            $lease = $leases->contract($privateOfficeRequest, $request->leaseTerms(), $request->user());
        } catch (LeasingException|InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return (new OfficeLeaseResource($this->loadForView($lease)))->response()->setStatusCode(201);
    }

    private function loadForView(OfficeLease $lease): OfficeLease
    {
        return $lease->load(['company', 'space.building', 'provider', 'periods.invoice', 'accessGrants']);
    }
}
