<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;

/**
 * Whether a payer is behind: any open invoice past its due date billed to
 * it — the provider's own invoices (every company of it), or the company's.
 */
class PayerStanding
{
    public function hasOverdue(Payer $payer, ?int $providerId, int $companyId): bool
    {
        $query = Invoice::query()->overdue()->where('billed_to', $payer);

        return ($payer === Payer::Provider
            ? $query->where('provider_id', $providerId)
            : $query->where('company_id', $companyId)
        )->exists();
    }
}
