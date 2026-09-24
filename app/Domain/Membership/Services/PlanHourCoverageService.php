<?php

namespace App\Domain\Membership\Services;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanResourceGrant;
use App\Domain\Membership\Models\ResourceType;
use Carbon\CarbonInterface;

/**
 * How much of a walk-in session the member's plan absorbs
 * (docs/decisions/plan-access-windows.md).
 *
 * Two independent limits apply, in this order:
 *
 *  1. the plan's daily access window — an evening package covers 20:00-00:00
 *     and nothing else, so a session straddling 20:00 is split at that
 *     boundary rather than classified as a whole;
 *  2. what is left of the plan's granted hour quota for this membership.
 *
 * Whatever neither limit covers is billed at the normal rate: when the hours
 * run out mid-window the member pays cash like an ordinary visitor, and
 * deliberately NOT out of their general wallet balance. That is why the hour
 * pool is a PlanResourceGrant consumed through ResourceQuotaService — which
 * refuses an over-draw — rather than WalletService::debit(), which silently
 * falls back to general points when a category pool is short.
 */
class PlanHourCoverageService
{
    public const RESOURCE_TYPE_SLUG = 'space-hours';

    public function __construct(private readonly ResourceQuotaService $quotas) {}

    /**
     * Seconds of [$from, $to) this membership's plan absorbs, already capped
     * at the remaining quota. Read-only — call consume() to actually spend it.
     */
    public function coverableSeconds(?Membership $membership, CarbonInterface $from, CarbonInterface $to): int
    {
        $grant = $this->grantFor($membership);

        if ($grant === null) {
            return 0;
        }

        $inWindow = $membership->plan->windowOverlap($from, $to);

        if ($inWindow <= 0) {
            return 0;
        }

        $remainingHours = $this->quotas->remainingFor($membership, $grant->resourceType);

        if ($remainingHours === null || $remainingHours <= 0) {
            return 0;
        }

        return min($inWindow, (int) floor($remainingHours * 3600));
    }

    /**
     * Records $seconds against the membership's hour grant. Returns the
     * seconds actually consumed, which is 0 when there is nothing to record.
     *
     * ResourceQuotaService::consume() re-checks the quota under a row lock
     * and throws if it would over-draw, so a concurrent checkout cannot push
     * the pool negative between coverableSeconds() and here. It opens its own
     * transaction, matching how ResourceConsumptionController already calls it.
     */
    public function consume(Membership $membership, WalkinSession $session, int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        $grant = $this->grantFor($membership);

        if ($grant === null) {
            return 0;
        }

        $this->quotas->consume(
            $grant,
            $membership,
            round($seconds / 3600, 2),
            $session->user,
            "Walk-in session #{$session->id}",
        );

        return $seconds;
    }

    /**
     * The membership's space-hours grant, or null when the plan has no
     * access window, no hour grant, or there is no membership at all — every
     * "this session is simply billed normally" case collapses to null here.
     */
    private function grantFor(?Membership $membership): ?PlanResourceGrant
    {
        if ($membership === null || $membership->plan === null) {
            return null;
        }

        if (! $membership->plan->hasAccessWindow()) {
            return null;
        }

        $resourceType = ResourceType::query()->where('slug', self::RESOURCE_TYPE_SLUG)->first();

        if ($resourceType === null) {
            return null;
        }

        $grant = $membership->plan->relationLoaded('resourceGrants')
            ? $membership->plan->resourceGrants->firstWhere('resource_type_id', $resourceType->id)
            : $membership->plan->resourceGrants()->where('resource_type_id', $resourceType->id)->first();

        if ($grant === null) {
            return null;
        }

        $grant->setRelation('resourceType', $resourceType);

        return $grant;
    }
}
