<?php

namespace App\Domain\Invoicing\Data;

use App\Domain\Providers\Enums\Payer;

/**
 * What an invoice is for, whatever produced it. `$source` is the source's
 * own FK column — ['provider_request_id' => 7] today, an office lease later.
 */
final class InvoiceDraft
{
    /** @param  array<string, int>  $source */
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $providerId,
        public readonly Payer $billedTo,
        public readonly int $points,
        public readonly string $description,
        public readonly array $source,
    ) {}
}
