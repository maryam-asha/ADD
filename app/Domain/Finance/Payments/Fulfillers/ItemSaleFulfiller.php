<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Retail\Models\ItemSale;

/**
 * Paying for what a member bought at the desk. Its price is fixed when the
 * sale is written; delivering it issues every line from the stock it was
 * sold from — so a sale the shelf cannot cover rolls back with its payment,
 * points or cash, and stock and money never disagree.
 */
class ItemSaleFulfiller implements PaymentFulfiller
{
    public function __construct(private readonly StockService $stock) {}

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::ItemSale;
    }

    public function pointsDue(Payment $payment): int
    {
        return $payment->amount_points ?? $this->sale($payment)->total_points;
    }

    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::General;
    }

    public function describe(Payment $payment): string
    {
        return __('api.item_sale.description', ['id' => $payment->payable_id]);
    }

    public function assertPayable(Payment $payment): void
    {
        $sale = $this->sale($payment);

        if ($sale->paid_at !== null) {
            throw new ReceptionActionException('api.payment.already_paid', 409);
        }

        if ($sale->total_points <= 0) {
            throw new ReceptionActionException('api.payment.nothing_due');
        }
    }

    public function fulfil(Payment $payment): void
    {
        $sale = $this->sale($payment);

        foreach ($sale->lines()->with('item.category', 'item.unit')->get() as $line) {
            $this->stock->issue($line->item, $sale->location(), $line->quantity, IssueReason::Sale, new Place(ItemSale::SOURCE, $sale->id));
        }

        $sale->forceFill(['paid_at' => now()])->save();
    }

    private function sale(Payment $payment): ItemSale
    {
        $sale = $payment->payable_id === null
            ? null
            : ItemSale::query()->whereKey($payment->payable_id)->lockForUpdate()->first();

        if ($sale === null || $payment->payable_type !== (new ItemSale)->getMorphClass()) {
            throw new ReceptionActionException('api.payment.payable_not_found', 404);
        }

        return $sale;
    }
}
