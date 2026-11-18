<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseDailyRunner;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Tests\Feature\Leasing\LeasingTestCase;
use Tests\Feature\Notification\Concerns\ReadsNotices;

/**
 * A lease signed reaches the company's admins; a lease over — at its end or
 * ended early — also reaches every member who could open its door, since
 * that door is about to stop opening for them.
 */
class LeaseNoticesTest extends LeasingTestCase
{
    use ReadsNotices;

    private Company $company;

    private User $admin;

    private User $doorHolder;

    private User $bystander;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        $this->actingAsAdmin();

        $this->company = Company::factory()->create();
        [$this->admin, $this->doorHolder, $this->bystander] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $this->company->members()->attach($this->admin->id, ['door_access_enabled' => false, 'is_admin' => true]);
        $this->company->members()->attach($this->doorHolder->id, ['door_access_enabled' => true, 'is_admin' => false]);
        $this->company->members()->attach($this->bystander->id, ['door_access_enabled' => false, 'is_admin' => false]);
    }

    public function test_a_signed_lease_tells_the_admins(): void
    {
        $lease = $this->lease();

        $notice = $this->assertNoticed($this->admin, 'lease.contracted');
        $this->assertSame($lease->starts_on->toDateString(), $notice->data['params']['start']);
        $this->assertSame($lease->ends_on->toDateString(), $notice->data['params']['end']);
        $this->assertNotNoticed($this->doorHolder, 'lease.contracted');
    }

    public function test_a_lease_ended_early_tells_the_admins_and_the_door_holders(): void
    {
        $lease = $this->lease();
        $on = now()->addDays(10)->toDateString();

        app(OfficeLeaseService::class)->terminate($lease, $on, 'Moving out', auth()->user());

        $this->assertSame($on, $this->assertNoticed($this->admin, 'lease.ended')->data['params']['date']);
        $this->assertNoticed($this->doorHolder, 'lease.ended');
        $this->assertNotNoticed($this->bystander, 'lease.ended');
    }

    public function test_a_lease_reaching_its_end_tells_them_once(): void
    {
        $lease = $this->lease(['duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->travelTo($lease->ends_on->addDay()->setTime(6, 0));

        app(LeaseDailyRunner::class)->run();
        app(LeaseDailyRunner::class)->run();

        $this->assertSame($lease->ends_on->addDay()->toDateString(), $this->assertNoticed($this->admin, 'lease.ended')->data['params']['date']);
        $this->assertNoticed($this->doorHolder, 'lease.ended');
    }

    private function lease(array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $this->company->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false), $overrides), 'contract_ref' => 'C']),
            auth()->user(),
        );
    }
}
