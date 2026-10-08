<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePrivateOfficeRequestRequest;
use App\Http\Resources\PrivateOfficeRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A new client company starts as a private-office request, on the existing
 * pipeline (requested -> quoted -> contracted): a quote and a contract are
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

    public function store(StorePrivateOfficeRequestRequest $request): PrivateOfficeRequestResource
    {
        return new PrivateOfficeRequestResource(PrivateOfficeRequest::create([
            ...$request->validated(),
            'status' => PrivateOfficeRequestStatus::Requested,
            'provider_id' => $this->provider($request)->id,
            'submitted_by' => $request->user()->id,
        ]));
    }
}
