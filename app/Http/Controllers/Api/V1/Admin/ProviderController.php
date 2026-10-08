<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProviderRequest;
use App\Http\Requests\Admin\UpdateProviderRequest;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Service providers (docs/decisions/service-providers.md). Does not extend
 * AdminResourceController: no `order` column, and a provider is
 * deactivated (updateStatus, Task 9), never deleted — its requests and
 * companies keep pointing at it.
 */
class ProviderController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return ProviderResource::collection(Provider::query()->withCount('companies')->orderBy('name')->get());
    }

    public function store(StoreProviderRequest $request): ProviderResource
    {
        $provider = Provider::create([...$request->validated(), 'status' => ProviderStatus::Active]);

        $this->logSensitiveAction('provider_created', $provider);

        return new ProviderResource($provider->refresh());
    }

    public function show(Provider $provider): ProviderResource
    {
        return new ProviderResource($provider->load('users')->loadCount('companies'));
    }

    public function update(UpdateProviderRequest $request, Provider $provider): JsonResponse
    {
        $provider->update($request->validated());

        return response()->json(['message' => __('api.admin.provider_updated')]);
    }
}
