<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseDailyRunner;
use App\Domain\Leasing\Services\OfficeLeaseService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

class LeaseDailyRunnerTest extends LeasingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function lease(Space $office, array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($office), 'contract_ref' => 'C', ...$overrides]),
            auth()->user(),
        );
    }

    private function runDaily(): array
    {
        return app(LeaseDailyRunner::class)->run();
    }

    public function test_a_period_is_invoiced_on_its_first_day_once(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false));
        $this->travelTo($lease->periods()->where('sequence', 2)->first()->starts_on);

        $this->assertSame(1, $this->runDaily()['invoices']);
        $this->assertSame(0, $this->runDaily()['invoices']);
        $this->assertSame(2, Invoice::query()->whereNotNull('office_lease_period_id')->count());
        $this->assertNull(Invoice::query()->latest('id')->first()->issued_by);
    }

    public function test_missed_days_catch_up_on_the_next_run(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false));
        $this->travelTo($lease->periods()->where('sequence', 3)->first()->starts_on->addDays(5));

        $this->assertSame(2, $this->runDaily()['invoices']);
    }

    public function test_a_back_dated_lease_leaves_nothing_for_the_run(): void
    {
        $this->lease($this->leasableOffice(withLock: false), ['starts_on' => now()->subMonthsNoOverflow(7)->toDateString(), 'billing_cycle_months' => 1]);

        $this->assertSame(0, $this->runDaily()['invoices']);
    }

    public function test_the_door_opens_on_the_first_day_and_closes_after_the_last(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice(), ['duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->assertSame(0, $this->runDaily()['doors_opened']);

        $this->travelTo($lease->starts_on->setTime(6, 0));
        $this->assertSame(1, $this->runDaily()['doors_opened']);
        $this->assertSame(0, $this->runDaily()['doors_opened']);

        $this->travelTo($lease->ends_on->addDay()->setTime(6, 0));
        $result = $this->runDaily();
        $this->assertSame(1, $result['ended']);
        $this->assertSame(1, $result['doors_closed']);
        $this->assertSame(OfficeLeaseStatus::Ended, $lease->fresh()->status);
        $this->assertSame(AccessGrantStatus::Revoked, $lease->accessGrants()->sole()->status);
    }

    public function test_a_future_termination_closes_the_door_on_its_date(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->toDateString()]);
        app(OfficeLeaseService::class)->terminate($lease, now()->addDays(10)->toDateString(), 'Moving out', auth()->user());

        $this->travel(9)->days();
        $this->assertSame(0, $this->runDaily()['doors_closed']);

        $this->travel(1)->days();
        $this->assertSame(1, $this->runDaily()['doors_closed']);
        $this->assertSame(AccessGrantStatus::Revoked, $lease->accessGrants()->sole()->status);
    }

    public function test_maintenance_takes_the_door_away_and_the_run_gives_it_back(): void
    {
        $this->fakeTtlock();
        $office = $this->leasableOffice();
        $lease = $this->lease($office, ['starts_on' => now()->toDateString()]);

        $office->update(['status' => OperationalStatus::Maintenance]);
        $this->artisan('access:revoke-grants-on-maintenance')->assertSuccessful();
        $this->assertSame(0, $this->runDaily()['doors_opened']);

        $office->update(['status' => OperationalStatus::Active]);
        $this->assertSame(1, $this->runDaily()['doors_opened']);
        $this->assertSame(1, $lease->accessGrants()->where('status', AccessGrantStatus::Activated)->count());
    }

    public function test_a_lock_failure_on_one_lease_does_not_stop_the_others(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::sequence()
            ->push('', 500)
            ->push(['keyboardPwd' => '445566', 'keyboardPwdId' => 6], 200)
            ->push(['keyboardPwd' => '778899', 'keyboardPwdId' => 7], 200)]);
        $this->fakeTtlock();
        $first = $this->lease($this->leasableOffice());
        $second = $this->lease($this->leasableOffice());
        $this->travelTo($first->starts_on->setTime(6, 0));

        $this->assertSame(1, $this->runDaily()['doors_opened']);
        $this->assertSame(0, $first->accessGrants()->count());
        $this->assertSame(1, $second->accessGrants()->count());

        $this->travel(1)->days();
        $this->assertSame(1, $this->runDaily()['doors_opened']);
        $this->assertSame(1, $first->accessGrants()->count());
    }

    public function test_a_terminated_lease_past_its_end_stays_terminated(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false), ['duration_months' => 3, 'billing_cycle_months' => 3]);
        app(OfficeLeaseService::class)->terminate($lease, $lease->ends_on->toDateString(), 'Moving out', auth()->user());

        $this->travelTo($lease->ends_on->addDay()->setTime(6, 0));

        $this->assertSame(0, $this->runDaily()['ended']);
        $this->assertSame(OfficeLeaseStatus::Terminated, $lease->fresh()->status);
    }

    /** Terminated between the run reading the lease and ending it: the termination wins. */
    public function test_ending_never_overwrites_a_termination_made_meanwhile(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false), ['duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->travelTo($lease->ends_on->addDay()->setTime(6, 0));

        OfficeLease::retrieved(function (OfficeLease $read) {
            OfficeLease::query()->whereKey($read->id)->update(['status' => OfficeLeaseStatus::Terminated, 'terminated_on' => $read->ends_on]);
        });

        $this->assertSame(0, app(LeaseDailyRunner::class)->endExpired());
        // Read past the model, whose retrieved hook above would fire again.
        $this->assertSame('terminated', OfficeLease::query()->toBase()->where('id', $lease->id)->value('status'));
        $this->assertSame(0, Activity::query()->where('description', 'lease_ended')->count());
    }

    public function test_a_door_that_failed_to_close_is_not_counted_closed(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->toDateString(), 'duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->assertSame(1, $lease->accessGrants()->count());

        $this->partialMock(PasscodeIssuanceService::class, fn ($mock) => $mock->shouldReceive('revokeForLease')->andThrow(new \RuntimeException('Lock service down')));
        $this->travelTo($lease->ends_on->addDay()->setTime(6, 0));

        $this->assertSame(0, app(LeaseDailyRunner::class)->closeDoors());
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $this->artisan('leases:run-daily')->assertSuccessful();

        $this->artisan('schedule:list')->expectsOutputToContain('leases:run-daily');

        // Hourly, not once a day: a door whose lock call failed, or a lease
        // made after the morning run, is opened within the hour.
        $event = collect(app(Schedule::class)->events())->sole(fn ($event) => str_contains((string) $event->command, 'leases:run-daily'));
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
