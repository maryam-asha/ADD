<?php

namespace App\Domain\Booking\DataTransferObjects;

use Carbon\CarbonInterface;

/** The plain pairing code, handed to the admin once; only its hash is stored. */
class IssuedPairingCode
{
    public function __construct(
        public readonly string $code,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
