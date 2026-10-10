<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListInvoicesRequest;
use App\Http\Resources\ProviderInvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The provider's invoices — read only (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md):
 * those billed to it and those billed to its companies.
 */
class ProviderInvoiceController extends Controller
{
    use ActsForProvider;

    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $query = Invoice::query()->where('provider_id', $this->provider($request)->id);

        return ProviderInvoiceResource::collection(
            InvoiceController::filtered($request, $query)->latest('id')->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Request $request, Invoice $invoice): ProviderInvoiceResource
    {
        $this->authorizeInvoice($request, $invoice);

        return new ProviderInvoiceResource($invoice->load(['company', 'payments']));
    }
}
