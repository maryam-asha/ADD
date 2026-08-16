<?php

namespace App\Domain\Booking\Enums;

/**
 * The coarse routing recorded on a booking/walk-in: whether it was (or will
 * be) paid from the member's wallet, or is destined for a cash-equivalent
 * settlement. The specific cash channel (cash|sham|mtn|syriatel) is a
 * separate, finer-grained value recorded only at settlement time — see
 * App\Domain\Finance\Enums\PaymentMethod.
 */
enum PaymentSource: string
{
    case Wallet = 'wallet';
    case Cash = 'cash';
}
