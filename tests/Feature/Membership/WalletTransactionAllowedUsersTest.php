<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/decisions/wallet-points-categorization.md /
 * docs/decisions/phase-3-membership-plan-wallet-mechanics.md
 * ("Company-admin allocation is a reallocation, not new money") — a company
 * admin moves an amount from the company wallet's general balance into a
 * categorized/restricted grant for specific employees. Net wallet balance
 * is unchanged; only the category/restriction tag changes, and one
 * employee's restricted grant must never leak into another's.
 */
class WalletTransactionAllowedUsersTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->wallets = new WalletService;
    }

    /**
     * @return array{company: Company, wallet: Wallet, admin: User, member: User}
     */
    private function makeCompanyWithAdminAndMember(string $generalBalance = '100.00'): array
    {
        $company = Company::factory()->create();
        $wallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $this->wallets->creditGeneral($wallet, $generalBalance, WalletTransactionSource::TopUp);

        $admin = User::factory()->create();
        $admin->assignRole('member');
        $company->members()->attach($admin->id, ['is_admin' => true]);

        $member = User::factory()->create();
        $member->assignRole('member');
        $company->members()->attach($member->id, ['is_admin' => false]);

        return compact('company', 'wallet', 'admin', 'member');
    }

    private function rawGeneralBalance(Wallet $wallet): string
    {
        $total = '0.00';

        foreach (WalletTransaction::where('wallet_id', $wallet->id)->where('category', WalletTransactionCategory::General)->get() as $transaction) {
            $total = bcadd($total, (string) $transaction->amount, 2);
        }

        return $total;
    }

    public function test_a_company_admin_can_allocate_cafe_credit_restricted_to_a_member(): void
    {
        ['company' => $company, 'wallet' => $wallet, 'admin' => $admin, 'member' => $member] = $this->makeCompanyWithAdminAndMember('100.00');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', 'cafe');
        $response->assertJsonPath('data.amount', '20.00');
        $response->assertJsonPath('data.allowed_user_ids', [$member->id]);

        $this->assertSame('80.00', $this->rawGeneralBalance($wallet->fresh()));

        $cafeTransaction = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('category', WalletTransactionCategory::Cafe)
            ->where('amount', '>', 0)
            ->first();

        $this->assertNotNull($cafeTransaction);
        $this->assertSame('20.00', (string) $cafeTransaction->amount);
        $this->assertSame([$member->id], $cafeTransaction->allowedUsers->pluck('id')->all());
    }

    public function test_a_plain_member_cannot_allocate_wallet_credit(): void
    {
        ['company' => $company, 'member' => $member] = $this->makeCompanyWithAdminAndMember('100.00');

        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertForbidden();
    }

    public function test_allocating_more_than_the_available_general_balance_fails_with_no_partial_state(): void
    {
        ['company' => $company, 'admin' => $admin, 'member' => $member] = $this->makeCompanyWithAdminAndMember('10.00');

        $countBefore = WalletTransaction::count();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Insufficient general balance to allocate this amount.']);

        $this->assertSame($countBefore, WalletTransaction::count());
    }

    public function test_space_specific_category_without_a_restricted_space_id_is_rejected(): void
    {
        ['company' => $company, 'admin' => $admin, 'member' => $member] = $this->makeCompanyWithAdminAndMember('100.00');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'space_specific',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('restricted_space_id');
    }

    public function test_general_category_is_rejected_since_an_allocation_must_be_categorized(): void
    {
        ['company' => $company, 'admin' => $admin, 'member' => $member] = $this->makeCompanyWithAdminAndMember('100.00');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'general',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('category');
    }

    public function test_a_user_id_outside_the_company_is_rejected(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->makeCompanyWithAdminAndMember('100.00');
        $outsider = User::factory()->create();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$outsider->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_ids');
    }

    /**
     * Regression: a duplicated id in `user_ids` used to reach
     * `WalletTransaction::allowedUsers()->attach()` unfiltered and collide
     * with the pivot's unique `(wallet_transaction_id, user_id)` index,
     * surfacing as a raw 500 (`UniqueConstraintViolationException`) instead
     * of a clean validation error.
     */
    public function test_a_duplicated_user_id_is_a_validation_error_not_a_500(): void
    {
        ['company' => $company, 'admin' => $admin, 'member' => $member] = $this->makeCompanyWithAdminAndMember('100.00');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id, $member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_ids.1');
    }

    public function test_one_employees_allocation_spend_does_not_touch_another_employees_allocation(): void
    {
        ['company' => $company, 'wallet' => $wallet, 'admin' => $admin] = $this->makeCompanyWithAdminAndMember('100.00');

        $employeeA = User::factory()->create();
        $employeeA->assignRole('member');
        $company->members()->attach($employeeA->id, ['is_admin' => false]);

        $employeeB = User::factory()->create();
        $employeeB->assignRole('member');
        $company->members()->attach($employeeB->id, ['is_admin' => false]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '15.00',
            'user_ids' => [$employeeA->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ])->assertCreated();

        $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '25.00',
            'user_ids' => [$employeeB->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ])->assertCreated();

        // A spends an amount fully covered by A's own 15.00 allocation.
        $this->wallets->debit($wallet->fresh(), $employeeA, WalletTransactionCategory::Cafe, '15.00');

        $bOptions = $this->wallets->spendOptions($employeeB, WalletTransactionCategory::Cafe);
        $this->assertCount(1, $bOptions);
        $this->assertSame('25.00', $bOptions[0]['category_balance']);
        $this->assertSame('25.00', $bOptions[0]['usable_balance']);

        $aOptions = $this->wallets->spendOptions($employeeA, WalletTransactionCategory::Cafe);
        $this->assertSame('0.00', $aOptions[0]['category_balance']);
    }
}
