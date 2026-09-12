<?php

namespace Tests\Feature\Booking;

use App\Domain\Finance\Enums\PaymentMethod;
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
        $this->assertEquals('10.00', $transaction->amount);
        $this->assertEquals('SYP', $transaction->payment_currency);
        $this->assertEquals('150000.00', $transaction->payment_amount);
    }
}

