<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListOfficeLeasesRequest;
use App\Http\Requests\Admin\StoreOfficeLeaseRequest;
use App\Http\Requests\Admin\TerminateOfficeLeaseRequest;
use App\Http\Resources\OfficeLeaseResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 * Permission-gated per route (office-leases.*). A new company's first lease
 * is made by CompanyController::store instead.
 */
class OfficeLeaseController extends Controller
{
    public function index(ListOfficeLeasesRequest $request): AnonymousResourceCollection
    {
        return OfficeLeaseResource::collection(
            self::filtered($request)
                ->with(['company', 'space.building', 'provider'])
                ->latest('id')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function show(OfficeLease $officeLease): OfficeLeaseResource
    {
        return new OfficeLeaseResource($this->loadForView($officeLease));
    }

    /** The list filters, shared with the provider's list (which scopes its companies first). */
    public static function filtered(Request $request, ?Builder $query = null): Builder
    {
        $today = now()->toDateString();

        return ($query ?? OfficeLease::query())
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('space_id'), fn (Builder $q) => $q->where('space_id', $request->integer('space_id')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->whereHas('space.building', fn (Builder $b) => $b->where('branch_id', $request->integer('branch_id'))))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('ending_within_days'), fn (Builder $q) => $q
                ->where('status', OfficeLeaseStatus::Active)
                ->whereDate('ends_on', '>=', $today)
                ->whereDate('ends_on', '<=', now()->addDays($request->integer('ending_within_days'))->toDateString()));
    }

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
