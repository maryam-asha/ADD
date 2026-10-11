<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StoreProviderOfficeRequestRequest;
use App\Http\Resources\PrivateOfficeRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A new client company, or another office for one of the provider's
 * companies, starts as a private-office request on the existing pipeline
 * (requested -> quoted -> contracted): a quote and a contract are
 * real steps, so a provider's office request is not a provider request.
 * CompanyController::store carries `provider_id` onto the company.
 */
class ProviderOfficeRequestController extends Controller
{
    use ActsForProvider;

    public function index(Request $request): AnonymousResourceCollection
    {
        return PrivateOfficeRequestResource::collection(
            PrivateOfficeRequest::query()
                ->where('provider_id', $this->provider($request)->id)
                ->latest('id')
                ->get()
        );
    }

    public function store(StoreProviderOfficeRequestRequest $request): JsonResponse|PrivateOfficeRequestResource
    {
        if ($request->filled('company_id')) {
            $company = Company::query()->find($request->integer('company_id'));
            abort_if($company === null, 404);
            $this->authorizeCompany($request, $company);

            if ($company->status !== CompanyStatus::Active) {
                return response()->json(['message' => __('api.provider.company_unavailable')], 422);
            }
        }

        return new PrivateOfficeRequestResource(PrivateOfficeRequest::create([
            ...$request->validated(),
            'status' => PrivateOfficeRequestStatus::Requested,
            'provider_id' => $this->provider($request)->id,
            'submitted_by' => $request->user()->id,
        ]));
    }
}
