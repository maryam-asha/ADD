<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Invariant 3: payment_amount, payment_currency, exchange_rate_to_base
 * and point_rate_usd record what a member handed over at the till. They
 * are audit history, and a balance must never be computed from them —
 * points revalue when the rate changes, but these columns are frozen at
 * the moment of payment, so mixing them into a sum silently produces a
 * stale balance.
 */
class WalletBalanceIgnoresAuditColumnsTest extends TestCase
{
    use ScansSourceFiles;

    public function test_wallet_service_never_reads_an_audit_column(): void
    {
        $auditColumns = ['payment_amount', 'payment_currency', 'exchange_rate_to_base', 'point_rate_usd'];
        $contents = file_get_contents(app_path('Domain/Membership/Services/WalletService.php'));

        $violations = [];

        foreach ($auditColumns as $column) {
            if (str_contains($contents, $column)) {
                $violations[] = "WalletService references audit column [{$column}]";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
