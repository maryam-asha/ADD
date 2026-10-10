<?php

namespace App\Http\Resources;

/** A lease as its company's provider sees it: prices, periods and invoices, no ADD staff fields. */
class ProviderOfficeLeaseResource extends OfficeLeaseResource
{
    protected function staffFields(): array
    {
        return [];
    }
}
