<?php

namespace App\Http\Controllers\Api\V1\Provider\Concerns;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Models\Provider;
use App\Http\Middleware\EnsureActiveProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

trait ActsForProvider
{
    /** The provider EnsureActiveProvider resolved for this request. */
    protected function provider(Request $request): Provider
    {
        return $request->attributes->get(EnsureActiveProvider::ATTRIBUTE);
    }

    /**
     * 404, not 403, for a company that is not this provider's: a provider
     * must not learn that another provider's company exists.
     */
    protected function authorizeCompany(Request $request, Company $company): void
    {
        abort_if(Gate::denies('provider.manage-company', $company), 404);
    }

    /** 404, as for companies: a provider must not learn another provider's invoice exists. */
    protected function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        abort_unless($invoice->provider_id === $this->provider($request)->id, 404);
    }
}
