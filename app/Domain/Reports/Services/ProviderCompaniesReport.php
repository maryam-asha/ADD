<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Providers\Models\Provider;

/** One row per client company of a provider: people, plan, leases and what it still owes. */
final class ProviderCompaniesReport
{
    public function __construct(
        private readonly InvoiceAggregates $aggregates,
        private readonly MembershipResolver $memberships,
    ) {}

    /** @return list<array<string, mixed>> */
    public function for(Provider $provider): array
    {
        return Company::query()
            ->where('provider_id', $provider->id)
            ->withCount([
                'members',
                'officeLeases as active_leases_count' => fn ($q) => $q->where('status', OfficeLeaseStatus::Active),
            ])
            ->orderBy('legal_name')
            ->get()
            ->map(function (Company $company) use ($provider) {
                $membership = $this->memberships->activeForOwner(OwnerType::Company, $company->id);

                return [
                    'id' => $company->id,
                    'legal_name' => $company->legal_name,
                    'status' => $company->status,
                    'members_count' => $company->members_count,
                    'membership' => $membership === null ? null : [
                        'plan_name' => $membership->plan?->translate('name'),
                        'seats' => $membership->seats,
                        'current_period_end' => $membership->current_period_end?->toIso8601String(),
                        'auto_renew' => $membership->auto_renew,
                        'renewal_stop_reason' => $membership->renewal_stop_reason?->value,
                        'renewal_stop_reason_label' => $membership->renewal_stop_reason?->label(),
                    ],
                    'active_leases_count' => $company->active_leases_count,
                    'outstanding' => array_map(
                        fn (array $r) => ['currency' => $r['currency'], 'remaining' => $r['remaining'], 'overdue' => $r['overdue']],
                        $this->aggregates->totals(Invoice::query()->where('provider_id', $provider->id)->where('company_id', $company->id)),
                    ),
                ];
            })->values()->all();
    }
}
