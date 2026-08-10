<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Read side of the hybrid wallet-routing decision
 * (docs/decisions/wallet-points-categorization.md, "Routing for a user with
 * both a personal wallet and a company membership"). No spend happens
 * through this endpoint — it only reports what's currently spendable and
 * where.
 */
class WalletHybridRoutingOptionsTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->wallets = new WalletService;
    }

    public function test_a_member_with_only_a_personal_balance_gets_exactly_one_option(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $this->wallets->creditGeneral($wallet, '25.00', WalletTransactionSource::TopUp);

        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/wallet/options?category=general');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.owner_type', 'user');
        $response->assertJsonPath('data.0.owner_label', 'Personal');
        $response->assertJsonPath('data.0.usable_balance', '25.00');
    }

    public function test_a_member_with_a_personal_and_a_company_balance_gets_both_options_labeled(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $this->wallets->creditGeneral($personalWallet, '25.00', WalletTransactionSource::TopUp);

        $company = Company::factory()->create();
        $company->members()->attach($member->id, ['door_access_enabled' => false]);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $this->wallets->creditGeneral($companyWallet, '40.00', WalletTransactionSource::TopUp);

        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/wallet/options?category=general');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $data = $response->json('data');
        $labels = array_column($data, 'owner_label');
        $ownerTypes = array_column($data, 'owner_type');

        $this->assertContains('Personal', $labels);
        $this->assertContains($company->legal_name, $labels);
        $this->assertContains('user', $ownerTypes);
        $this->assertContains('company', $ownerTypes);
    }

    public function test_a_category_with_zero_usable_balance_anywhere_returns_an_empty_array(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/wallet/options?category=cafe');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_an_invalid_category_is_rejected_with_a_validation_error(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/wallet/options?category=not_a_real_category');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('category');
    }
}
