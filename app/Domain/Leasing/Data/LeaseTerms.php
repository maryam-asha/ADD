<?php

namespace App\Domain\Leasing\Data;

use App\Domain\Providers\Enums\Payer;

/** What staff agreed for a lease, as validated by ValidatesLeaseTerms. Dates are Y-m-d. */
final class LeaseTerms
{
    public function __construct(
        public readonly int $spaceId,
        public readonly string $startsOn,
        public readonly int $durationMonths,
        public readonly int $billingCycleMonths,
        public readonly string $monthlyAmount,
        public readonly string $currency,
        public readonly string $contractRef,
        public readonly ?Payer $billedTo = null,
        public readonly bool $autoRenew = false,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            spaceId: (int) $validated['space_id'],
            startsOn: (string) $validated['starts_on'],
            durationMonths: (int) $validated['duration_months'],
            billingCycleMonths: (int) $validated['billing_cycle_months'],
            monthlyAmount: (string) $validated['monthly_amount'],
            currency: (string) $validated['currency'],
            contractRef: (string) $validated['contract_ref'],
            billedTo: isset($validated['billed_to']) ? Payer::from($validated['billed_to']) : null,
            autoRenew: (bool) ($validated['auto_renew'] ?? false),
        );
    }
}
