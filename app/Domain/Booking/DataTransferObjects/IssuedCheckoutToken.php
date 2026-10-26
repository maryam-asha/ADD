<?php

namespace App\Domain\Booking\DataTransferObjects;

use Carbon\CarbonInterface;

class IssuedCheckoutToken
{
    public function __construct(
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
