<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StoreProviderRequestRequest;
use App\Http\Resources\ProviderRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProviderRequestController extends Controller
{
    use ActsForProvider;

    public function __construct(private readonly ProviderRequestService $requests) {}

    /** Filters: `status`, `type`, `company_id`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = ProviderRequest::query()
            ->where('provider_id', $this->provider($request)->id)
            ->with(['company', 'submitter'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return ProviderRequestResource::collection($requests);
    }

    public function show(Request $request, ProviderRequest $providerRequest): ProviderRequestResource
    {
        $this->authorizeRequest($request, $providerRequest);

        return new ProviderRequestResource($providerRequest->load(['company', 'submitter']));
    }

    public function store(StoreProviderRequestRequest $request): JsonResponse
    {
        $created = $this->requests->submit(
            $this->provider($request),
            $request->user(),
            $request->type(),
            $request->type()->needsCompany() ? $request->integer('company_id') : null,
            $request->payload(),
        );

        return (new ProviderRequestResource($created->load(['company', 'submitter'])))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, ProviderRequest $providerRequest): JsonResponse
    {
        $this->authorizeRequest($request, $providerRequest);

        try {
            $this->requests->cancel($providerRequest);
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.provider.request_cancelled')]);
    }

    private function authorizeRequest(Request $request, ProviderRequest $providerRequest): void
    {
        abort_unless($providerRequest->provider_id === $this->provider($request)->id, 404);
    }
}
