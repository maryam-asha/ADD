<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use Illuminate\Support\Facades\DB;

/**
 * Renews a provider company's subscription
 * (docs/superpowers/specs/2026-10-11-auto-renewal-design.md): the next
 * membership starts where this one ends (or now, if it already ended),
 * same plan, seats and branch, invoiced at the plan's price today in the
 * previous invoice's currency to the payer the provider chose at
 * assignment. Only a subscription that came from a plan-assignment
 * request (or a renewal of one) is renewable.
 */
class MembershipRenewalService
{
    private const MAX_CHAIN = 1000;

    public function __construct(
        private readonly MembershipActivationService $activations,
        private readonly InvoiceIssuer $invoices,
        private readonly PayerStanding $standing,
    ) {}

    /** The plan-assignment request at the root of this subscription's chain, if any. */
    public function origin(Membership $membership): ?ProviderRequest
    {
        $root = $membership;

        for ($i = 0; $root->renewed_from_id !== null && $i < self::MAX_CHAIN; $i++) {
            $root = Membership::query()->findOrFail($root->renewed_from_id);
        }

        return ProviderRequest::query()
            ->where('type', ProviderRequestType::PlanAssignment)
            ->where('result_id', $root->id)
            ->first();
    }

    /** The invoice this subscription was billed with: its renewal invoice, else its request's. */
    public function sourceInvoice(Membership $membership): ?Invoice
    {
        return Invoice::query()->where('membership_id', $membership->id)->first()
            ?? Invoice::query()->whereHas('providerRequest', fn ($q) => $q
                ->where('type', ProviderRequestType::PlanAssignment)
                ->where('result_id', $membership->id))->first();
    }

    public function isRenewable(Membership $membership): bool
    {
        return $membership->owner_type === OwnerType::Company && $this->origin($membership) !== null;
    }

    /**
     * @throws RenewalRefused when a check fails (the caller stores the reason)
     * @throws RenewalException when not renewable or already renewed
     */
    public function renew(Membership $membership, ?User $actor, bool $checkOverdue = true): Membership
    {
        return DB::transaction(function () use ($membership, $actor, $checkOverdue) {
            $locked = Membership::query()->with('plan')->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            if (Membership::query()->where('renewed_from_id', $locked->id)->exists()) {
                throw new RenewalException('api.renewal.already_renewed', 409);
            }

            $origin = $locked->owner_type === OwnerType::Company ? $this->origin($locked) : null;

            if ($origin === null) {
                throw new RenewalException('api.renewal.not_renewable');
            }

            $company = Company::query()->findOrFail($locked->owner_id);
            // A company that has since become direct pays its own way.
            $payer = $company->provider_id === null ? Payer::Company : Payer::from($origin->payload['payer']);

            if ($checkOverdue && $this->standing->hasOverdue($payer, $company->provider_id, $company->id)) {
                throw new RenewalRefused(RenewalStopReason::OverdueInvoice);
            }

            if ($company->status !== CompanyStatus::Active) {
                throw new RenewalRefused(RenewalStopReason::CompanyInactive);
            }

            if (! $locked->plan->is_active) {
                throw new RenewalRefused(RenewalStopReason::PlanInactive);
            }

            $startsAt = $locked->current_period_end->isFuture() ? $locked->current_period_end : now();

            try {
                $renewal = $this->activations->activate(
                    $locked->plan,
                    OwnerType::Company,
                    $company->id,
                    charge: false,
                    auditAction: 'membership_renewed',
                    branchId: $locked->branch_id,
                    seats: $locked->seats,
                    auditProperties: ['renewed_from_id' => $locked->id, 'automatic' => $actor === null],
                    startsAt: $startsAt,
                    attributes: ['auto_renew' => true, 'renewed_from_id' => $locked->id],
                );
            } catch (SeatPoolExhaustedException) {
                throw new RenewalRefused(RenewalStopReason::NoSeats);
            }

            $this->invoices->issueForMembershipRenewal($renewal, $payer, $this->sourceInvoice($locked)?->currency, $actor);

            $locked->update(['renewal_stop_reason' => null]);

            return $renewal;
        });
    }
}
