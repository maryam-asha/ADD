<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Foundation\Models\Branch;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Models\QrPoint;

/**
 * Whose menu a member is looking at and whether they may order from it —
 * one answer for the menu screen and the scan of a place's QR.
 *
 * The branch is the QR's, else the member's first bill's, else the one the
 * app asked for. Ordering needs a kitchen there (an active kitchen store)
 * and an open session or checked-in booking (spec decision 8); a branch
 * with no kitchen is said first, since checking in would not help.
 */
class MenuAccess
{
    public const NO_OPEN_SESSION = 'no_open_session';

    public const NO_KITCHEN = 'no_kitchen';

    public function __construct(private readonly BillLocator $bills) {}

    /**
     * @return array{branch_id: ?int, can_order: bool, reason: ?string, bills: list<Booking|WalkinSession>}
     */
    public function for(User $member, ?QrPoint $qr, ?int $branchId): array
    {
        $bills = $this->bills->eligibleBills($member);
        $branchId = $qr?->branch_id
            ?? ($bills === [] ? null : (int) $bills[0]->space->building->branch_id)
            ?? $branchId
            // The branch the request names (X-Branch-Id, via ResolveDefaultBranch), else the default one.
            ?? Branch::defaultId();

        $reason = match (true) {
            $branchId !== null && KitchenStore::activeForBranch($branchId) === null => self::NO_KITCHEN,
            $bills === [] => self::NO_OPEN_SESSION,
            default => null,
        };

        return [
            'branch_id' => $branchId,
            'can_order' => $reason === null,
            'reason' => $reason,
            'bills' => $bills,
        ];
    }
}
