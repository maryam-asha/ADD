<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;

/**
 * What BookingExtensionService::extend() did: where the member sits from
 * now on when the extension moved them (null when they stay put), what the
 * added time cost, and how that is being paid — the wallet payment taken
 * with the extension, or the card the member chose, opened only once the
 * extension is saved (BookingExtensionService::openCardPayment()).
 */
final class ExtensionOutcome
{
    public function __construct(
        public readonly ?Space $movedTo,
        public readonly int $differencePoints,
        public readonly ?Payment $walletPayment = null,
        public readonly ?string $gateway = null,
    ) {}
}
