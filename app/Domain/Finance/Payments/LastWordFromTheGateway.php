<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Before a held thing is let go for want of payment, any card still pending
 * for it is asked once more. An accepted one pays it and releases the hold
 * through its fulfiller; a gateway that cannot be reached decides nothing,
 * and the hold is judged on what is known. Called outside any transaction,
 * as every gateway call is.
 */
class LastWordFromTheGateway
{
    public function __construct(
        private readonly SyncPaymentStatus $sync,
        private readonly PaymentMethodRegistry $methods,
    ) {}

    public function ask(Model $hold): void
    {
        Payment::query()
            ->forPayable($hold)
            ->where('status', PaymentStatus::Pending)
            ->whereIn('method', $this->methods->gatewayKeys())
            ->get()
            ->each(function (Payment $payment) {
                try {
                    $this->sync->handle($payment);
                } catch (PaymeraException) {
                    // Unreachable now; the reconciler keeps asking. A later
                    // acceptance credits the member's wallet (needs_review).
                }
            });
    }
}
