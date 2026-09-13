<?php

namespace Tests\Feature\Member;

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

    public function test_member_with_active_plan_and_balances_gets_matching_design_payload(): void
    {
        $member = $this->actingAsMember();

        $proPlan = Plan::find(2); // The Free Bird

        // Create active membership ending on Jun 15, 2025
        $endDate = Carbon::parse('2025-06-15 23:59:59', 'Asia/Damascus');
        Membership::create([
            'plan_id' => $proPlan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => Carbon::now('Asia/Damascus')->subDays(15),
            'current_period_end' => $endDate,
        ]);

        // Create wallet and credit Cafe ($40.00) and Printing ($25.00)
        $wallet = Wallet::firstOrCreate([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);

        $wallets = app(WalletService::class);
        $wallets->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '40.00',
            WalletTransactionSource::SubscriptionGrant,
            $endDate,
            null,
            [],
            'Café monthly balance'
        );
        $wallets->creditCategorized(
            $wallet,
            WalletTransactionCategory::PrintingInternet,
            '25.00',
            WalletTransactionSource::SubscriptionGrant,
            $endDate,
            null,
            [],
            'Printing monthly balance'
        );

        $response = $this->getJson('/api/v1/member/packages');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'current_plan' => [
                    'id',
                    'membership_id',
                    'name',
                    'module',
                    'tag',
                    'status',
                    'status_label',
                    'is_active',
                    'current_period_end',
                    'validity_text',
                    'benefits' => [
                        '*' => ['key', 'title', 'subtitle', 'icon'],
                    ],
                    'balances' => [
                        '*' => ['category', 'title', 'amount', 'formatted', 'expires_at', 'icon'],
                    ],
                ],
                'available_packages' => [
                    '*' => [
                        'id',
                        'name',
                        'module',
                        'subtitle',
                        'price',
                        'currency',
                        'currency_symbol',
                        'billing_period',
                        'formatted_price',
                        'tags' => [
                            '*' => ['key', 'label', 'icon'],
                        ],
                        'features' => [
                            '*' => ['id', 'name', 'value', 'icon', 'is_included'],
                        ],
                        'balances_grant' => [
                            '*' => ['category', 'title', 'amount', 'formatted', 'icon'],
                        ],
                        'is_current',
                    ],
                ],
            ],
        ]);

        $response->assertJsonMissingPath('data.header');
        $response->assertJsonMissingPath('data.current_plan.balance_notice');
        $response->assertJsonMissingPath('data.current_plan.action_button');
        $response->assertJsonMissingPath('data.available_packages.0.action_button');
        $response->assertJsonMissingPath('data.available_packages.0.view_details');
        $response->assertJsonPath('data.current_plan.name', 'The Free Bird');
        $response->assertJsonPath('data.current_plan.status', 'active');
        $response->assertJsonPath('data.current_plan.balances.0.amount', 40);
        $response->assertJsonPath('data.current_plan.balances.0.formatted', '$40.00');
        $response->assertJsonPath('data.current_plan.balances.1.amount', 25);
        $response->assertJsonPath('data.current_plan.balances.1.formatted', '$25.00');

        // Check available subscription packages (5 active subscriptions)
        $response->assertJsonCount(5, 'data.available_packages');
        $response->assertJsonPath('data.available_packages.0.name', 'The Smart Starter');
        $response->assertJsonPath('data.available_packages.0.is_current', false);

        $response->assertJsonPath('data.available_packages.1.name', 'The Free Bird');
        $response->assertJsonPath('data.available_packages.1.is_current', true);

        $response->assertJsonPath('data.available_packages.2.name', 'The Happy Committer');
        $response->assertJsonPath('data.available_packages.2.is_current', false);
    }

    public function test_filtering_packages_by_module(): void
    {
        $this->actingAsMember();

        // Filter by Flexi Desk slug
        $response = $this->getJson('/api/v1/member/packages?module=flexi_desk');
        $response->assertOk();
        $response->assertJsonCount(3, 'data.available_packages');
        $response->assertJsonPath('data.available_packages.0.name', 'The Smart Starter');
        $response->assertJsonPath('data.available_packages.1.name', 'The Free Bird');
        $response->assertJsonPath('data.available_packages.2.name', 'The Happy Committer');

        // Filter by Dedicated Desk slug
        $responseDedicated = $this->getJson('/api/v1/member/packages?module=dedicated_desk');
        $responseDedicated->assertOk();
        $responseDedicated->assertJsonCount(2, 'data.available_packages');
        $responseDedicated->assertJsonPath('data.available_packages.0.name', 'The Dedicated');
        $responseDedicated->assertJsonPath('data.available_packages.1.name', 'The Dedicated +');
    }

    public function test_member_without_active_membership_returns_null_current_plan(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/packages');

        $response->assertOk();
        $response->assertJsonPath('data.current_plan', null);
        $response->assertJsonCount(5, 'data.available_packages');
        $response->assertJsonPath('data.available_packages.0.is_current', false);
        $response->assertJsonPath('data.available_packages.1.is_current', false);
        $response->assertJsonPath('data.available_packages.2.is_current', false);
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
}
