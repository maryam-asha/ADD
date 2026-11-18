<?php

namespace App\Domain\Booking\DataTransferObjects;

use Carbon\CarbonInterface;

class IssuedScanTicket
{
    public function __construct(
        public readonly string $ticket,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
