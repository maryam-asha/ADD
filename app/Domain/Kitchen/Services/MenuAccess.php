<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Models\QrPoint;

/**
 * Whose menu a member is looking at and whether they may order from it —
 * one answer for the menu screen and the scan of a place's QR.
 *
 * Branch, route and payment options come from OrderRouting. Anyone may order
 * where a kitchen is open (an active kitchen store); no bill is needed any
 * more (spec decision 1), so the only refusal is a branch with no kitchen.
 */
class MenuAccess
{
    public const NO_KITCHEN = 'no_kitchen';

    public function __construct(private readonly OrderRouting $routing) {}

    /**
     * @return array{branch_id: ?int, can_order: bool, reason: ?string, bills: list<Booking|WalkinSession>, route: string, payment_methods: list<string>, wallets: list<array<string, mixed>>}
     */
    public function for(User $member, ?QrPoint $qr, ?int $branchId): array
    {
        $decision = $this->routing->for($member, $qr, $branchId);

        $reason = $decision->branchId !== null && KitchenStore::activeForBranch($decision->branchId) === null
            ? self::NO_KITCHEN
            : null;

        return [
            'branch_id' => $decision->branchId,
            'can_order' => $reason === null,
            'reason' => $reason,
            'bills' => $decision->bills,
            'route' => $decision->route->value,
            'payment_methods' => $decision->methods,
            'wallets' => $decision->wallets,
        ];
    }
}
