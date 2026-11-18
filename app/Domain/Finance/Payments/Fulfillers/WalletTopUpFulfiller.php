<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Membership\Enums\WalletTransactionCategory;

/**
 * A top-up buys the points themselves. The credit PaymentService writes is
 * the whole purchase: there is nothing to spend and nothing to deliver.
 */
class WalletTopUpFulfiller implements PaymentFulfiller
{
    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::WalletTopUp;
    }

    public function pointsDue(Payment $payment): int
    {
        return 0;
    }

    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::General;
    }

    public function describe(Payment $payment): string
    {
        return "Wallet top-up #{$payment->id}";
    }

    public function assertPayable(Payment $payment): void {}

    public function fulfil(Payment $payment): void {}
}
