<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\ResourceQuotaService;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PlanModuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/v1/member/memberships/current` — the member's own active plan
 * and this-cycle usage ("My Subscription" tab). The package catalog is a
 * separate endpoint/response, see `PackageBrowseTest`.
 */
class MembershipCurrentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(PlanModuleSeeder::class);
        $this->seed(PlanSeeder::class);
        Carbon::setTestNow(Carbon::parse('2025-05-16 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::firstOrCreate([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);
        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en');

        return $member;
    }

    public function test_unauthenticated_user_cannot_access_current_membership(): void
    {
        $response = $this->getJson('/api/v1/member/memberships/current');
        $response->assertUnauthorized();
    }

    public function test_member_without_active_membership_gets_null_data(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/memberships/current');

        $response->assertOk();
        $response->assertJsonPath('data', null);
    }

    public function test_active_plan_matches_design_payload(): void
    {
        $member = $this->actingAsMember();

        $proPlan = Plan::find(2); // The Free Bird
        $endDate = Carbon::parse('2025-06-15 23:59:59', 'Asia/Damascus');
        Membership::create([
            'plan_id' => $proPlan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(15),
            'current_period_end' => $endDate,
        ]);

        // Cafe (400 points = $40.00) and Printing (250 points = $25.00) —
        // SettingSeeder fixes point.rate_usd at 0.10.
        $wallet = Wallet::firstOrCreate([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);

        $wallets = app(WalletService::class);
        $wallets->creditCategorized($wallet, WalletTransactionCategory::Cafe, '400', WalletTransactionSource::SubscriptionGrant, $endDate, null, [], 'Café monthly balance');
        $wallets->creditCategorized($wallet, WalletTransactionCategory::PrintingInternet, '250', WalletTransactionSource::SubscriptionGrant, $endDate, null, [], 'Printing monthly balance');

        $response = $this->getJson('/api/v1/member/memberships/current');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'membership_id',
                'name',
                'module',
                'tag',
                'status',
                'status_label',
                'is_active',
                'current_period_start',
                'current_period_end',
                'validity_text',
                'billing_cycle_text',
                'benefits_title',
                'usage_title',
                'balance_rollover_notice',
                'benefits' => [
                    '*' => ['key', 'title', 'subtitle', 'icon'],
                ],
                'discounts' => [
                    '*' => ['id', 'percentage', 'title', 'space_id', 'space_type', 'usage_limit', 'icon'],
                ],
                'resources' => [
                    '*' => ['id', 'resource_type_id', 'slug', 'name', 'unit_label', 'icon', 'granted', 'remaining', 'title'],
                ],
                'balances' => [
                    '*' => [
                        'category', 'title', 'unit', 'granted', 'used', 'remaining', 'percentage_used',
                        'formatted_granted', 'formatted_used', 'formatted_remaining', 'remaining_label',
                        'amount', 'formatted', 'icon',
                    ],
                ],
            ],
        ]);

        $response->assertJsonMissingPath('data.balance_notice');
        $response->assertJsonMissingPath('data.action_button');
        $response->assertJsonPath('data.name', 'The Free Bird');
        $response->assertJsonPath('data.status', 'active');

        // Nothing was spent yet, so granted === remaining and percentage_used is 0.
        $response->assertJsonPath('data.balances.0.category', 'cafe');
        $response->assertJsonPath('data.balances.0.unit', 'usd');
        $response->assertJsonPath('data.balances.0.granted', 40);
        $response->assertJsonPath('data.balances.0.used', 0);
        $response->assertJsonPath('data.balances.0.percentage_used', 0);
        $response->assertJsonPath('data.balances.0.amount', 40);
        $response->assertJsonPath('data.balances.0.formatted', '$40.00');
        $response->assertJsonPath('data.balances.1.category', 'printing_internet');
        $response->assertJsonPath('data.balances.1.amount', 25);
        $response->assertJsonPath('data.balances.1.formatted', '$25.00');

        // "The Free Bird" has no meeting-room balance, so only 2 usage cards.
        $response->assertJsonCount(2, 'data.balances');

        // Café + Printing balances exist, no meeting balance, but real
        // discounts (asserted below) — badges must reflect exactly that.
        $benefitKeys = collect($response->json('data.benefits'))->pluck('key')->all();
        $this->assertSame(['cafe_access', 'printing_credits', 'member_discounts'], $benefitKeys);

        // The Free Bird carries real plan_discounts rows (PlanSeeder), not
        // just text — this is the whole point of the discounts key existing.
        $discounts = $response->json('data.discounts');
        $this->assertNotEmpty($discounts);
        $this->assertSame(30, collect($discounts)->first()['percentage']);
    }

    /**
     * Meeting Rooms (`space_specific`) is the one category displayed in
     * hours, not dollars — `hourly_rate_points` is per-space and not fixed,
     * so the raw point grant is treated as an hour count 1:1 by product
     * convention (PlanSeeder literally calls the same figure "Meeting Room
     * Hours"), not converted through `PointConversionService`.
     */
    public function test_usage_tracks_granted_used_and_remaining_per_category(): void
    {
        $member = $this->actingAsMember();
        $wallets = app(WalletService::class);

        // The Dedicated + : Cafe 500, PrintingInternet 350, SpaceSpecific 80.
        $plan = Plan::find(6);

        $endDate = Carbon::parse('2025-06-15 23:59:59', 'Asia/Damascus');
        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(1),
            'current_period_end' => $endDate,
        ]);

        $wallet = Wallet::firstOrCreate([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);

        $wallets->creditCategorized($wallet, WalletTransactionCategory::SpaceSpecific, '80', WalletTransactionSource::SubscriptionGrant, $endDate, null, [], 'Meeting Room Hours');

        // A 20-hour meeting-room booking debit, recorded directly (this test
        // is about PackageBrowseService's aggregation, not WalletService's
        // own debit-resolution algorithm, which is covered elsewhere).
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'amount' => -20,
            'category' => WalletTransactionCategory::SpaceSpecific,
            'source' => WalletTransactionSource::TopUp,
            'description' => 'Meeting room booking',
        ]);

        $response = $this->getJson('/api/v1/member/memberships/current');

        $response->assertOk();
        $response->assertJsonCount(3, 'data.balances');

        $meeting = collect($response->json('data.balances'))->firstWhere('category', 'space_specific');

        $this->assertNotNull($meeting);
        $this->assertSame('hours', $meeting['unit']);
        $this->assertSame(80, $meeting['granted']);
        $this->assertSame(20, $meeting['used']);
        $this->assertSame(60, $meeting['remaining']);
        $this->assertSame(25, $meeting['percentage_used']);
        $this->assertSame('60 hours', $meeting['formatted_remaining']);

        // Cafe/Printing/Meeting balances plus this plan's real discounts
        // (PlanSeeder) — all four badges apply.
        $benefitKeys = collect($response->json('data.benefits'))->pluck('key')->all();
        $this->assertSame(['cafe_access', 'printing_credits', 'meeting_rooms', 'member_discounts'], $benefitKeys);
    }

    public function test_a_plan_with_no_discounts_returns_an_empty_array_not_a_missing_key(): void
    {
        $member = $this->actingAsMember();

        $plan = Plan::create([
            'name' => ['en' => 'No Discount Plan', 'ar' => 'خطة بدون خصم'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
            'included_hours' => 0,
            'is_active' => true,
            'order' => 99,
        ]);

        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(1),
            'current_period_end' => Carbon::now('Asia/Damascus')->addDays(29),
        ]);

        $response = $this->getJson('/api/v1/member/memberships/current');

        $response->assertOk();
        $response->assertJsonPath('data.discounts', []);
    }

    /**
     * Mirrors the discounts empty-array assertion above, for the
     * resources key (docs/decisions/resource-quota-design.md).
     */
    public function test_a_plan_with_no_resource_grants_returns_an_empty_array_not_a_missing_key(): void
    {
        $member = $this->actingAsMember();

        $plan = Plan::create([
            'name' => ['en' => 'No Resource Grant Plan', 'ar' => 'خطة بدون موارد'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
            'included_hours' => 0,
            'is_active' => true,
            'order' => 99,
        ]);

        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(1),
            'current_period_end' => Carbon::now('Asia/Damascus')->addDays(29),
        ]);

        $response = $this->getJson('/api/v1/member/memberships/current');

        $response->assertOk();
        $response->assertJsonPath('data.resources', []);
    }

    /**
     * Simulates reception having already recorded consumption against the
     * member's active membership before the member ever loads the "My
     * Subscription" screen — `remaining` must reflect `granted - consumed`,
     * not just the raw grant.
     */
    public function test_resources_reflect_prior_consumption(): void
    {
        $member = $this->actingAsMember();

        $plan = Plan::create([
            'name' => ['en' => 'Printing Plan', 'ar' => 'خطة الطباعة'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
            'included_hours' => 0,
            'is_active' => true,
            'order' => 98,
        ]);

        $resourceType = ResourceType::create([
            'slug' => 'printing',
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
        ]);

        $grant = $plan->resourceGrants()->create([
            'resource_type_id' => $resourceType->id,
            'quantity' => 100,
        ]);

        $membership = Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(1),
            'current_period_end' => Carbon::now('Asia/Damascus')->addDays(29),
        ]);

        app(ResourceQuotaService::class)->consume($grant, $membership, 12, $member);

        $response = $this->getJson('/api/v1/member/memberships/current');

        $response->assertOk();
        $response->assertJsonPath('data.resources.0.granted', 100);
        $response->assertJsonPath('data.resources.0.remaining', 88);
    }
}
