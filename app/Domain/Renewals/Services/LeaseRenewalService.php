<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Booking\Services\AvailabilityService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Leasing\Services\LeaseScheduleBuilder;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Extends an active lease by one more term — same price, length and
 * billing cycle — on the same lease row: periods are appended and
 * `ends_on` moves (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
 * The door grant ran to the old end, so it is re-issued after commit.
 */
class LeaseRenewalService
{
    public function __construct(
        private readonly LeaseScheduleBuilder $schedule,
        private readonly PayerStanding $standing,
        private readonly AvailabilityService $availability,
        private readonly OfficeLeaseService $leases,
        private readonly SettingService $settings,
    ) {}

    /**
     * Extends from the caller's read of the lease: when `ends_on` moved
     * since (a double click, the run and staff at once) it is refused, so
     * one read extends once. `$automatic` (the renewal run) re-checks under
     * the lock that auto-renew is still on and the end still inside the
     * advance window.
     *
     * @throws RenewalRefused when a check fails (the caller stores the reason)
     * @throws RenewalException when the lease is not active, already extended or (automatic) no longer due
     */
    public function extend(OfficeLease $lease, ?User $actor, bool $checkOverdue = true, bool $automatic = false): OfficeLease
    {
        $extended = DB::transaction(function () use ($lease, $checkOverdue, $automatic) {
            $locked = OfficeLease::query()->whereKey($lease->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OfficeLeaseStatus::Active) {
                throw new RenewalException('api.renewal.lease_not_active', 409);
            }

            if ($locked->ends_on->toDateString() !== $lease->ends_on->toDateString()) {
                throw new RenewalException('api.renewal.already_renewed', 409);
            }

            if ($automatic && (! $locked->auto_renew || ! $this->dueForAutomaticExtension($locked))) {
                throw new RenewalException('api.renewal.not_renewable');
            }

            $space = Space::query()->whereKey($locked->space_id)->lockForUpdate()->firstOrFail();
            $from = $locked->ends_on->addDay()->toDateString();
            $to = $this->schedule->endsOn($from, $locked->duration_months);

            if ($checkOverdue && $this->standing->hasOverdue($locked->billed_to, $locked->provider_id, $locked->company_id)) {
                throw new RenewalRefused(RenewalStopReason::OverdueInvoice);
            }

            if (Company::query()->findOrFail($locked->company_id)->status !== CompanyStatus::Active) {
                throw new RenewalRefused(RenewalStopReason::CompanyInactive);
            }

            if ($space->status !== OperationalStatus::Active) {
                throw new RenewalRefused(RenewalStopReason::OfficeUnavailable);
            }

            if (OfficeLease::query()->overlapping($space->id, $from, $to)->whereKeyNot($locked->id)->exists()
                || $this->availability->hasOpenBookingsOn($space, $from, $to)) {
                throw new RenewalRefused(RenewalStopReason::OfficeTaken);
            }

            $last = (int) OfficeLeasePeriod::query()->where('office_lease_id', $locked->id)->max('sequence');

            foreach ($this->schedule->build($from, $locked->duration_months, $locked->billing_cycle_months, (string) $locked->monthly_amount) as $period) {
                OfficeLeasePeriod::create([
                    ...$period,
                    'office_lease_id' => $locked->id,
                    'sequence' => $last + $period['sequence'],
                    'status' => LeasePeriodStatus::Upcoming,
                ]);
            }

            $locked->update([
                'ends_on' => $to,
                'renewals_count' => $locked->renewals_count + 1,
                'renewal_stop_reason' => null,
            ]);

            return $locked;
        });

        activity()->causedBy($actor)->performedOn($extended)->withProperties([
            'ends_on' => $extended->ends_on->toDateString(),
            'automatic' => $actor === null,
        ])->log('lease_renewed');

        $this->leases->closeDoor($extended);
        $this->leases->openDoor($extended->fresh());

        return $extended->fresh();
    }

    /** The run's window: the lease ends today or within `renewals.advance_days`. */
    private function dueForAutomaticExtension(OfficeLease $lease): bool
    {
        $endsOn = $lease->ends_on->toDateString();
        $advanceDays = (int) $this->settings->get('renewals.advance_days', RenewalRunner::DEFAULT_ADVANCE_DAYS);

        return $endsOn >= now()->toDateString() && $endsOn <= now()->addDays($advanceDays)->toDateString();
    }
}
