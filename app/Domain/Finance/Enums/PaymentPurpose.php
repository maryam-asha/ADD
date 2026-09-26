<?php

namespace App\Domain\Finance\Enums;

/**
 * Why the member is paying. Both purposes credit the wallet — points are
 * this system's unit of account and card money has to become points on the
 * way in, exactly as cash does at the reception till — but `PlanPurchase`
 * additionally activates the plan recorded on the payment.
 *
 * If that activation fails after the money has been taken, the points stay
 * in the member's wallet and the payment is flagged NeedsReview. The
 * charge is never silently swallowed and the bank is never called
 * automatically (docs/paymera-integration-plan.md, decision 3).
 */
enum PaymentPurpose: string
{
    case WalletTopUp = 'wallet_top_up';
    case PlanPurchase = 'plan_purchase';
}
