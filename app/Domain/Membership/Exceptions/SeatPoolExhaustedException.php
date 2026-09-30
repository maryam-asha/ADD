<?php

namespace App\Domain\Membership\Exceptions;

use Carbon\CarbonImmutable;

/**
 * Thrown by MembershipActivationService when the subscription being sold
 * would hold more seats than its branch's co-working pool has free at some
 * moment of its period (docs/decisions/coworking-seat-pool.md). Carries that
 * first moment, so the seller can say from when it no longer fits.
 */
class SeatPoolExhaustedException extends \RuntimeException
{
    public function __construct(public readonly CarbonImmutable $firstShortfall)
    {
        parent::__construct("The co-working seat pool is full from {$firstShortfall->toIso8601String()}.");
    }
}
