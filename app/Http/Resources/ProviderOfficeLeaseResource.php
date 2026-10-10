<?php

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Http\Middleware\EnsureActiveProvider;
use Illuminate\Http\Request;

/** A lease as its company's provider sees it: prices, periods and invoices, no ADD staff fields. */
class ProviderOfficeLeaseResource extends OfficeLeaseResource
{
    /**
     * Only the invoices billed to the viewing provider (the one
     * EnsureActiveProvider resolved for this request): after a company moves
     * providers, the periods the previous one was billed for are its own
     * business, not the new provider's.
     */
    protected function showsInvoice(Invoice $invoice, Request $request): bool
    {
        $provider = $request->attributes->get(EnsureActiveProvider::ATTRIBUTE);

        return $provider !== null && $invoice->provider_id === $provider->id;
    }

    protected function staffFields(): array
    {
        return [];
    }
}
