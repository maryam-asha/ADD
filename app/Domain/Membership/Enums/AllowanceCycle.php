<?php

namespace App\Domain\Membership\Enums;

/**
 * How a plan's hours and discount use-limits run: once for the whole
 * period, or afresh each month from the subscription's start. Unused hours
 * never roll over (product owner, 2026-11-02).
 */
enum AllowanceCycle: string
{
    case Period = 'period';
    case Monthly = 'monthly';
}
