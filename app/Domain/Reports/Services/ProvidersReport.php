<?php

namespace App\Domain\Reports\Services;

use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Models\Provider;

/**
 * Every provider at a glance: its companies, the seats it holds in each
 * branch's co-working pool (and its share of that pool), its leases and
 * what it owes. Pool capacity comes from CoworkingPoolService only.
 */
final class ProvidersReport
{
    public function __construct(
        private readonly InvoiceAggregates $aggregates,
        private readonly CoworkingPoolService $pool,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $capacity = [];

        return Provider::query()->orderBy('name')->get()->map(function (Provider $provider) use (&$capacity) {
            $companies = Company::query()->where('provider_id', $provider->id);
            $companyIds = (clone $companies)->pluck('id');

            $held = Membership::query()->live()
                ->where('owner_type', OwnerType::Company)
                ->whereIn('owner_id', $companyIds)
                ->selectRaw('branch_id, SUM(seats) AS seats')
                ->groupBy('branch_id')->orderBy('branch_id')
                ->toBase()->get();

            $branchShare = $held->map(function ($row) use (&$capacity) {
                $branch = Branch::query()->find($row->branch_id);
                $seats = (int) $row->seats;
                $cap = $branch === null ? 0 : $capacity[$branch->id] ??= $this->capacity($branch);

                return [
                    'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                    'branch_name' => $branch?->translate('name'),
                    'seats' => $seats,
                    'capacity' => $cap,
                    'share' => $cap > 0 ? bcdiv((string) $seats, (string) $cap, 2) : null,
                ];
            })->values()->all();

            return [
                'id' => $provider->id,
                'name' => $provider->name,
                'status' => $provider->status,
                'active_companies' => (clone $companies)->where('status', CompanyStatus::Active)->count(),
                'seats' => (int) $held->sum('seats'),
                'branch_share' => $branchShare,
                'active_leases' => OfficeLease::query()->whereIn('company_id', $companyIds)->where('status', OfficeLeaseStatus::Active)->count(),
                'outstanding' => array_map(
                    fn (array $r) => ['currency' => $r['currency'], 'remaining' => $r['remaining'], 'overdue' => $r['overdue']],
                    $this->aggregates->totals(Invoice::query()->where('provider_id', $provider->id)),
                ),
            ];
        })->values()->all();
    }

    /** The branch's co-working seats open now. */
    private function capacity(Branch $branch): int
    {
        return $this->pool->timeline($branch, now(), now()->addMinute())->capacityAt(now());
    }
}
