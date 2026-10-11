<?php

namespace App\Http\Resources;

/** A payment as the provider sees it: no ADD staff fields. */
class ProviderInvoicePaymentResource extends InvoicePaymentResource
{
    protected function staffFields(): array
    {
        return [];
    }
}
