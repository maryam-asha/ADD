<?php

namespace App\Domain\Booking\DataTransferObjects;

use Carbon\CarbonImmutable;

/** What the member was shown at checkout: the session priced up to `$at`, at `$points`. */
class CheckoutQuote
{
    public function __construct(
        public readonly CarbonImmutable $at,
        public readonly int $points,
    ) {}
}
