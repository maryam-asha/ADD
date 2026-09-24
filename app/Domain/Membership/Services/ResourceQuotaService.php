<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Exceptions\InsufficientResourceQuotaException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanResourceGrant;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Read-time quota computation and ledger-writing for PlanResourceGrant, the
 * natural-unit (not points) sibling of WalletService's balance mechanics
 * (docs/decisions/resource-quota-design.md). No stored "remaining" counter
 * — every figure here is summed from `resource_consumptions` on the spot
 * with bcmath at scale 2, cast to float only at the boundary, mirroring
 * WalletService::sumAmounts()'s style.
 */
class ResourceQuotaService
{
    /**
     * Null when the membership's plan grants no quota at all for this
     * resource type. Otherwise the grant's quantity minus everything
     * consumed against it by this membership, floored at 0.00 — a
     * membership can never show a negative remaining balance. This is an
     * unlocked read (no lockForUpdate()) — it is for display only; writes
     * go through consume() below.
     */
    public function remainingFor(Membership $membership, ResourceType $resourceType): ?float
    {
        $plan = $membership->plan;

        if ($plan === null) {
            return null;
        }

        $grant = $plan->relationLoaded('resourceGrants')
            ? $plan->resourceGrants->firstWhere('resource_type_id', $resourceType->id)
            : $plan->resourceGrants()->where('resource_type_id', $resourceType->id)->first();

        if ($grant === null) {
            return null;
        }

        $consumed = $this->sumConsumption($grant->id, $membership->id);
        $remaining = bcsub((string) $grant->quantity, $consumed, 2);

        if (bccomp($remaining, '0', 2) < 0) {
            return 0.0;
        }

        return (float) $remaining;
    }

    /**
     * Records one unit of consumption against a grant, refusing it with
     * InsufficientResourceQuotaException when it would exceed what remains.
     *
     * Unlike PlanDiscountService::redeem(), which is documented to run
     * inside a transaction its caller already holds, this method has no
     * caller-held lock to rely on and opens its own DB::transaction().
     * Inside it, the grant row — not the ledger — is locked with
     * lockForUpdate(): the very first consumption against a grant has zero
     * pre-existing ledger rows, so locking the ledger would lock nothing
     * for a first-time consume, whereas the grant row is guaranteed to
     * already exist. The ledger read is locked too, as defense-in-depth,
     * but the grant-row lock is what actually serializes concurrent calls.
     */
    public function consume(PlanResourceGrant $grant, Membership $membership, float $quantity, User $recordedBy, ?string $note = null): ResourceConsumption
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('ResourceQuotaService::consume() requires a positive quantity.');
        }

        $requested = number_format($quantity, 2, '.', '');

        return DB::transaction(function () use ($grant, $membership, $requested, $recordedBy, $note) {
            $lockedGrant = PlanResourceGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();

            $consumed = $this->sumConsumption($lockedGrant->id, $membership->id, lockForUpdate: true);
            $remaining = bcsub((string) $lockedGrant->quantity, $consumed, 2);

            if (bccomp($requested, $remaining, 2) > 0) {
                throw new InsufficientResourceQuotaException(sprintf(
                    'PlanResourceGrant %d has insufficient remaining quota for membership %d: requested %s, has %s remaining.',
                    $lockedGrant->id,
                    $membership->id,
                    $requested,
                    $remaining
                ));
            }

            return ResourceConsumption::create([
                'plan_resource_grant_id' => $lockedGrant->id,
                'membership_id' => $membership->id,
                'quantity' => $requested,
                'recorded_by_user_id' => $recordedBy->id,
                'note' => $note,
            ]);
        });
    }

    private function sumConsumption(int $grantId, int $membershipId, bool $lockForUpdate = false): string
    {
        $query = ResourceConsumption::query()
            ->where('plan_resource_grant_id', $grantId)
            ->where('membership_id', $membershipId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $total = '0';

        foreach ($query->get() as $consumption) {
            $total = bcadd($total, (string) $consumption->quantity, 2);
        }

        return $total;
    }
}
