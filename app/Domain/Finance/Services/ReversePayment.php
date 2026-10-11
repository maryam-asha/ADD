<?php

namespace App\Domain\Finance\Services;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Identity\Models\User;
use App\Events\Finance\PaymentReversed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reverses an accepted payment at the bank, on an admin's instruction.
 *
 * Only ever admin-initiated. Nothing in the app reverses a payment
 * automatically — not even a plan activation that failed after the charge
 * (that leaves the points with the member instead, by decision). Calling
 * the bank is a human's call.
 *
 * Ordering is deliberate: the gateway is asked first, and the local record
 * only moves to Reversed once the gateway has accepted. Marking locally
 * first would leave a payment reading as reversed while the cardholder was
 * never refunded, which is the worse of the two failure modes.
 *
 * Only a payment made through a gateway can be reversed here. One paid
 * another way (wallet points, cash) has no bank to call, and is refused
 * with UnknownPaymentMethodException before anything is touched.
 */
class ReversePayment
{
    use LogsSensitiveActions;

    public function __construct(private readonly PaymentMethodRegistry $registry) {}

    /**
     * @throws UnknownPaymentMethodException when the payment's method is not a gateway
     */
    public function handle(Payment $payment, User $actor): Payment
    {
        if (! $payment->status->isReversible()) {
            throw new RuntimeException(
                "Payment {$payment->id} is {$payment->status->value} and cannot be reversed."
            );
        }

        // Resolved up front, before any lock is taken: a payment with no
        // gateway behind it has nothing to reverse.
        $gateway = $this->registry->gatewayFor($payment);

        if ($payment->payment_id === null) {
            throw new RuntimeException("Payment {$payment->id} has no gateway id to reverse.");
        }

        // Shares SyncPaymentStatus's lock key so a reversal cannot interleave
        // with a status sync deciding the same payment's fate.
        $lock = Cache::lock("payments:payment:{$payment->id}", 30);

        if (! $lock->get()) {
            throw new RuntimeException("Payment {$payment->id} is busy; try again.");
        }

        try {
            $payment->refresh();

            // Re-checked inside the lock: two admins pressing the button at
            // once must produce one reversal, not two.
            if (! $payment->status->isReversible()) {
                throw new RuntimeException(
                    "Payment {$payment->id} is {$payment->status->value} and cannot be reversed."
                );
            }

            $gateway->cancel($payment);

            DB::transaction(function () use ($payment, $actor) {
                $payment->forceFill([
                    'status' => PaymentStatus::Reversed,
                    'reversed_at' => now(),
                    'reversed_by' => $actor->id,
                ])->save();

                event(new PaymentReversed($payment));
            });

            $this->logSensitiveAction('payment_reversed', $payment, [
                'gateway_payment_id' => $payment->payment_id,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'points_credited' => $payment->points_to_credit,
                'reversed_by' => $actor->id,
            ]);

            return $payment->refresh();
        } finally {
            $lock->release();
        }
    }
}
