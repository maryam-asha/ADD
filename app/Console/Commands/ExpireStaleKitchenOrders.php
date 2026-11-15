<?php

namespace App\Console\Commands;

use App\Domain\Finance\Payments\LastWordFromTheGateway;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kitchen orders nobody acts on are cancelled by the system and give back
 * what they took (2026-11-14 spec, decisions 3, 5 and 7), in three sweeps:
 * held for a card past `payment_due_at` — after one last word from the
 * gateway, so a card the bank accepted meanwhile moves the order on instead
 * — awaiting reception past kitchen.reception_window_minutes, and pending
 * past kitchen.accept_window_minutes, an order on a bill included. Each
 * order is locked and re-read where it is let go (KitchenOrderService::expire),
 * so a decision landing in the same instant wins.
 */
class ExpireStaleKitchenOrders extends Command
{
    protected $signature = 'kitchen:expire-stale-orders';

    protected $description = 'Cancel and refund kitchen orders no one paid for, confirmed or accepted in time.';

    public function handle(KitchenOrderService $orders, LastWordFromTheGateway $gateway): int
    {
        $reception = (int) config('kitchen.reception_window_minutes');
        $accept = (int) config('kitchen.accept_window_minutes');

        $counts = [
            'payment' => $this->sweep(
                KitchenOrder::query()
                    ->where('status', KitchenOrderStatus::AwaitingPayment)
                    ->where('payment_due_at', '<=', now()),
                'payment', $orders, $gateway,
            ),
            'reception' => $this->sweep(
                KitchenOrder::query()
                    ->where('status', KitchenOrderStatus::AwaitingReception)
                    ->where(fn (Builder $q) => $q
                        ->where('paid_at', '<=', now()->subMinutes($reception))
                        ->orWhere(fn (Builder $q) => $q->whereNull('paid_at')->where('created_at', '<=', now()->subMinutes($reception)))),
                'reception', $orders, null,
            ),
            'kitchen' => $this->sweep(
                KitchenOrder::query()
                    ->where('status', KitchenOrderStatus::Pending)
                    ->where('reached_kitchen_at', '<=', now()->subMinutes($accept)),
                'kitchen', $orders, null,
            ),
        ];

        if (array_sum($counts) > 0) {
            $this->info("Cancelled {$counts['payment']} unpaid, {$counts['reception']} unconfirmed and {$counts['kitchen']} unaccepted order(s).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  Builder<KitchenOrder>  $query
     * @param  'payment'|'reception'|'kitchen'  $why
     */
    private function sweep(Builder $query, string $why, KitchenOrderService $orders, ?LastWordFromTheGateway $gateway): int
    {
        $cancelled = 0;

        $query->chunkById(100, function ($stale) use ($why, $orders, $gateway, &$cancelled) {
            foreach ($stale as $order) {
                try {
                    $gateway?->ask($order);

                    if ($orders->expire($order, $why)) {
                        $cancelled++;
                    }
                } catch (Throwable $e) {
                    Log::error('[Kitchen] could not cancel a stale order', ['kitchen_order_id' => $order->id, 'why' => $why, 'error' => $e->getMessage()]);
                }
            }
        });

        return $cancelled;
    }
}
