<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Services\Notification\NotificationService;
use App\Support\PlainAmount;

/**
 * Who hears about an invoice: whoever pays it — a company's admins in the
 * app, or a provider's accounts in their portal. Told when it is issued,
 * when a payment is recorded on it, and once when it is past its due date.
 */
class InvoiceNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function issued(Invoice $invoice): void
    {
        $this->tellPayer($invoice, 'invoice.issued', [
            'amount' => (string) $invoice->amount,
            'due' => $invoice->due_at?->toDateString() ?? '',
        ]);
    }

    /** $amount: what was recorded, in the invoice's currency. */
    public function paymentRecorded(Invoice $invoice, string $amount): void
    {
        $this->tellPayer($invoice, 'invoice.payment_recorded', [
            'amount' => PlainAmount::of($amount),
            'remaining' => PlainAmount::of($invoice->remaining()),
        ]);
    }

    /** Reminder: past its due date, with what is left to pay. Once per invoice. */
    public function overdue(Invoice $invoice): void
    {
        $this->tellPayer($invoice, 'invoice.overdue', [
            'amount' => $invoice->remaining(),
        ], "invoice.overdue:{$invoice->id}");
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  string|null  $dedupeKey  a reminder's: sent once per reader
     */
    private function tellPayer(Invoice $invoice, string $templateKey, array $params, ?string $dedupeKey = null): void
    {
        [$readers, $audience] = $invoice->billed_to === Payer::Provider
            ? [Provider::query()->find($invoice->provider_id)?->users()->where('users.status', 'active')->get(), NotificationAudience::Provider]
            : [Company::query()->find($invoice->company_id)?->members()->wherePivot('is_admin', true)->where('users.status', 'active')->get(), NotificationAudience::Member];

        if ($readers === null || $readers->isEmpty()) {
            return;
        }

        $params = ['number' => '#'.$invoice->id, 'currency' => (string) $invoice->currency] + $params;
        $target = ['target_type' => 'invoice', 'target_id' => (string) $invoice->id];

        $dedupeKey === null
            ? $this->notifications->sendToUsers($readers, $templateKey, $params, $target, $audience)
            : $this->notifications->sendToUsersOnce($readers, $dedupeKey, $templateKey, $params, $target, $audience);
    }
}
