<?php

namespace App\Domain\Booking\DataTransferObjects;

use App\Domain\Booking\Models\Kiosk;

/** The result of a successful pairing: the only moment the plain secret leaves the server. */
class KioskPairing
{
    public function __construct(
        public readonly Kiosk $kiosk,
        public readonly string $secret,
    ) {}
}
