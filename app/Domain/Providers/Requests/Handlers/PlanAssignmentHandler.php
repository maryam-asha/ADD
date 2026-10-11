<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/**
 * Activates the plan for the client company with no wallet charge: in the
 * provider flow money is invoiced, never taken from a wallet. Approval
 * issues the invoice through InvoiceIssuer in the same transaction
 * (docs/decisions/service-providers.md). Only the reviewer may sell past
 * a full pool.
 */
class PlanAssignmentHandler implements ProviderRequestHandler
{
    public function __construct(
        private readonly MembershipActivationService $activations,
        private readonly MembershipResolver $memberships,
    ) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $payload = $request->payload;
        $plan = Plan::query()->find($payload['plan_id']);

        if ($plan === null || ! $plan->is_active || (! $plan->is_public && $plan->provider_id !== $request->provider_id)) {
            throw new ProviderRequestException('api.provider.plan_unavailable');
        }

        if ($this->memberships->activeForOwner(OwnerType::Company, $request->company_id) !== null) {
            throw new ProviderRequestException('api.admin.membership_already_active');
        }

        try {
            $membership = $this->activations->activate(
                $plan,
                OwnerType::Company,
                $request->company_id,
                charge: false,
                auditAction: 'membership_activated_by_provider_request',
                branchId: $payload['branch_id'] ?? null,
                seats: (int) $payload['seats'],
                overrideCapacity: (bool) ($overrides['override_capacity'] ?? false),
                auditProperties: ['provider_request_id' => $request->id, 'payer' => $payload['payer']],
                attributes: ['auto_renew' => (bool) ($payload['auto_renew'] ?? false)],
            );
        } catch (SeatPoolExhaustedException) {
            throw new ProviderRequestException('api.membership.seat_pool_full');
        }

        return $membership->id;
    }
}
