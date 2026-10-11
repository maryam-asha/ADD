<?php

namespace Tests\Feature\Renewals;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\LeaseRenewalService;

class LeaseRenewalTest extends RenewalsTestCase
{
    private function lease(Space $office, array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($office, ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C', ...$overrides]),
            $this->reviewer(),
        );
    }

    private function assertRefused(callable $action, RenewalStopReason $reason): void
    {
        try {
            $action();
            $this->fail("Expected {$reason->value}.");
        } catch (RenewalRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_the_lease_gains_another_term_of_periods_and_its_door_runs_to_the_new_end(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice());
        $oldEnd = $lease->ends_on;
        $oldGrant = $lease->accessGrants()->sole();
        $lease->update(['renewal_stop_reason' => RenewalStopReason::OfficeTaken]);

        $extended = app(LeaseRenewalService::class)->extend($lease, null);

        $this->assertSame($oldEnd->addDay()->addMonthsNoOverflow(12)->subDay()->toDateString(), $extended->ends_on->toDateString());
        $this->assertSame(1, $extended->renewals_count);
        $this->assertNull($extended->renewal_stop_reason);
        $periods = $extended->periods()->get();
        $this->assertCount(8, $periods);
        $this->assertSame([5, 6, 7, 8], $periods->where('status', LeasePeriodStatus::Upcoming)->where('sequence', '>', 4)->pluck('sequence')->values()->all());
        $this->assertSame($oldEnd->addDay()->toDateString(), $periods->firstWhere('sequence', 5)->starts_on->toDateString());
        $this->assertSame('1500.00', (string) $periods->firstWhere('sequence', 8)->amount);

        $this->assertSame(AccessGrantStatus::Revoked, $oldGrant->fresh()->status);
        $live = $extended->accessGrants()->where('status', AccessGrantStatus::Activated)->sole();
        $this->assertSame($extended->ends_on->endOfDay()->timestamp, $live->expires_at->timestamp);
    }

    public function test_a_lease_from_the_31st_keeps_its_day(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false), ['starts_on' => '2026-01-31', 'duration_months' => 2, 'billing_cycle_months' => 1]);

        $extended = app(LeaseRenewalService::class)->extend($lease, null);

        $this->assertSame(['2026-03-31', '2026-04-30'], $extended->periods()->where('sequence', '>', 2)->get()->map(fn ($p) => $p->starts_on->toDateString())->all());
        $this->assertSame('2026-05-30', $extended->ends_on->toDateString());
    }

    public function test_an_office_leased_or_booked_in_the_next_term_stops_the_extension(): void
    {
        $office = $this->leasableOffice(withLock: false);
        $lease = $this->lease($office, ['duration_months' => 3, 'billing_cycle_months' => 3]);

        $booking = Booking::factory()->create([
            'space_id' => $office->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => $lease->ends_on->addDays(10)->setTime(10, 0),
            'end_at' => $lease->ends_on->addDays(10)->setTime(11, 0),
        ]);
        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OfficeTaken);

        $booking->update(['status' => BookingStatus::Cancelled]);
        $this->assertSame(1, app(LeaseRenewalService::class)->extend($lease, null)->renewals_count);
    }

    public function test_another_lease_after_this_one_stops_the_extension(): void
    {
        $office = $this->leasableOffice(withLock: false);
        $lease = $this->lease($office, ['duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->lease($office, ['starts_on' => $lease->ends_on->addMonthsNoOverflow(1)->toDateString(), 'duration_months' => 3, 'billing_cycle_months' => 3]);

        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OfficeTaken);
        $this->assertSame(0, $lease->fresh()->renewals_count);
    }

    public function test_maintenance_overdue_and_inactive_lease(): void
    {
        $office = $this->leasableOffice(withLock: false);
        $lease = $this->lease($office);

        $office->update(['status' => OperationalStatus::Maintenance]);
        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OfficeUnavailable);
        $office->update(['status' => OperationalStatus::Active]);

        Invoice::factory()->create(['provider_id' => null, 'company_id' => $lease->company_id, 'billed_to' => Payer::Company, 'due_at' => now()->subDay()->toDateString()]);
        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OverdueInvoice);
        $this->assertSame(1, app(LeaseRenewalService::class)->extend($lease, $this->reviewer(), checkOverdue: false)->renewals_count);

        app(OfficeLeaseService::class)->terminate($lease->fresh(), $lease->starts_on->toDateString(), 'x', $this->reviewer());
        try {
            app(LeaseRenewalService::class)->extend($lease->fresh(), $this->reviewer(), checkOverdue: false);
            $this->fail('Expected lease_not_active.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.lease_not_active', $e->messageKey);
        }
    }

    public function test_a_stale_read_extends_the_lease_once(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false));

        app(LeaseRenewalService::class)->extend($lease, $this->reviewer(), checkOverdue: false);

        try {
            app(LeaseRenewalService::class)->extend($lease, $this->reviewer(), checkOverdue: false);
            $this->fail('Expected already_renewed.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.already_renewed', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(1, $lease->fresh()->renewals_count);
        $this->assertCount(8, $lease->periods()->get());
    }

    public function test_an_automatic_extension_rechecks_auto_renew_and_the_window_under_the_lock(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false));
        $lease->update(['auto_renew' => true]);
        $this->travelTo($lease->ends_on->subDays(3));

        OfficeLease::query()->whereKey($lease->id)->update(['auto_renew' => false]);
        $this->assertNotRenewable(fn () => app(LeaseRenewalService::class)->extend($lease, null, automatic: true));

        OfficeLease::query()->whereKey($lease->id)->update(['auto_renew' => true]);
        $this->travelTo($lease->ends_on->subDays(8));
        $this->assertNotRenewable(fn () => app(LeaseRenewalService::class)->extend($lease, null, automatic: true));

        $this->assertSame(0, $lease->fresh()->renewals_count);
    }

    public function test_a_stale_door_grant_is_replaced_when_the_door_opens(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice());
        $oldGrant = $lease->accessGrants()->sole();
        $lease->update(['ends_on' => $lease->ends_on->addMonthsNoOverflow(12)->toDateString()]);

        $this->assertTrue(app(OfficeLeaseService::class)->openDoor($lease->fresh()));

        $this->assertSame(AccessGrantStatus::Revoked, $oldGrant->fresh()->status);
        $live = $lease->accessGrants()->where('status', AccessGrantStatus::Activated)->sole();
        $this->assertSame($lease->fresh()->ends_on->endOfDay()->timestamp, $live->expires_at->timestamp);

        $this->assertFalse(app(OfficeLeaseService::class)->openDoor($lease->fresh()));
        $this->assertSame(1, $lease->accessGrants()->where('status', AccessGrantStatus::Activated)->count());
    }

    private function assertNotRenewable(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected not_renewable.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.not_renewable', $e->messageKey);
        }
    }
}
