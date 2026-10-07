<?php

namespace Tests\Feature\Member;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
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
 * `GET /api/v1/member/packages` — the package catalog ("Packages" tab).
 * The member's own subscription and usage is a separate endpoint/response,
 * see `MembershipCurrentControllerTest`.
 */
class PackageBrowseTest extends TestCase
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

    public function test_unauthenticated_user_cannot_access_packages(): void
    {
        $response = $this->getJson('/api/v1/member/packages');
        $response->assertUnauthorized();
    }

    public function test_package_catalog_matches_design_payload_and_marks_the_current_plan(): void
    {
        $member = $this->actingAsMember();

        $proPlan = Plan::find(2); // The Free Bird

        Membership::create([
            'plan_id' => $proPlan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(15),
            'current_period_end' => Carbon::parse('2025-06-15 23:59:59', 'Asia/Damascus'),
        ]);

        $response = $this->getJson('/api/v1/member/packages');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'module',
                    'subtitle',
                    'pricing' => [
                        'points',
                        'points_label',
                        'price',
                        'price_label',
                        'currency',
                    ],
                    'features' => [
                        '*' => ['id', 'name', 'value', 'icon', 'is_included'],
                    ],
                    'more_features_count',
                    'is_current',
                ],
            ],
        ]);

        // Lightweight browsing list — tags/balances_grant/discounts/
        // resources_grant are all "View details" data, not shown on the
        // card; getPackageDetails() (packages/{plan}) is the one place a
        // client reads them.
        $response->assertJsonMissingPath('data.0.tags');
        $response->assertJsonMissingPath('data.0.balances_grant');
        $response->assertJsonMissingPath('data.0.discounts');
        $response->assertJsonMissingPath('data.0.resources_grant');
        $response->assertJsonMissingPath('data.0.action_button');
        $response->assertJsonMissingPath('data.0.view_details');

        // Check available subscription packages (5 active subscriptions)
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('data.0.name', 'The Smart Starter');
        $response->assertJsonPath('data.0.is_current', false);

        $response->assertJsonPath('data.1.name', 'The Free Bird');
        $response->assertJsonPath('data.1.is_current', true);
        // price_points 750, point.rate_usd 0.10 (SettingSeeder) -> 75 USD.
        $response->assertJsonPath('data.1.pricing.points', 750);
        $response->assertJsonPath('data.1.pricing.points_label', '750 pts / mo');
        $response->assertJsonPath('data.1.pricing.price', 75);
        $response->assertJsonPath('data.1.pricing.price_label', '75 $ / mo');
        $response->assertJsonPath('data.1.pricing.currency', 'USD');

        $response->assertJsonPath('data.2.name', 'The Happy Committer');
        $response->assertJsonPath('data.2.is_current', false);
    }

    /**
     * The catalog is a browsing list, not a details screen — `features` (a
     * free-text bullet list) is capped at 3 with a `more_features_count`
     * remainder. "The Smart Starter" (plan 1) has 5 seeded features.
     */
    public function test_catalog_caps_features_at_three_with_a_remainder_count(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/packages');

        $response->assertOk();

        $starter = collect($response->json('data'))->firstWhere('id', 1);
        $this->assertCount(3, $starter['features']);
        $this->assertSame(2, $starter['more_features_count']);
    }

    public function test_filtering_packages_by_module(): void
    {
        $this->actingAsMember();

        // Filter by Flexi Desk slug
        $response = $this->getJson('/api/v1/member/packages?module=flexi_desk');
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.name', 'The Smart Starter');
        $response->assertJsonPath('data.1.name', 'The Free Bird');
        $response->assertJsonPath('data.2.name', 'The Happy Committer');

        // Filter by Dedicated Desk slug
        $responseDedicated = $this->getJson('/api/v1/member/packages?module=dedicated_desk');
        $responseDedicated->assertOk();
        $responseDedicated->assertJsonCount(2, 'data');
        $responseDedicated->assertJsonPath('data.0.name', 'The Dedicated');
        $responseDedicated->assertJsonPath('data.1.name', 'The Dedicated +');
    }

    public function test_member_without_active_membership_has_no_package_marked_current(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/packages');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('data.0.is_current', false);
        $response->assertJsonPath('data.1.is_current', false);
        $response->assertJsonPath('data.2.is_current', false);
    }

    public function test_a_plan_with_no_discounts_returns_an_empty_array_not_a_missing_key(): void
    {
        $this->actingAsMember();

        $plan = Plan::create([
            'name' => ['en' => 'No Discount Plan', 'ar' => 'خطة بدون خصم'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
            'is_active' => true,
            'order' => 99,
        ]);

        // `discounts` is a "View details" field, not on the catalog card.
        $response = $this->getJson("/api/v1/member/packages/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.discounts', []);
    }

    /**
     * Mirrors the discounts empty-array assertion above, for the
     * resources_grant key (docs/decisions/resource-quota-design.md).
     */
    public function test_a_plan_with_no_resource_grants_returns_an_empty_array_not_a_missing_key(): void
    {
        $this->actingAsMember();

        $plan = Plan::create([
            'name' => ['en' => 'No Resource Grant Plan', 'ar' => 'خطة بدون موارد'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
            'is_active' => true,
            'order' => 99,
        ]);

        // `resources_grant` is a "View details" field, not on the catalog card.
        $response = $this->getJson("/api/v1/member/packages/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.resources_grant', []);
    }

    public function test_purchasing_membership_auto_grants_plan_balances(): void
    {
        $member = $this->actingAsMember();
        $wallets = app(WalletService::class);

        // Member wallet with enough general balance
        $wallet = $wallets->walletFor(OwnerType::User, $member->id);
        $wallets->creditGeneral($wallet, 3000, WalletTransactionSource::TopUp, 'Top-up for purchase');

        // Buy The Dedicated + (id: 6, price: 2500 points)
        // Grants: Cafe (500 points), Printing (350), SpaceSpecific (80)
        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => 6,
        ]);

        $response->assertCreated();

        // Assert member wallet transactions
        $transactions = WalletTransaction::where('wallet_id', $wallet->id)->get();

        // 1 initial credit + 1 purchase debit + 3 auto-grants = 5 transactions
        $this->assertCount(5, $transactions);

        // Cafe grant
        $cafeTx = $transactions->where('category', WalletTransactionCategory::Cafe)->first();
        $this->assertNotNull($cafeTx);
        $this->assertSame(500, (int) $cafeTx->amount);
        $this->assertEquals(WalletTransactionSource::SubscriptionGrant, $cafeTx->source);
        $this->assertNotNull($cafeTx->expires_at);

        // Printing grant
        $printingTx = $transactions->where('category', WalletTransactionCategory::PrintingInternet)->first();
        $this->assertNotNull($printingTx);
        $this->assertSame(350, (int) $printingTx->amount);
        $this->assertEquals(WalletTransactionSource::SubscriptionGrant, $printingTx->source);
        $this->assertNotNull($printingTx->expires_at);

        // SpaceSpecific grant
        $spaceTx = $transactions->where('category', WalletTransactionCategory::SpaceSpecific)->first();
        $this->assertNotNull($spaceTx);
        $this->assertSame(80, (int) $spaceTx->amount);
        $this->assertEquals(WalletTransactionSource::SubscriptionGrant, $spaceTx->source);
        $this->assertNotNull($spaceTx->expires_at);
    }

    public function test_unauthenticated_user_cannot_access_package_details(): void
    {
        $response = $this->getJson('/api/v1/member/packages/1');
        $response->assertUnauthorized();
    }

    /**
     * `GET packages/{plan}` — "View details": the one place a client reads
     * a plan's full, untruncated `features` list (the catalog caps it at 3).
     */
    public function test_package_details_returns_the_full_untruncated_feature_list(): void
    {
        $this->actingAsMember();

        // "The Smart Starter" (plan 1) has 5 seeded features.
        $response = $this->getJson('/api/v1/member/packages/1');

        $response->assertOk();
        $response->assertJsonPath('data.id', 1);
        $response->assertJsonPath('data.name', 'The Smart Starter');
        $response->assertJsonCount(5, 'data.features');
        $response->assertJsonMissingPath('data.more_features_count');
    }

    /**
     * `tags`/`balances_grant`/`discounts` — dropped from the catalog list —
     * are exactly what "View details" exists to show. Badges reflect real
     * plan configuration (a matching `plan_balances` category, or an active
     * discount), not a fixed list: "The Dedicated +" (Cafe/Printing/Meeting
     * balances + a real discount) shows all four.
     */
    public function test_package_details_includes_tags_balances_grant_and_discounts(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/packages/6');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'tags' => ['*' => ['key', 'label', 'icon']],
                'balances_grant' => ['*' => ['category', 'title', 'amount', 'formatted', 'icon']],
                'discounts' => ['*' => ['id', 'percentage', 'title', 'space_id', 'space_type', 'usage_limit', 'icon']],
                'resources_grant',
            ],
        ]);

        $tagKeys = collect($response->json('data.tags'))->pluck('key')->all();
        $this->assertSame(['cafe', 'printing', 'meeting', 'discounts'], $tagKeys);

        // "The Smart Starter" (id 1) has no meeting-room balance.
        $starterResponse = $this->getJson('/api/v1/member/packages/1');
        $starterTagKeys = collect($starterResponse->json('data.tags'))->pluck('key')->all();
        $this->assertNotContains('meeting', $starterTagKeys);
    }

    public function test_package_details_marks_the_members_own_active_plan(): void
    {
        $member = $this->actingAsMember();

        Membership::create([
            'plan_id' => 2,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(1),
            'current_period_end' => Carbon::now('Asia/Damascus')->addDays(29),
        ]);

        $response = $this->getJson('/api/v1/member/packages/2');

        $response->assertOk();
        $response->assertJsonPath('data.is_current', true);
    }

    public function test_package_details_404s_for_a_nonexistent_plan(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/packages/999999');

        $response->assertNotFound();
    }

    public function test_package_details_404s_for_a_non_subscription_plan(): void
    {
        $this->actingAsMember();

        // "1 Day Pass" (plan 4) is a one-time purchase, not a subscription —
        // same visibility rule as the catalog list (is_subscription=true).
        $response = $this->getJson('/api/v1/member/packages/4');

        $response->assertNotFound();
    }

    public function test_package_details_404s_for_an_inactive_plan(): void
    {
        $this->actingAsMember();

        $plan = Plan::create([
            'name' => ['en' => 'Retired Plan', 'ar' => 'خطة متوقفة'],
            'is_subscription' => true,
            'price_points' => 100,
            'duration_days' => 30,
            'is_active' => false,
            'order' => 99,
        ]);

        $response = $this->getJson("/api/v1/member/packages/{$plan->id}");

        $response->assertNotFound();
    }

    public function test_package_details_404s_for_a_private_plan(): void
    {
        $this->actingAsMember();

        $plan = Plan::factory()->private()->create();

        $response = $this->getJson("/api/v1/member/packages/{$plan->id}");

        $response->assertNotFound();
    }

    public function test_a_private_plan_is_absent_from_the_catalog(): void
    {
        $this->actingAsMember();

        $plan = Plan::factory()->private()->create([
            'name' => ['en' => 'Acme Staff Evenings', 'ar' => 'مسائيات أكمي'],
        ]);

        $response = $this->getJson('/api/v1/member/packages');

        $response->assertOk();
        $this->assertNotContains($plan->id, array_column($response->json('data'), 'id'));
    }

    /**
     * Operations assigns a private plan from the dashboard, so its holder
     * never browsed to it. Their own package screen must still open.
     */
    public function test_the_holder_of_a_private_plan_can_still_see_its_details(): void
    {
        $member = $this->actingAsMember();

        $plan = Plan::factory()->private()->create();

        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(29),
        ]);

        $response = $this->getJson("/api/v1/member/packages/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.is_current', true);
    }

    public function test_package_pricing_follows_locale_and_chosen_currency(): void
    {
        $this->actingAsMember();
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);

        // In Arabic with base currency (USD)
        $responseAr = $this->withHeader('lang', 'ar')->getJson('/api/v1/member/packages/2');
        $responseAr->assertOk();
        $responseAr->assertJsonPath('data.pricing.points', 750);
        $responseAr->assertJsonPath('data.pricing.points_label', '750 نقطة / شهرياً');
        $responseAr->assertJsonPath('data.pricing.price', 75);
        $responseAr->assertJsonPath('data.pricing.price_label', '75 $ / شهرياً');
        $responseAr->assertJsonPath('data.pricing.currency', 'USD');

        // In Arabic with SYP currency
        $responseArSyp = $this->withHeaders(['lang' => 'ar', 'currency' => 'SYP'])->getJson('/api/v1/member/packages/2');
        $responseArSyp->assertOk();
        $responseArSyp->assertJsonPath('data.pricing.points', 750);
        $responseArSyp->assertJsonPath('data.pricing.price', 750000);
        $responseArSyp->assertJsonPath('data.pricing.price_label', '750,000 ل.س / شهرياً');
        $responseArSyp->assertJsonPath('data.pricing.currency', 'SYP');

        // In English with SYP currency
        $responseEnSyp = $this->withHeaders(['lang' => 'en', 'currency' => 'SYP'])->getJson('/api/v1/member/packages/2');
        $responseEnSyp->assertOk();
        $responseEnSyp->assertJsonPath('data.pricing.points', 750);
        $responseEnSyp->assertJsonPath('data.pricing.points_label', '750 pts / mo');
        $responseEnSyp->assertJsonPath('data.pricing.price', 750000);
        $responseEnSyp->assertJsonPath('data.pricing.price_label', '750,000 SYP / mo');
        $responseEnSyp->assertJsonPath('data.pricing.currency', 'SYP');
    }
}
