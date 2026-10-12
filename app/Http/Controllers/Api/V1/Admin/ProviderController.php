<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Models\Currency;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProviderRequest;
use App\Http\Requests\Admin\UpdateProviderRequest;
use App\Http\Requests\Admin\UpdateProviderStatusRequest;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Service providers (docs/decisions/service-providers.md). Does not extend
 * AdminResourceController: no `order` column, and a provider is
 * deactivated (updateStatus), never deleted — its requests and
 * companies keep pointing at it.
 */
class ProviderController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return ProviderResource::collection(Provider::query()->withCount(['companies', 'users'])->orderBy('name')->get());
    }

    /** The provider and its first account (a `provider`-role user, linked) are one transaction. */
    public function store(StoreProviderRequest $request): ProviderResource
    {
        [$provider, $account] = DB::transaction(function () use ($request) {
            $provider = Provider::create([
                ...$request->safe()->except('account'),
                'status' => ProviderStatus::Active,
            ]);

            $account = User::create([
                'name' => $request->validated('account.name'),
                'email' => $request->validated('account.email'),
                'phone' => $request->validated('account.phone'),
                'password' => Hash::make($request->validated('account.password')),
                'preferred_language' => 'ar',
                'preferred_currency' => Currency::query()->where('is_base', true)->value('code'),
                'status' => 'active',
            ]);
            $account->assignRole('provider');
            $provider->users()->attach($account->id);

            return [$provider, $account];
        });

        $this->logSensitiveAction('provider_created', $provider, ['user_id' => $account->id]);
        $this->logSensitiveAction('user_created', $account, ['role' => 'provider', 'provider_id' => $provider->id]);

        return new ProviderResource($provider->refresh()->load('users')->loadCount(['companies', 'users']));
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

    /**
     * Deactivating cuts every account off (EnsureActiveProvider) and cancels
     * the provider's pending requests. Its companies keep running; an admin
     * moves them with CompanyProviderController. Reactivating brings no
     * cancelled request back.
     */
    public function updateStatus(UpdateProviderStatusRequest $request, Provider $provider, ProviderRequestService $requests): JsonResponse
    {
        $before = $provider->status;
        $status = ProviderStatus::from($request->validated('status'));

        DB::transaction(function () use ($provider, $status, $requests) {
            $provider->update(['status' => $status]);

            if ($status === ProviderStatus::Inactive) {
                $requests->cancelPending(ProviderRequest::query()->where('provider_id', $provider->id), 'provider_deactivated');
            }
        });

        $this->logSensitiveAction('provider_status_changed', $provider, ['before' => $before->value, 'after' => $status->value]);

        return response()->json(['message' => __('api.admin.provider_status_updated')]);
    }
}
