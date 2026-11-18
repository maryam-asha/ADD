<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET member/memberships: every subscription the member holds or held —
 * their own and those of the companies they belong to, active or expired —
 * newest first, each saying whose it is (`via`).
 */
class MemberMembershipListTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'Asia/Damascus')->utc());

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->company = Company::factory()->create(['legal_name' => 'Acme LLC']);
        $this->company->members()->attach($this->member->id, ['is_admin' => false]);
        Sanctum::actingAs($this->member, ['member-app']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function membership(OwnerType $type, int $ownerId, string $start, int $days, string $plan): Membership
    {
        $startAt = Carbon::parse($start, 'Asia/Damascus')->utc();

        return Membership::factory()->create([
            'plan_id' => Plan::factory()->create(['name' => ['en' => $plan, 'ar' => $plan]])->id,
            'owner_type' => $type,
            'owner_id' => $ownerId,
            'status' => MembershipStatus::Active,
            'current_period_start' => $startAt,
            'current_period_end' => $startAt->copy()->addDays($days),
        ]);
    }

    public function test_it_lists_personal_and_company_subscriptions_active_and_expired_newest_first(): void
    {
        $expired = $this->membership(OwnerType::User, $this->member->id, '2026-07-01 09:00', 30, 'Old Flex');
        $company = $this->membership(OwnerType::Company, $this->company->id, '2026-09-20 09:00', 30, 'Team Plan');
        $active = $this->membership(OwnerType::User, $this->member->id, '2026-10-01 09:00', 30, 'Flex');

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/memberships')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.plan.name', 'Flex')
            ->assertJsonPath('data.0.via', 'personal')
            ->assertJsonPath('data.0.company', null)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.id', $company->id)
            ->assertJsonPath('data.1.via', 'company')
            ->assertJsonPath('data.1.company.id', $this->company->id)
            ->assertJsonPath('data.1.company.name', 'Acme LLC')
            ->assertJsonPath('data.1.status', 'active')
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonPath('data.2.id', $expired->id)
            ->assertJsonPath('data.2.status', 'expired')
            ->assertJsonPath('data.2.is_current', false);
    }

    /**
     * The current one is the one memberships/current shows: the member's own
     * active plan first, else a company's — and none once all have ended.
     */
    public function test_the_current_one_is_the_one_memberships_current_shows(): void
    {
        $this->membership(OwnerType::User, $this->member->id, '2026-07-01 09:00', 30, 'Old Flex');
        $company = $this->membership(OwnerType::Company, $this->company->id, '2026-09-20 09:00', 30, 'Team Plan');

        $this->getJson('/api/v1/member/memberships')
            ->assertJsonPath('data.0.id', $company->id)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.is_current', false);
        $this->getJson('/api/v1/member/memberships/current')
            ->assertJsonPath('data.membership_id', $company->id);

        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00', 'Asia/Damascus')->utc());

        $this->getJson('/api/v1/member/memberships')
            ->assertJsonPath('data.0.is_current', false)
            ->assertJsonPath('data.1.is_current', false);
    }

    public function test_it_filters_by_status_and_by_whose_it_is(): void
    {
        $this->membership(OwnerType::User, $this->member->id, '2026-07-01 09:00', 30, 'Old Flex');
        $company = $this->membership(OwnerType::Company, $this->company->id, '2026-09-20 09:00', 30, 'Team Plan');
        $active = $this->membership(OwnerType::User, $this->member->id, '2026-10-01 09:00', 30, 'Flex');

        $this->getJson('/api/v1/member/memberships?status=active')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.1.id', $company->id);

        $this->getJson('/api/v1/member/memberships?status=expired')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'expired');

        $this->getJson('/api/v1/member/memberships?via=company')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $company->id);

        $this->getJson('/api/v1/member/memberships?via=personal&status=active')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_a_subscription_ending_today_is_active_until_its_end(): void
    {
        $this->membership(OwnerType::User, $this->member->id, '2026-09-07 12:00', 30, 'Flex');

        $this->getJson('/api/v1/member/memberships')->assertJsonPath('data.0.status', 'active');

        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:01', 'Asia/Damascus')->utc());

        $this->getJson('/api/v1/member/memberships')->assertJsonPath('data.0.status', 'expired');
    }

    public function test_other_peoples_and_other_companies_subscriptions_are_not_listed(): void
    {
        $this->membership(OwnerType::User, User::factory()->create()->id, '2026-10-01 09:00', 30, 'Someone Else');
        $this->membership(OwnerType::Company, Company::factory()->create()->id, '2026-10-01 09:00', 30, 'Other Company');

        $this->getJson('/api/v1/member/memberships')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_a_renewal_made_ahead_is_upcoming_and_filterable(): void
    {
        $live = $this->membership(OwnerType::Company, $this->company->id, '2026-10-01 09:00', 30, 'Team');
        $next = $this->membership(OwnerType::Company, $this->company->id, '2026-10-31 09:00', 30, 'Team');

        $this->getJson('/api/v1/member/memberships')
            ->assertOk()
            ->assertJsonPath('data.0.id', $next->id)
            ->assertJsonPath('data.0.status', 'upcoming')
            ->assertJsonPath('data.1.id', $live->id)
            ->assertJsonPath('data.1.status', 'active');

        $this->getJson('/api/v1/member/memberships?status=upcoming')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $next->id);

        $this->getJson('/api/v1/member/memberships?status=expired')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->getJson('/api/v1/member/memberships?status=cancelled&via=team')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'via']);
    }
}
