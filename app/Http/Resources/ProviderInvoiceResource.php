<?php

namespace App\Http\Resources;

/** An invoice as the provider sees it: no ADD staff fields, no computed amount or rate. */
class ProviderInvoiceResource extends InvoiceResource
{
    protected string $paymentResource = ProviderInvoicePaymentResource::class;

    protected function staffFields(): array
    {
        return [];
    }
}
