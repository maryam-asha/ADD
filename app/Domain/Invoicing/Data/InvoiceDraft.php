<?php

namespace App\Domain\Invoicing\Data;

use App\Domain\Providers\Enums\Payer;

/**
 * What an invoice is for, whatever produced it. Either `points` (priced in
 * money at issue — plan assignments, top-ups) or a `fixedAmount` in
 * `fixedCurrency` (office lease periods). `$source` is the source's own FK
 * column: ['provider_request_id' => 7] or ['office_lease_period_id' => 3].
 */
final class InvoiceDraft
{
    /** @param  array<string, int>  $source */
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $providerId,
        public readonly Payer $billedTo,
        public readonly ?int $points,
        public readonly string $description,
        public readonly array $source,
        public readonly ?string $fixedAmount = null,
        public readonly ?string $fixedCurrency = null,
    ) {}

    public function isFixed(): bool
    {
        return $this->fixedAmount !== null;
    }
}
