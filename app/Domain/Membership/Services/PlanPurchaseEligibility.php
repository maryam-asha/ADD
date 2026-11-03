<?php

namespace App\Domain\Membership\Services;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;

/**
 * Whether an owner may buy a plan now (product owner, 2026-11-03). A member
 * holds one personal plan at a time; the one way to a second is a flexible
 * seat whose limited hours are spent this cycle — the new plan then
 * replaces it (MembershipActivationService). A company's plan never stands
 * in the way of a personal one, and a company keeps its own rule: nothing
 * new while one is live.
 */
class PlanPurchaseEligibility
{
    public function __construct(
        private readonly MembershipResolver $memberships,
        private readonly PlanAllowanceService $allowances,
    ) {}

    /** The live membership that refuses the purchase, or null when it may go ahead. */
    public function blockingFor(OwnerType $ownerType, int $ownerId): ?Membership
    {
        $live = $this->memberships->activeForOwner($ownerType, $ownerId);

        if ($live === null || $ownerType === OwnerType::Company) {
            return $live;
        }

        $user = User::query()->find($ownerId);

        return $user !== null && $this->isReplaceable($live, $user) ? null : $live;
    }

    /** A flexible seat with limited hours, none of them left this cycle. */
    public function isReplaceable(Membership $membership, User $user): bool
    {
        $plan = $membership->plan;

        if ($plan === null || $plan->coworking_seat !== CoworkingSeat::Flexible || $plan->coworking_hours === null) {
            return false;
        }

        $allowance = $this->allowances->allowanceFor($membership, SpaceType::CoSpace);

        return $allowance !== null && $this->allowances->remainingSeconds($membership, $user, $allowance, now()) === 0;
    }

    /** What a member is told when their own plan refuses the purchase. */
    public function reason(Membership $blocking): string
    {
        return __('api.package.already_subscribed', [
            'date' => $blocking->current_period_end->copy()->timezone('Asia/Damascus')->format('d/m/Y'),
        ]);
    }
}
