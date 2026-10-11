<?php

namespace App\Domain\Booking\DataTransferObjects;

use App\Domain\Booking\Models\WalkinSession;

/**
 * What WalkInCapacityService::start() hands back: the session, plus whether
 * the member's plan applies at this moment.
 *
 * The session alone is not enough. A member on an evening package who walks
 * in at 14:00 is NOT refused — the session starts as an ordinary paid
 * walk-in — but reception has to be told that the plan is not covering it,
 * which is a caveat on a success, not an error
 * (docs/decisions/plan-access-windows.md).
 */
class WalkInStartResult
{
    public function __construct(
        public readonly WalkinSession $session,
        public readonly bool $planApplies,
        public readonly ?string $noticeKey = null,
    ) {}
}
