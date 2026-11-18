<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\PlanCreationService;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/** The design becomes a private plan only its provider can assign. */
class PlanDesignHandler implements ProviderRequestHandler
{
    public function __construct(private readonly PlanCreationService $plans) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        return $this->plans->create([
            ...$request->payload,
            'is_public' => false,
            'provider_id' => $request->provider_id,
        ])->id;
    }
}
