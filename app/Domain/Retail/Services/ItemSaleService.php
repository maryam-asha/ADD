<?php

namespace App\Domain\Retail\Services;

use Add\Inventory\Support\Place;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Retail\Models\ItemSale;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Support\Facades\DB;

/**
 * Reception selling stock to a member: the sale is written at today's
 * prices and paid in the same transaction — from the member's points
 * (PaymentService::start with `wallet`), or with money handed over, which
 * becomes points in their wallet and is spent at once
 * (settleAtReception). Paying it issues the stock (ItemSaleFulfiller), so
 * anything that fails — points short, shelf empty — leaves nothing behind.
 */
class ItemSaleService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * @param  list<array{sale_item_id: int, quantity: int}>  $lines
     * @return array{sale: ItemSale, payment: Payment}
     */
    public function sell(User $member, array $lines, Place $from, string $method, ?string $currency, User $operator): array
    {
        return DB::transaction(function () use ($member, $lines, $from, $method, $currency, $operator) {
            $saleItems = SaleItem::query()->active()->whereKey(array_column($lines, 'sale_item_id'))->get()->keyBy('id');

            $sale = ItemSale::query()->create([
                'user_id' => $member->id,
                'location_type' => $from->type,
                'location_id' => $from->id,
                'total_points' => 0,
                'sold_by' => $operator->id,
            ]);

            $total = 0;

            foreach ($lines as $line) {
                $saleItem = $saleItems->get($line['sale_item_id']);
                $points = $saleItem->price_points * $line['quantity'];
                $total += $points;

                $sale->lines()->create([
                    'inventory_item_id' => $saleItem->inventory_item_id,
                    'quantity' => $line['quantity'],
                    'unit_price_points' => $saleItem->price_points,
                    'line_points' => $points,
                ]);
            }

            $sale->update(['total_points' => $total]);

            $payment = $method === WalletMethod::KEY
                ? $this->payments->start(PaymentPurpose::ItemSale, $member, WalletMethod::KEY, [
                    'payable' => $sale,
                    // The member's own points, never a company's they can spend.
                    'wallet_owner_type' => OwnerType::User,
                    'wallet_owner_id' => $member->id,
                ])['payment']
                : $this->payments->settleAtReception(PaymentPurpose::ItemSale, $sale, PaymentMethod::from($method), null, $currency, $operator)->payment;

            return ['sale' => $sale->refresh(), 'payment' => $payment];
        });
    }
}
