<?php

namespace App\Domain\Providers\Requests;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\ProviderRequest;

/**
 * Carries out one type of provider request once a reviewer approves it.
 * Runs inside ProviderRequestService::approve's transaction, with the
 * request row locked; re-checks what may have changed since submission and
 * throws ProviderRequestException to refuse. Returns the id of what it
 * created (see ProviderRequestResource), or null.
 */
interface ProviderRequestHandler
{
    /** @param  array<string, mixed>  $overrides  reviewer-only switches, e.g. override_capacity */
    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int;
}
