<?php

namespace Tests\Feature\Notification;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Domain\Renewals\Services\RenewalRunner;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Feature\Renewals\RenewalsTestCase;

/**
 * A plan someone else switched on, a plan starting, a plan renewed, a desk
 * tied to it: each reaches the member who holds it — or, for a company's
 * plan, the company's admins.
 */
class MembershipNoticesTest extends RenewalsTestCase
{
    use ReadsNotices;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->branch = $this->branchWithSeats(10);
        $this->branch->update(['is_default' => true]);
    }

    public function test_staff_activating_a_plan_tells_the_member(): void
    {
        $member = $this->member();
        $plan = Plan::factory()->create(['name' => ['ar' => 'مرن', 'en' => 'Flexi'], 'duration_days' => 30]);
        $this->actingAsStaff();

        $this->postJson('/api/v1/admin/memberships', ['plan_id' => $plan->id, 'user_id' => $member->id])->assertCreated();

        $notice = $this->assertNoticed($member, 'membership.assigned');
        $this->assertSame(['ar' => 'مرن', 'en' => 'Flexi'], $notice->data['params']['plan']);
        $this->assertSame(now()->addDays(30)->setTimezone('Asia/Damascus')->toDateString(), $notice->data['params']['end']);
    }

    public function test_a_company_plan_tells_its_admins_only(): void
    {
        [$company, $admin, $colleague] = $this->companyWithAdmin(['branch_id' => $this->branch->id]);
        $this->actingAsStaff();

        $this->postJson('/api/v1/admin/memberships', ['plan_id' => Plan::factory()->create()->id, 'company_id' => $company->id])->assertCreated();

        $this->assertNoticed($admin, 'membership.assigned');
        $this->assertNotNoticed($colleague, 'membership.assigned');
    }

    public function test_a_desk_tied_later_tells_the_member(): void
    {
        $space = Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $this->branch->id])->id,
            'space_type' => 'co_space',
            'name' => ['ar' => 'القاعة', 'en' => 'Hall'],
        ]);
        $desk = SeatDesk::factory()->create(['space_id' => $space->id]);
        $member = $this->member();
        $this->actingAsStaff();
        $id = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->dedicatedDesk()->create()->id,
            'user_id' => $member->id,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/memberships/{$id}/desks", ['seat_desk_ids' => [$desk->id]])->assertOk();

        $notice = $this->assertNoticed($member, 'membership.desk_assigned');
        $this->assertSame($desk->code, $notice->data['params']['desk']);
        $this->assertSame(['ar' => 'القاعة', 'en' => 'Hall'], $notice->data['params']['space']);
    }

    public function test_a_provider_assignment_a_renewal_and_its_start_tell_the_admins(): void
    {
        $provider = Provider::factory()->create();
        [$company, $admin] = $this->companyWithAdmin(['provider_id' => $provider->id, 'branch_id' => $this->branchWithSeats(10)->id]);

        $current = $this->assignedMembership($provider, company: $company);
        $renewal = app(MembershipRenewalService::class)->renew($current, null);
        $this->travelTo($renewal->current_period_start->copy()->addMinute());
        app(RenewalRunner::class)->run();

        $this->assertNoticed($admin, 'membership.assigned');
        $this->assertSame(
            $renewal->current_period_end->setTimezone('Asia/Damascus')->toDateString(),
            $this->assertNoticed($admin, 'membership.renewed')->data['params']['date'],
        );
        $this->assertNoticed($admin, 'membership.started');
    }

    /** @return array{Company, User, User} a company, its admin and a plain member */
    private function companyWithAdmin(array $attributes = []): array
    {
        $company = Company::factory()->create($attributes);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        [$admin, $colleague] = [$this->member(), $this->member()];
        $company->members()->attach($admin->id, ['door_access_enabled' => true, 'is_admin' => true]);
        $company->members()->attach($colleague->id, ['door_access_enabled' => true, 'is_admin' => false]);

        return [$company, $admin, $colleague];
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function actingAsStaff(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('admin');
        Sanctum::actingAs($staff, ['dashboard']);
    }
}
