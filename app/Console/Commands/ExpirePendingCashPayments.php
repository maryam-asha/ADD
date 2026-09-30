<?php

namespace App\Console\Commands;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\CashMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A plan chosen with cash is held for the member to pay at the desk
 * (CashMethod, `payments.cash_plan_hold_hours`). Nothing was taken, so a
 * hold nobody came to pay simply lapses: it becomes Expired, and reception
 * can no longer settle it.
 *
 * Only cash holds. A gateway's pending payment has money possibly in flight
 * at the bank, and paymera:reconcile alone decides its fate.
 *
 * Each hold is expired on its own, and only if it is still pending at that
 * moment — reception settling it in the same instant wins. One that fails
 * is logged and the rest carry on.
 */
class ExpirePendingCashPayments extends Command
{
    protected $signature = 'payments:expire-pending';

    protected $description = 'Let cash plan holds that were never paid at reception lapse.';

    public function handle(): int
    {
        $expired = 0;

        Payment::query()
            ->where('method', CashMethod::KEY)
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->select('id')
            ->chunkById(200, function ($payments) use (&$expired) {
                foreach ($payments as $payment) {
                    try {
                        $expired += Payment::query()
                            ->whereKey($payment->id)
                            ->where('status', PaymentStatus::Pending)
                            ->update(['status' => PaymentStatus::Expired->value]);
                    } catch (Throwable $e) {
                        Log::error('[Payments] could not expire a cash hold', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($expired > 0) {
            $this->info("Expired {$expired} unpaid cash hold(s).");
        }

        return self::SUCCESS;
    }
}
