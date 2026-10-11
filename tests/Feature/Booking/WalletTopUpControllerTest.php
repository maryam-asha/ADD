<?php

namespace Tests\Feature\Booking;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\TopUpMinimum;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
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
            'points' => 250,
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
            'points' => 500,
            'amount' => '50.00',
            'payment_method' => 'cash',
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
            'points' => 100,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'company_id' => $company->id,
            'points' => 100,
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
            'points' => 100,
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
            'points' => 10,
            'amount' => '0',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_an_inactive_company(): void
    {
        $this->actingAsOperations();
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);
        Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $company->update(['status' => CompanyStatus::Inactive]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'company_id' => $company->id,
            'points' => 500,
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
            'points' => 100,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    /** The pound has no piasters in use: an SYP amount is whole ("5000.00" is). */
    public function test_a_syrian_amount_with_piasters_is_refused(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000666667',
            'source' => ExchangeRateSource::Manual,
            'effective_from' => now()->subMinute(),
        ]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            // What 150,000.50 SYP would buy, so `amount` is the only thing wrong.
            'points' => 100,
            'amount' => '150000.50',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'must be a whole number']);
    }

    public function test_operations_can_top_up_in_foreign_currency_with_conversion(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'is_base' => true, 'is_active' => true]
        );
        Currency::firstOrCreate(
            ['code' => 'SYP'],
            ['name' => 'Syrian Pound', 'symbol' => 'LS', 'is_base' => false, 'is_active' => true]
        );

        ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000666667',
            'source' => ExchangeRateSource::Manual,
            'effective_from' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 100,
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
            'points' => 340,
            'amount' => '500000',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ]);

        // 500,000 SYP -> $34.0136 exactly -> /0.10 -> 340.136 -> floor -> 340
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
            'points' => 100,
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
            'points' => 250,
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

    private function memberWithWallet(): User
    {
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    public function test_a_top_up_below_the_minimum_is_rejected(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 9,
            'amount' => '0.90',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors(['points' => __('api.wallet.min_points', ['min' => 10])]);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_the_minimum_follows_the_setting(): void
    {
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 25, SettingValueType::Int);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 20,
            'amount' => '2.00',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('points');

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 25,
            'amount' => '2.50',
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    /**
     * Too many points would credit value nobody paid for; too few would
     * credit less than the operator promised the member. Both are refused.
     */
    public function test_points_that_do_not_match_the_amount_are_rejected_and_nothing_is_written(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        foreach ([249, 251] as $points) {
            $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
                'user_id' => $member->id,
                'points' => $points,
                'amount' => '25.00',
                'payment_method' => 'cash',
            ])->assertJsonValidationErrors(['points' => __('api.wallet.quote_stale')]);
        }

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_the_cents_rounding_that_over_credited_is_gone(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272']);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        // $34.09523264 exactly. The old path rounded it to 34.10 and credited 341.
        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 341,
            'amount' => '501200',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('points');

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 340,
            'amount' => '501200',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.points_credited', 340);
    }

    public function test_a_rate_change_after_the_dashboard_quoted_is_rejected(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272']);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        // The dashboard quoted 500,000 SYP as 340 points; then the rate moved
        // so the same cash now buys 350.
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000700000',
            'effective_from' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 340,
            'amount' => '500000',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors(['points' => __('api.wallet.quote_stale')]);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    /**
     * Spec §6: a rate update too small to move the floor must not strand the
     * dashboard's quote. 500,000 SYP is 340.136 points at the first rate and
     * 340.136...+ at the second — still 340 — so the same request succeeds.
     */
    public function test_a_rate_change_too_small_to_move_the_floor_still_succeeds(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272']);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680273',
            'effective_from' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 340,
            'amount' => '500000',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.points_credited', 340);
    }

    public function test_an_amount_with_more_than_two_decimals_is_rejected(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 100,
            'amount' => '10.005',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('amount');

        // The plain-decimal regex is what keeps exponent notation away from
        // bcmath, which would throw a ValueError (a 500) on a string like this.
        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 10000,
            'amount' => '1e3',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('amount');
    }

    public function test_the_preview_endpoint_no_longer_exists(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups/preview', [])->assertNotFound();
    }

    public function test_the_balance_carries_what_the_dashboard_needs_to_quote_a_top_up(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 25, SettingValueType::Int);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0068027211']);
        Currency::find('SYP')->update(['cash_step' => '10.00']);
        // Active but no rate: cannot be quoted, so it must not be offered.
        Currency::factory()->create(['code' => 'GBP', 'is_base' => false, 'is_active' => true]);
        // Has a rate but is inactive: not offered either.
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_active' => false]);
        ExchangeRate::factory()->create(['currency_code' => 'EUR', 'rate_to_base' => '1.0800000000']);

        $response = $this->getJson("/api/v1/admin/reception/wallet-balance?user_id={$member->id}")->assertOk();

        $response->assertJsonPath('data.top_up.point_rate_usd', '0.1000')
            ->assertJsonPath('data.top_up.min_points', 25);

        $currencies = collect($response->json('data.top_up.currencies'))->keyBy('code');
        $this->assertEqualsCanonicalizing(['SYP', 'USD'], $currencies->keys()->all());
        $this->assertSame('0.0068027211', $currencies['SYP']['rate_to_base']);
        $this->assertSame('10.00', $currencies['SYP']['cash_step']);
        // The pound has no decimals (2026_10_04_100200_syrian_pound_has_no_decimals).
        $this->assertSame(0, $currencies['SYP']['decimal_places']);
        $this->assertSame('1', $currencies['USD']['rate_to_base']);
        $this->assertSame('1.00', $currencies['USD']['cash_step']);
    }

    /**
     * The dashboard's exact arithmetic parses only plain decimals, so the
     * server decides the published form whatever an admin typed.
     */
    public function test_the_balance_publishes_the_point_rate_as_a_plain_four_decimal_string(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        foreach (['.05' => '0.0500', '5.' => '5.0000', '+0.10' => '0.1000', '0.1' => '0.1000'] as $stored => $published) {
            app(SettingService::class)->set('point.rate_usd', $stored, SettingValueType::String);

            $this->getJson("/api/v1/admin/reception/wallet-balance?user_id={$member->id}")
                ->assertOk()
                ->assertJsonPath('data.top_up.point_rate_usd', $published);
        }
    }
}
