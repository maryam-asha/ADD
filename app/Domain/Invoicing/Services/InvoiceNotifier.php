<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Services\Notification\NotificationService;

/**
 * Who hears that an invoice was issued: whoever pays it — a company's admins
 * in the app, or a provider's accounts in their portal.
 */
class InvoiceNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function issued(Invoice $invoice): void
    {
        $params = [
            'number' => '#'.$invoice->id,
            'amount' => (string) $invoice->amount,
            'currency' => (string) $invoice->currency,
            'due' => $invoice->due_at?->toDateString() ?? '',
        ];
        $target = ['target_type' => 'invoice', 'target_id' => (string) $invoice->id];

        if ($invoice->billed_to === Payer::Provider) {
            $accounts = Provider::query()->find($invoice->provider_id)?->users()->where('users.status', 'active')->get();

            if ($accounts !== null && $accounts->isNotEmpty()) {
                $this->notifications->sendToUsers($accounts, 'invoice.issued', $params, $target, NotificationAudience::Provider);
            }

            return;
        }

        $admins = Company::query()->find($invoice->company_id)?->members()
            ->wherePivot('is_admin', true)
            ->where('users.status', 'active')
            ->get();

        if ($admins !== null && $admins->isNotEmpty()) {
            $this->notifications->sendToUsers($admins, 'invoice.issued', $params, $target);
        }
    }
}
