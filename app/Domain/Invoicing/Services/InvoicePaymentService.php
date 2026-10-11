<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;

/**
 * Every change to what an invoice has been paid, under its row lock, so
 * two payments recorded at once can never together exceed it.
 */
class InvoicePaymentService
{
    public function __construct(private readonly InvoiceMoney $money) {}

    /** @param  array{amount: string, currency: string, method: string, receipt_number?: ?string, paid_at: string}  $data */
    public function record(Invoice $invoice, array $data, User $by): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $by) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $locked->status->isOpen()) {
                throw new InvoicingException('api.invoice.not_payable', 409);
            }

            if (bccomp((string) $data['amount'], '0', 8) <= 0) {
                throw new InvoicingException('api.invoice.amount_too_small');
            }

            $converted = $this->money->toInvoiceCurrency((string) $data['amount'], $data['currency'], $locked->currency, $data['paid_at']);
            $amount = $converted['amount'];

            if (bccomp($amount, '0', 2) <= 0) {
                throw new InvoicingException('api.invoice.amount_too_small');
            }

            $remaining = $locked->remaining();
            $shortfall = bcsub($remaining, $amount, 2);

            if (bccomp($shortfall, '0', 2) < 0) {
                throw new InvoicingException('api.invoice.overpayment', 422, ['remaining' => $remaining, 'currency' => $locked->currency]);
            }

            // Converting from another currency truncates, which can leave less than
            // one minor unit open on a payment worth exactly the remainder: that
            // payment settles it. A same-currency payment is never rounded, so
            // one unit short stays open.
            if ($data['currency'] !== $locked->currency && bccomp($shortfall, '0', 2) > 0 && bccomp($shortfall, $this->money->minorUnit($locked->currency), 2) <= 0) {
                $amount = $remaining;
            }

            $payment = $locked->payments()->create([
                'received_amount' => $data['amount'],
                'received_currency' => $data['currency'],
                'exchange_rate' => $converted['rate'],
                'amount' => $amount,
                'method' => $data['method'],
                'receipt_number' => $data['receipt_number'] ?? null,
                'paid_at' => $data['paid_at'],
                'recorded_by' => $by->id,
            ]);

            $locked->paid_amount = bcadd((string) $locked->paid_amount, $amount, 2);
            $locked->refreshStatus();
            $locked->save();

            activity()->causedBy($by)->performedOn($locked)
                ->withProperties(['payment_id' => $payment->id, 'amount' => $amount, 'received' => $data['amount'].' '.$data['currency']])
                ->log('invoice_payment_recorded');

            return $payment;
        });
    }

    public function void(InvoicePayment $payment, string $reason, User $by): void
    {
        DB::transaction(function () use ($payment, $reason, $by) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $locked = InvoicePayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isVoided()) {
                throw new InvoicingException('api.invoice.payment_already_voided', 409);
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();

            $invoice->paid_amount = bcsub((string) $invoice->paid_amount, (string) $locked->amount, 2);
            $invoice->refreshStatus();
            $invoice->save();

            activity()->causedBy($by)->performedOn($invoice)
                ->withProperties(['payment_id' => $locked->id, 'reason' => $reason])
                ->log('invoice_payment_voided');
        });
    }

    public function cancel(Invoice $invoice, string $reason, User $by): void
    {
        DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Cancelled) {
                throw new InvoicingException('api.invoice.already_cancelled', 409);
            }

            if ($locked->payments()->active()->exists()) {
                throw new InvoicingException('api.invoice.has_payments');
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            activity()->causedBy($by)->performedOn($locked)
                ->withProperties(['reason' => $reason])
                ->log('invoice_cancelled');
        });
    }
}
