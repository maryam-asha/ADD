<?php

namespace App\Domain\Membership\Enums;

/**
 * Documentation/reporting only (docs/decisions/wallet-points-categorization.md).
 * No code may branch on this enum's value to decide spend behavior — the
 * debit-resolution algorithm reads only `category`, `expires_at`, and
 * `wallet_transaction_allowed_users`, never `source`.
 */
enum WalletTransactionSource: string
{
    case TopUp = 'top_up';
    case SubscriptionGrant = 'subscription_grant';
    case Gift = 'gift';
    case CompanyAdminAllocation = 'company_admin_allocation';
    case Refund = 'refund';
}
