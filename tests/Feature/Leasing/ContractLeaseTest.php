<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Illuminate\Support\Facades\Http;

class ContractLeaseTest extends LeasingTestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actor = $this->actingAsAdmin();
    }

    private function terms(Space $space, array $overrides = []): LeaseTerms
    {
        return LeaseTerms::fromArray([...$this->leasePayload($space), 'contract_ref' => 'C-2001', ...$overrides]);
    }

    private function service(): OfficeLeaseService
    {
        return app(OfficeLeaseService::class);
    }

    /** Runs $action and asserts it is refused with $key and $status. */
    private function assertRefused(callable $action, string $key, int $status = 422): void
    {
        try {
            $action();
            $this->fail("Expected the lease to be refused with {$key}.");
        } catch (LeasingException $e) {
            $this->assertSame($key, $e->messageKey);
            $this->assertSame($status, $e->status);
        }
    }

    public function test_a_new_company_gets_its_lease_periods_and_first_invoice(): void
    {
        $branch = Branch::factory()->create();
        $office = $this->leasableOffice($branch);
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($office), $this->actor, 'Acme LLC');

        $company = $lease->company;
        $this->assertSame('Acme LLC', $company->legal_name);
        $this->assertSame($branch->id, $company->branch_id);
        $this->assertSame('C-2001', $company->contract_ref);
        $this->assertSame($request->id, $company->created_from_request_id);
        $this->assertNull($company->provider_id);
        $this->assertTrue(Wallet::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->exists());

        $this->assertSame(Payer::Company, $lease->billed_to);
        $this->assertSame($this->actor->id, $lease->created_by);
        $this->assertSame(now()->addDay()->addMonthsNoOverflow(12)->subDay()->toDateString(), $lease->ends_on->toDateString());
        $this->assertSame(
            [LeasePeriodStatus::Invoiced, LeasePeriodStatus::Upcoming, LeasePeriodStatus::Upcoming, LeasePeriodStatus::Upcoming],
            $lease->periods->pluck('status')->all(),
        );
        $invoice = $lease->periods->first()->invoice;
        $this->assertSame('1500', (string) $invoice->amount);
        $this->assertSame($this->actor->id, $invoice->issued_by);

        $request->refresh();
        $this->assertSame(PrivateOfficeRequestStatus::Contracted, $request->status);
        $this->assertSame('C-2001', $request->contract_ref);
        $this->assertSame($company->id, $request->converted_company_id);
        $this->assertSame(0, $lease->accessGrants()->count(), 'A lease starting tomorrow opens no door today.');
    }

    public function test_an_existing_company_gets_another_office_in_another_branch(): void
    {
        $company = Company::factory()->create();
        $office = $this->leasableOffice(Branch::factory()->create());
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]);

        $lease = $this->service()->contract($request, $this->terms($office), $this->actor);

        $this->assertSame($company->id, $lease->company_id);
        $this->assertSame(1, Company::query()->count());
        $this->assertSame($company->id, $request->fresh()->converted_company_id);
    }

    public function test_a_provider_company_bills_the_request_payer_unless_staff_choose(): void
    {
        $provider = Provider::factory()->create();
        $company = Company::factory()->create(['provider_id' => $provider->id]);

        $asRequested = $this->service()->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $provider->id, 'payer' => Payer::Provider]),
            $this->terms($this->leasableOffice()),
            $this->actor,
        );
        $overridden = $this->service()->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $provider->id, 'payer' => Payer::Provider]),
            $this->terms($this->leasableOffice(), ['billed_to' => 'company']),
            $this->actor,
        );

        $this->assertSame(Payer::Provider, $asRequested->billed_to);
        $this->assertSame($provider->id, $asRequested->provider_id);
        $this->assertSame(Payer::Provider, $asRequested->periods->first()->invoice->billed_to);
        $this->assertSame(Payer::Company, $overridden->billed_to);
    }

    public function test_a_provider_company_with_no_payer_anywhere_is_refused(): void
    {
        $provider = Provider::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['provider_id' => $provider->id]);

        $this->assertRefused(
            fn () => $this->service()->contract($request, $this->terms($this->leasableOffice()), $this->actor, 'Client Co'),
            'api.lease.payer_required',
        );
        $this->assertSame(0, Company::query()->count());
    }

    public function test_a_back_dated_lease_invoices_every_started_period_and_opens_the_door(): void
    {
        $this->fakeTtlock();
        $office = $this->leasableOffice();
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($office, [
            'starts_on' => now()->subMonthsNoOverflow(7)->toDateString(),
            'billing_cycle_months' => 1,
        ]), $this->actor, 'Late LLC');

        $this->assertSame(8, $lease->periods()->where('status', LeasePeriodStatus::Invoiced)->count());
        $this->assertSame(4, $lease->periods()->where('status', LeasePeriodStatus::Upcoming)->count());
        $this->assertSame(AccessGrantStatus::Activated, $lease->accessGrants()->sole()->status);
    }

    public function test_a_lease_on_an_office_without_a_lock_is_kept_without_a_door(): void
    {
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($this->leasableOffice(withLock: false), [
            'starts_on' => now()->toDateString(),
        ]), $this->actor, 'No Lock LLC');

        $this->assertTrue($lease->exists);
        $this->assertSame(0, $lease->accessGrants()->count());
    }

    public function test_a_failing_lock_service_does_not_undo_the_lease(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::response('', 500)]);
        $this->fakeTtlock();
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($this->leasableOffice(), [
            'starts_on' => now()->toDateString(),
        ]), $this->actor, 'Offline LLC');

        $this->assertTrue($lease->exists);
        $this->assertSame(0, $lease->accessGrants()->count());
    }

    public function test_an_office_taken_for_part_of_the_dates_is_refused(): void
    {
        $office = $this->leasableOffice();
        $this->service()->contract(PrivateOfficeRequest::factory()->quoted()->create(), $this->terms($office), $this->actor, 'First LLC');

        $this->assertRefused(
            fn () => $this->service()->contract(
                PrivateOfficeRequest::factory()->quoted()->create(),
                $this->terms($office, ['starts_on' => now()->addMonthsNoOverflow(11)->toDateString()]),
                $this->actor,
                'Second LLC',
            ),
            'api.lease.office_taken',
            409,
        );
        $this->assertSame(1, OfficeLease::query()->count());
        $this->assertSame(1, Company::query()->count());
    }

    /** The reverse of a leased office refusing bookings: bookings still to happen refuse the lease. */
    public function test_an_office_with_a_booking_still_to_happen_in_the_window_is_refused(): void
    {
        $office = $this->leasableOffice();
        Booking::factory()->create(['space_id' => $office->id, 'start_at' => now()->addMonth()->setTime(10, 0), 'end_at' => now()->addMonth()->setTime(11, 0)]);

        $this->assertRefused(
            fn () => $this->service()->contract(PrivateOfficeRequest::factory()->quoted()->create(), $this->terms($office), $this->actor, 'Acme LLC'),
            'api.lease.office_booked',
            409,
        );
        $this->assertSame(0, OfficeLease::query()->count());
    }

    public function test_cancelled_bookings_and_bookings_outside_the_window_do_not_block_a_lease(): void
    {
        $office = $this->leasableOffice();
        Booking::factory()->cancelled()->create(['space_id' => $office->id, 'start_at' => now()->addMonth()->setTime(10, 0), 'end_at' => now()->addMonth()->setTime(11, 0)]);
        // Today, the day before the lease starts tomorrow.
        Booking::factory()->create(['space_id' => $office->id, 'start_at' => now()->setTime(8, 0), 'end_at' => now()->setTime(9, 0)]);

        $this->service()->contract(PrivateOfficeRequest::factory()->quoted()->create(), $this->terms($office), $this->actor, 'Acme LLC');

        $this->assertSame(1, OfficeLease::query()->count());
    }

    public function test_the_office_is_free_again_the_day_after_a_lease_ends(): void
    {
        $office = $this->leasableOffice();
        $first = $this->service()->contract(PrivateOfficeRequest::factory()->quoted()->create(), $this->terms($office), $this->actor, 'First LLC');

        $second = $this->service()->contract(
            PrivateOfficeRequest::factory()->quoted()->create(),
            $this->terms($office, ['starts_on' => $first->ends_on->addDay()->toDateString()]),
            $this->actor,
            'Second LLC',
        );

        $this->assertTrue($second->exists);
    }

    public function test_refusals_save_nothing(): void
    {
        $quoted = fn (array $attributes = []) => PrivateOfficeRequest::factory()->quoted()->create($attributes);

        $this->assertRefused(fn () => $this->service()->contract(PrivateOfficeRequest::factory()->create(), $this->terms($this->leasableOffice()), $this->actor, 'X'), 'api.lease.request_not_quoted');

        $coSpace = Space::factory()->ofType(SpaceType::CoSpace)->create();
        $this->assertRefused(fn () => $this->service()->contract($quoted(), $this->terms($coSpace), $this->actor, 'X'), 'api.lease.not_an_office');

        $maintenance = $this->leasableOffice();
        $maintenance->update(['status' => OperationalStatus::Maintenance]);
        $this->assertRefused(fn () => $this->service()->contract($quoted(), $this->terms($maintenance), $this->actor, 'X'), 'api.lease.office_unavailable');

        $company = Company::factory()->create();
        $this->assertRefused(fn () => $this->service()->contract($quoted(['company_id' => $company->id]), $this->terms($this->leasableOffice()), $this->actor, 'X'), 'api.lease.request_is_for_existing_company');
        $this->assertRefused(fn () => $this->service()->contract($quoted(), $this->terms($this->leasableOffice()), $this->actor), 'api.lease.request_has_no_company');

        $company->update(['status' => CompanyStatus::Inactive]);
        $this->assertRefused(fn () => $this->service()->contract($quoted(['company_id' => $company->id]), $this->terms($this->leasableOffice()), $this->actor), 'api.lease.company_inactive');

        $this->assertSame(0, OfficeLease::query()->count());
        $this->assertSame(1, Company::query()->count());
    }

    public function test_a_currency_without_a_rate_rolls_everything_back(): void
    {
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        try {
            $this->service()->contract($request, $this->terms($this->leasableOffice(), ['currency' => 'SYP', 'monthly_amount' => '5000000']), $this->actor, 'Rate LLC');
            $this->fail('Expected no_exchange_rate.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.no_exchange_rate', $e->messageKey);
        }

        $this->assertSame(0, OfficeLease::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(PrivateOfficeRequestStatus::Quoted, $request->fresh()->status);
    }
}
