<?php

namespace Tests\Feature\Booking;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WalletTopUpControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_operations_can_top_up_a_members_wallet(): void
    {
        $operator = $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '25.00',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '25.00');

        $transaction = $wallet->fresh()->transactions()->latest('id')->first();
        $this->assertSame($operator->id, $transaction->performed_by_user_id);
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);

        $activity = Activity::where('description', 'wallet_top_up')->latest('id')->first();
        $this->assertEquals($operator->id, $activity->causer_id);
    }

    public function test_operations_can_top_up_a_companys_wallet(): void
    {
        $this->actingAsOperations();
        $company = Company::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'company_id' => $company->id,
            'amount' => '50.00',
            'payment_method' => 'syriatel',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.recipient_name', $company->legal_name);
        $response->assertJsonPath('data.previous_balance', '0.00');
        $response->assertJsonPath('data.new_balance', '50.00');
    }

    public function test_top_up_requires_exactly_one_target(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        $company = Company::factory()->create();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'company_id' => $company->id,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_an_invalid_payment_method(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.00',
            'payment_method' => 'visa',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_a_non_positive_amount(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '0',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_an_inactive_company(): void
    {
        $this->actingAsOperations();
        $company = Company::factory()->create(['status' => \App\Domain\Identity\Enums\CompanyStatus::Active]);
        Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $company->update(['status' => \App\Domain\Identity\Enums\CompanyStatus::Inactive]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'company_id' => $company->id,
            'amount' => '50.00',
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('company_id');
    }

    public function test_operations_can_query_wallet_balance_for_user_and_company(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create(['name' => 'Sara Al-Halabi']);
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $response = $this->getJson("/api/v1/admin/reception/wallet-balance?user_id={$member->id}");
        $response->assertOk();
        $response->assertJsonPath('data.owner_name', 'Sara Al-Halabi');
        $response->assertJsonPath('data.general_balance', '0.00');

        $company = Company::factory()->create(['legal_name' => 'Syria Tech']);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $responseC = $this->getJson("/api/v1/admin/reception/wallet-balance?company_id={$company->id}");
        $responseC->assertOk();
        $responseC->assertJsonPath('data.owner_name', 'Syria Tech');
        $responseC->assertJsonPath('data.general_balance', '0.00');
    }

    public function test_operations_can_list_users(): void
    {
        $this->actingAsOperations();
        User::factory()->create(['name' => 'Member User'])->assignRole('member');

        $response = $this->getJson('/api/v1/admin/users?role=member');
        $response->assertOk();
    }

    public function test_a_member_cannot_top_up_a_wallet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_operations_can_top_up_in_foreign_currency_with_conversion(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        \App\Domain\Finance\Models\Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'is_base' => true, 'is_active' => true]
        );
        \App\Domain\Finance\Models\Currency::firstOrCreate(
            ['code' => 'SYP'],
            ['name' => 'Syrian Pound', 'symbol' => 'LS', 'is_base' => false, 'is_active' => true]
        );

        \App\Domain\Finance\Models\ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000666667',
            'source' => \App\Domain\Finance\Enums\ExchangeRateSource::Manual,
            'effective_from' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '150000.00',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payment_amount', '150000.00');
        $response->assertJsonPath('data.payment_currency', 'SYP');
        $response->assertJsonPath('data.amount', '10.00');
        $response->assertJsonPath('data.currency', 'USD');
        $response->assertJsonPath('data.new_balance', '10.00');

        $transaction = $wallet->fresh()->transactions()->latest('id')->first();
        // The ledger is point-denominated: $10.00 credited is 100 points.
        $this->assertSame(100, (int) $transaction->amount);
        $this->assertEquals('SYP', $transaction->payment_currency);
        $this->assertEquals('150000.00', $transaction->payment_amount);
    }

    public function test_a_cash_top_up_credits_points_and_records_what_was_paid(): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $operator = User::factory()->create();
        $operator->assignRole('admin');
        Sanctum::actingAs($operator, ['dashboard']);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '500000',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ]);

        // 500,000 SYP -> $34.01 -> /0.10 -> 340.1 -> floor -> 340
        $response->assertCreated()
            ->assertJsonPath('data.points_credited', 340)
            ->assertJsonPath('data.payment_currency', 'SYP')
            ->assertJsonPath('data.point_rate_usd', '0.1000');

        $this->assertDatabaseHas('wallet_transactions', [
            'amount' => 340,
            'payment_currency' => 'SYP',
            'payment_amount' => '500000.00',
            'performed_by_user_id' => $operator->id,
        ]);
    }

    public function test_a_payment_too_small_to_buy_one_point_is_rejected(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $operator = User::factory()->create();
        $operator->assignRole('admin');
        Sanctum::actingAs($operator, ['dashboard']);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '0.05',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('amount');
    }

    /**
     * Entry condition 2: the audit trail must never claim a value the
     * ledger does not hold. $10.05 buys 100 whole points ($10.00) — the
     * spare $0.05 is not creditable. The receipt and the activity log must
     * both report the 100 points actually stored, while `payment_amount`
     * keeps the verbatim $10.05 that was handed over.
     */
    public function test_a_sub_point_remainder_is_never_reported_as_credited(): void
    {
        $operator = $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.05',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.points_credited', 100)
            ->assertJsonPath('data.new_balance_points', 100)
            // What was handed over is recorded verbatim...
            ->assertJsonPath('data.payment_amount', '10.05')
            // ...but every credited-value figure reports the 100 points
            // stored, not the untruncated 10.05 the old code echoed back.
            ->assertJsonPath('data.amount', '10.00')
            ->assertJsonPath('data.new_balance', '10.00');

        $transaction = $wallet->fresh()->transactions()->latest('id')->first();
        $this->assertSame(100, (int) $transaction->amount);

        $activity = Activity::where('description', 'wallet_top_up')->latest('id')->first();
        $this->assertSame(100, $activity->properties['points_credited']);
        $this->assertSame('10.05', $activity->properties['payment_amount']);
        // The pre-truncation base-currency figure must not appear at all.
        $this->assertArrayNotHasKey('amount', $activity->properties->toArray());
    }

    /**
     * The credit and its audit fields share one transaction, so a wallet
     * can never hold credited points whose payment method, operator and
     * rates are missing.
     */
    public function test_a_credited_top_up_always_carries_its_full_audit_trail(): void
    {
        $operator = $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '25.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $transaction = $wallet->fresh()->transactions()->latest('id')->first();

        $this->assertSame(250, (int) $transaction->amount);
        $this->assertSame($operator->id, $transaction->performed_by_user_id);
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);
        $this->assertSame('USD', $transaction->payment_currency);
        $this->assertSame('0.1000', (string) $transaction->point_rate_usd);
    }

    public function test_preview_reports_the_points_a_payment_would_buy_without_writing_anything(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups/preview', [
            'user_id' => $member->id,
            'amount' => '500000',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.points', 340)
            ->assertJsonPath('data.usd', '34.01')
            ->assertJsonPath('data.payment_currency', 'SYP')
            ->assertJsonPath('data.point_rate_usd', '0.10');

        // Preview is a pure read — nothing may land in the ledger.
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_preview_applies_the_same_floor_as_a_real_top_up(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups/preview', [
            'user_id' => $member->id,
            'amount' => '10.05',
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.points', 100);

        // And it refuses what a real top-up would refuse, so the dialog
        // cannot promise a credit the store endpoint would reject.
        $this->postJson('/api/v1/admin/reception/wallet-top-ups/preview', [
            'user_id' => $member->id,
            'amount' => '0.05',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('amount');
    }
}
