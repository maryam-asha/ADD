<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListInvoicesRequest;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Provider invoices (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md).
 * Does not extend AdminResourceController: paginated and filtered, and an
 * invoice is cancelled, never deleted.
 */
class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        return InvoiceResource::collection(self::filtered($request)->latest('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(['provider', 'company', 'issuer', 'payments.recorder']));
    }

    public function cancel(ReasonRequest $request, Invoice $invoice, InvoicePaymentService $payments): JsonResponse
    {
        try {
            $payments->cancel($invoice, $request->validated('reason'), $request->user());
        } catch (InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.invoice.cancelled')]);
    }

    /** The list filters, shared with the provider's list (which scopes the provider first). */
    public static function filtered(ListInvoicesRequest $request, ?Builder $query = null): Builder
    {
        return ($query ?? Invoice::query())
            ->with(['provider', 'company'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->overdue())
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('billed_to'), fn (Builder $q) => $q->where('billed_to', $request->string('billed_to')));
    }
}
