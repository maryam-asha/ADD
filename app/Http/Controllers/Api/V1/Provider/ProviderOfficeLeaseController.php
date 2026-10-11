<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Http\Controllers\Api\V1\Admin\OfficeLeaseController;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListOfficeLeasesRequest;
use App\Http\Resources\ProviderOfficeLeaseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The office leases of the provider's companies — read only
 * (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 */
class ProviderOfficeLeaseController extends Controller
{
    use ActsForProvider;

    public function index(ListOfficeLeasesRequest $request): AnonymousResourceCollection
    {
        $query = OfficeLease::query()->whereHas('company', fn ($q) => $q->where('provider_id', $this->provider($request)->id));

        return ProviderOfficeLeaseResource::collection(
            OfficeLeaseController::filtered($request, $query)
                ->with(['company', 'space.building'])
                ->latest('id')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Request $request, OfficeLease $officeLease): ProviderOfficeLeaseResource
    {
        $this->authorizeLease($request, $officeLease);

        return new ProviderOfficeLeaseResource($officeLease->load(['company', 'space.building', 'periods.invoice']));
    }

    public function forCompany(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorizeCompany($request, $company);

        return ProviderOfficeLeaseResource::collection(
            $company->officeLeases()->with(['company', 'space.building'])->latest('id')->get()
        );
    }
}
