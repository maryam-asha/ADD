<?php

namespace App\Domain\Providers\Services;

use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandlers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one path for provider requests (docs/decisions/service-providers.md):
 * a provider submits and may cancel while pending; a reviewer approves —
 * which hands the request to its type's handler under a row lock — or
 * rejects with a reason.
 */
class ProviderRequestService
{
    public function __construct(
        private readonly ProviderRequestHandlers $handlers,
        private readonly InvoiceIssuer $invoices,
    ) {}

    public function submit(Provider $provider, User $submitter, ProviderRequestType $type, ?int $companyId, array $payload): ProviderRequest
    {
        $request = ProviderRequest::create([
            'provider_id' => $provider->id,
            'submitted_by' => $submitter->id,
            'type' => $type,
            'company_id' => $companyId,
            'payload' => $payload,
            'status' => ProviderRequestStatus::Pending,
        ]);

        activity()->causedBy($submitter)->performedOn($request)
            ->withProperties(['type' => $type->value, 'company_id' => $companyId])
            ->log('provider_request_submitted');

        return $request;
    }

    public function cancel(ProviderRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $locked = $this->lockPending($request);

            $locked->forceFill(['status' => ProviderRequestStatus::Cancelled])->save();
        });
    }

    /**
     * A pending request asking for the same thing: the same employee, the
     * same admin, or any plan assignment for the same company. Top-ups and
     * plan designs may be asked for more than once.
     */
    public function duplicateExists(ProviderRequestType $type, ?int $companyId, array $payload): bool
    {
        $key = match ($type) {
            ProviderRequestType::CompanyMember => ['payload->phone', $payload['phone'] ?? null],
            ProviderRequestType::CompanyAdmin => ['payload->user_id', $payload['user_id'] ?? null],
            ProviderRequestType::PlanAssignment => null,
            default => false,
        };

        if ($key === false) {
            return false;
        }

        return ProviderRequest::query()
            ->pending()
            ->where('type', $type)
            ->where('company_id', $companyId)
            ->when($key !== null, fn ($query) => $query->where($key[0], $key[1]))
            ->exists();
    }

    /**
     * Carries the request out under a row lock. Everything that may have
     * changed since submission is checked again: the provider and the
     * company still active, the company still this provider's, and the
     * handler's own preconditions. An assignment or a top-up also issues its
     * invoice in the same transaction. A refusal leaves the request pending.
     */
    public function approve(ProviderRequest $request, User $reviewer, array $overrides = [], ?InvoiceTerms $terms = null): ProviderRequest
    {
        $approved = DB::transaction(function () use ($request, $reviewer, $overrides, $terms) {
            $locked = $this->lockPending($request);
            $this->assertStillValid($locked);

            $resultId = $this->handlers->for($locked->type)->handle($locked, $reviewer, $overrides);

            // Phase 2: an assignment or a top-up is invoiced in the same
            // transaction - a pricing refusal undoes the grant with it.
            try {
                $this->invoices->issueForProviderRequest($locked, $reviewer, $terms ?? new InvoiceTerms);
            } catch (InvoicingException $e) {
                throw new ProviderRequestException($e->messageKey, $e->status, $e->params);
            }

            $locked->forceFill([
                'status' => ProviderRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'result_id' => $resultId,
            ])->save();

            activity()->causedBy($reviewer)->performedOn($locked)
                ->withProperties(['type' => $locked->type->value, 'result_id' => $resultId, 'overrides' => $overrides])
                ->log('provider_request_approved');

            return $locked;
        });

        $this->notify($approved, 'provider.request_approved');

        return $approved;
    }

    public function reject(ProviderRequest $request, User $reviewer, string $reason): ProviderRequest
    {
        $rejected = DB::transaction(function () use ($request, $reviewer, $reason) {
            $locked = $this->lockPending($request);

            $locked->forceFill([
                'status' => ProviderRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            activity()->causedBy($reviewer)->performedOn($locked)
                ->withProperties(['type' => $locked->type->value, 'reason' => $reason])
                ->log('provider_request_rejected');

            return $locked;
        });

        $this->notify($rejected, 'provider.request_rejected');

        return $rejected;
    }

    private function lockPending(ProviderRequest $request): ProviderRequest
    {
        $locked = ProviderRequest::query()->lockForUpdate()->findOrFail($request->id);

        if (! $locked->isPending()) {
            throw new ProviderRequestException('api.provider.request_not_pending', 409);
        }

        return $locked;
    }

    private function assertStillValid(ProviderRequest $request): void
    {
        if (! $request->provider->isActive()) {
            throw new ProviderRequestException('api.provider.inactive');
        }

        if ($request->company_id !== null) {
            $company = $request->company;

            if ($company === null || $company->provider_id !== $request->provider_id || $company->status->value !== 'active') {
                throw new ProviderRequestException('api.provider.company_unavailable');
            }
        }
    }

    /** The provider's accounts and whoever submitted (a company admin, for company_admin). */
    private function notify(ProviderRequest $request, string $templateKey): void
    {
        $recipients = $request->provider->users()->pluck('users.id')
            ->push($request->submitted_by)
            ->filter()
            ->unique();

        foreach ($recipients as $userId) {
            NotificationLog::create([
                'user_id' => $userId,
                'channel' => 'push',
                'template_key' => $templateKey,
                'status' => 'sent',
            ]);
        }
    }

    /**
     * Cancels every pending request in $requests, recording why — when a
     * provider is deactivated or a company leaves it. Returns how many.
     *
     * @param  Builder<ProviderRequest>  $requests
     */
    public function cancelPending(Builder $requests, string $reason): int
    {
        return $requests->where('status', ProviderRequestStatus::Pending)->update([
            'status' => ProviderRequestStatus::Cancelled->value,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
