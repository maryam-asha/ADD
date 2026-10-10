<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Enums\Payer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a lease is made, ended early, and given or denied its door
 * (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 */
class OfficeLeaseService
{
    public function __construct(
        private readonly LeaseScheduleBuilder $schedule,
        private readonly InvoiceIssuer $invoices,
        private readonly PasscodeIssuanceService $passcodes,
    ) {}

    /**
     * Closes a quoted office request with a lease. $newCompanyName given: the
     * request is for a new company, created here with its wallet in the
     * office's branch; null: the request names an existing company. One
     * transaction — a refusal or a pricing failure saves nothing. Every
     * period already started (the first always) is invoiced now; the door
     * opens after commit, and a lock failure never undoes the lease.
     */
    public function contract(PrivateOfficeRequest $request, LeaseTerms $terms, User $actor, ?string $newCompanyName = null): OfficeLease
    {
        $lease = DB::transaction(function () use ($request, $terms, $actor, $newCompanyName) {
            $request = PrivateOfficeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== PrivateOfficeRequestStatus::Quoted) {
                throw new LeasingException('api.lease.request_not_quoted');
            }

            // Locking the office row serialises two contracts for the same
            // office: the second sees the first lease below and is refused.
            $space = Space::query()->with('building')->whereKey($terms->spaceId)->lockForUpdate()->firstOrFail();
            $this->assertLeasable($space);

            $endsOn = $this->schedule->endsOn($terms->startsOn, $terms->durationMonths);

            if (OfficeLease::query()->overlapping($space->id, $terms->startsOn, $endsOn)->exists()) {
                throw new LeasingException('api.lease.office_taken', 409);
            }

            $company = $newCompanyName === null
                ? $this->existingCompany($request)
                : $this->newCompany($request, $newCompanyName, $terms->contractRef, $space->building->branch_id);

            $lease = OfficeLease::create([
                'company_id' => $company->id,
                'space_id' => $space->id,
                'private_office_request_id' => $request->id,
                'provider_id' => $company->provider_id,
                'billed_to' => $this->payer($company, $request, $terms->billedTo),
                'currency' => $terms->currency,
                'monthly_amount' => $terms->monthlyAmount,
                'starts_on' => $terms->startsOn,
                'duration_months' => $terms->durationMonths,
                'billing_cycle_months' => $terms->billingCycleMonths,
                'ends_on' => $endsOn,
                'status' => OfficeLeaseStatus::Active,
                'created_by' => $actor->id,
            ]);

            foreach ($this->schedule->build($terms->startsOn, $terms->durationMonths, $terms->billingCycleMonths, $terms->monthlyAmount) as $period) {
                $lease->periods()->create([...$period, 'status' => LeasePeriodStatus::Upcoming]);
            }

            OfficeLeasePeriod::query()
                ->where('office_lease_id', $lease->id)
                ->where(fn ($q) => $q->where('sequence', 1)->orWhereDate('starts_on', '<=', now()->toDateString()))
                ->orderBy('sequence')
                ->get()
                ->each(fn (OfficeLeasePeriod $period) => $this->invoices->issueForLeasePeriod($period, $actor));

            $request->update([
                'status' => PrivateOfficeRequestStatus::Contracted,
                'contract_ref' => $terms->contractRef,
                'converted_company_id' => $company->id,
            ]);

            return $lease;
        });

        activity()->causedBy($actor)->performedOn($lease)->withProperties([
            'company_id' => $lease->company_id,
            'space_id' => $lease->space_id,
            'private_office_request_id' => $lease->private_office_request_id,
        ])->log('lease_created');

        $this->openDoor($lease);

        return $lease->refresh();
    }

    /**
     * Issues the office lock grant when the lease grants access today, the
     * office is active, has a lock, and no live grant exists yet. Never
     * throws: a lock-service failure is logged and the daily run retries.
     */
    public function openDoor(OfficeLease $lease): bool
    {
        $lease->loadMissing('space');

        if (! $lease->grantsAccessOn(now()->toDateString())
            || $lease->space->status !== OperationalStatus::Active
            || ! $this->passcodes->hasLock($lease->space)
            || $lease->accessGrants()->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])->exists()) {
            return false;
        }

        try {
            $this->passcodes->issueForLease($lease);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to issue the office lock grant for a lease', [
                'office_lease_id' => $lease->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function assertLeasable(Space $space): void
    {
        if ($space->space_type !== SpaceType::Business) {
            throw new LeasingException('api.lease.not_an_office');
        }

        if ($space->status !== OperationalStatus::Active) {
            throw new LeasingException('api.lease.office_unavailable');
        }
    }

    private function existingCompany(PrivateOfficeRequest $request): Company
    {
        if ($request->company_id === null) {
            throw new LeasingException('api.lease.request_has_no_company');
        }

        $company = Company::query()->findOrFail($request->company_id);

        if ($company->status !== CompanyStatus::Active) {
            throw new LeasingException('api.lease.company_inactive');
        }

        return $company;
    }

    /** The one path into `companies` (PRD §5.1), formerly inline in CompanyController::store. */
    private function newCompany(PrivateOfficeRequest $request, string $legalName, string $contractRef, int $branchId): Company
    {
        if ($request->company_id !== null) {
            throw new LeasingException('api.lease.request_is_for_existing_company');
        }

        $company = Company::create([
            'legal_name' => $legalName,
            'contract_ref' => $contractRef,
            'branch_id' => $branchId,
            'status' => CompanyStatus::Active,
            'created_from_request_id' => $request->id,
            'provider_id' => $request->provider_id,
        ]);

        Wallet::create([
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
        ]);

        return $company;
    }

    /** A direct company always pays itself; a provider's pays as staff chose, else as the request said. */
    private function payer(Company $company, PrivateOfficeRequest $request, ?Payer $chosen): Payer
    {
        if ($company->provider_id === null) {
            return Payer::Company;
        }

        return $chosen ?? $request->payer ?? throw new LeasingException('api.lease.payer_required');
    }
}
