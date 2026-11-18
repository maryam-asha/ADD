<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\Wallet;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/v1/member/memberships/current` — the member's plan ("My plan"
 * tab): whose it is, its period, and one list of cards to draw
 * (CurrentMembershipStats). The package catalog is a separate endpoint.
 */
class MembershipCurrentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_plan_is_null_data(): void
    {
        $this->actingAsMember();

        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertExactJson(['data' => null, 'pending_purchase' => null]);
    }

    public function test_the_response_has_only_the_agreed_keys(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe(Plan::factory()->create(), OwnerType::User, $member->id);

        $response = $this->getJson('/api/v1/member/memberships/current')->assertOk();

        $this->assertSame(['membership_id', 'via', 'company', 'plan', 'period', 'stats'], array_keys($response->json('data')));
        $this->assertSame(['id', 'name', 'module', 'seat', 'desks'], array_keys($response->json('data.plan')));
        $this->assertSame(['start', 'end', 'cycle_start', 'cycle_end'], array_keys($response->json('data.period')));
        $response->assertJsonPath('data.membership_id', $membership->id)
            ->assertJsonPath('data.via', 'personal')
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.plan.seat', 'flexible')
            ->assertJsonPath('data.plan.desks', null)
            ->assertJsonPath('data.stats.0.key', 'period')
            ->assertJsonPath('data.stats.1.key', 'workspace_hours')
            ->assertJsonMissingPath('data.applied_discounts');
    }

    public function test_a_company_plan_says_whose_it_is_and_counts_the_employees_own_hours(): void
    {
        $member = $this->actingAsMember();
        $colleague = User::factory()->create();
        $company = Company::factory()->create();
        $company->members()->attach($member->id, ['is_admin' => false]);
        $company->members()->attach($colleague->id, ['is_admin' => false]);
        $membership = $this->subscribe(Plan::factory()->coworking(20)->create(), OwnerType::Company, $company->id);
        $this->spend($membership, $member, 3600);
        $this->spend($membership, $colleague, 7200);

        $response = $this->getJson('/api/v1/member/memberships/current')->assertOk()
            ->assertJsonPath('data.via', 'company')
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.company.name', $company->legal_name);

        $hours = collect($response->json('data.stats'))->firstWhere('key', 'workspace_hours');
        $this->assertEquals(1, $hours['used']);
        $this->assertEquals(19, $hours['remaining']);
    }

    public function test_a_dedicated_desk_lists_its_desks_and_unlimited_hours(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe(Plan::factory()->dedicatedDesk()->create(), OwnerType::User, $member->id);

        $response = $this->getJson('/api/v1/member/memberships/current')->assertOk()
            ->assertJsonPath('data.plan.seat', 'dedicated')
            ->assertJsonPath('data.plan.desks', []);

        $hours = collect($response->json('data.stats'))->firstWhere('key', 'workspace_hours');
        $this->assertSame('value', $hours['display']);
        $this->assertNull($hours['total']);
    }

    public function test_pending_purchase_stays_beside_data(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe(Plan::factory()->create(), OwnerType::User, $member->id);

        $response = $this->getJson('/api/v1/member/memberships/current')->assertOk();

        $this->assertSame(['data', 'pending_purchase'], array_keys($response->json()));
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en');

        return $member;
    }

    private function subscribe(Plan $plan, OwnerType $ownerType, int $ownerId): Membership
    {
        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(12),
            'current_period_end' => now()->addDays(18),
        ]);
    }

    private function spend(Membership $membership, User $user, int $seconds): void
    {
        PlanHourUsage::factory()->create([
            'membership_id' => $membership->id,
            'user_id' => $user->id,
            'allowance_kind' => 'coworking',
            'cycle_start' => $membership->current_period_start,
            'seconds' => $seconds,
        ]);
    }
}
