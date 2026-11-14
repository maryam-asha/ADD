<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Finance\Payments\CheckoutOutcome;
use App\Domain\Kitchen\Models\KitchenOrder;

/**
 * An order sent, or a held one sent again with another method, and what
 * paying for it came to: the wallet's payment (done), cash at reception
 * (pay_at_reception), on the bill (done, nothing paid yet). `checkout` is
 * null when a card is still to be opened — that happens once the order is
 * committed, outside any transaction, so a gateway that fails leaves the
 * order standing (KitchenController::store).
 */
final readonly class PlacedOrder
{
    public function __construct(
        public KitchenOrder $order,
        public ?CheckoutOutcome $checkout,
    ) {}
}
