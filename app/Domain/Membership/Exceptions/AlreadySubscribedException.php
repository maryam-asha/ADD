<?php

namespace App\Domain\Membership\Exceptions;

use App\Domain\Membership\Models\Membership;
use RuntimeException;

/**
 * A member's own plan still runs and cannot be replaced: one personal plan
 * at a time, unless its limited hours are spent (PlanPurchaseEligibility).
 * Thrown by activation under its lock, so a second tap that passed the
 * check before the first one's plan existed is refused, not charged.
 */
class AlreadySubscribedException extends RuntimeException
{
    public function __construct(public readonly Membership $current)
    {
        parent::__construct('The member already holds a live personal plan.');
    }
}
