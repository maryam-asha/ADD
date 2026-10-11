<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Requests\Admin\StoreInvoicePaymentRequest;
use App\Http\Resources\InvoicePaymentResource;
use Illuminate\Http\JsonResponse;

/** Recording money against an invoice, and voiding a wrong record. Never the cash drawer. */
class InvoicePaymentController extends Controller
{
    public function __construct(private readonly InvoicePaymentService $payments) {}

    public function store(StoreInvoicePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        try {
            $payment = $this->payments->record($invoice, $request->validated(), $request->user());
        } catch (InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return (new InvoicePaymentResource($payment->load('recorder')))->response()->setStatusCode(201);
    }

    public function void(ReasonRequest $request, InvoicePayment $invoicePayment): JsonResponse
    {
        try {
            $this->payments->void($invoicePayment, $request->validated('reason'), $request->user());
        } catch (InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.invoice.payment_voided')]);
    }
}
