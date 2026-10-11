<?php

namespace App\Domain\Booking\Enums;

/**
 * The coarse routing recorded on a booking/walk-in: whether it was (or will
 * be) paid from the member's wallet, or is destined for a cash-equivalent
 * settlement. The specific cash channel (cash today) is a
 * separate, finer-grained value recorded only at settlement time — see
 * App\Domain\Finance\Enums\PaymentMethod.
 *
 * `Plan` is neither: the time was absorbed by the member's plan access
 * window and nothing was collected for it at all
 * (docs/decisions/plan-access-windows.md). It is only ever set on a session
 * the plan covered in FULL — a partially covered one still owes money and is
 * settled through the ordinary cash path like any other walk-in.
 */
enum PaymentSource: string
{
    case Wallet = 'wallet';
    case Cash = 'cash';
    case Plan = 'plan';
    // Paid by card (or any other gateway) through the app.
    case Gateway = 'gateway';
}
