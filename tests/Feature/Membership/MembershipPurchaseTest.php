<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/architecture/2026-08-08-backend-build-plan.md ("Phase 3 — Membership")
 * — buying a plan debits the buyer's (or their company's) wallet general
 * balance and creates the Membership row, all-or-nothing in one transaction.
 */
class MembershipPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->wallets = new WalletService;
    }

    private function makeFundedMember(string $generalBalance = '500.00'): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $this->wallets->creditGeneral($wallet, $generalBalance, WalletTransactionSource::TopUp);

        return $member;
    }

    /**
     * @return array{company: Company, wallet: Wallet, admin: User, member: User}
     */
    private function makeCompanyWithAdminAndMember(string $generalBalance = '500.00'): array
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

    public function test_a_funded_member_can_buy_a_subscription_plan_for_themselves(): void
    {
        $member = $this->makeFundedMember('500.00');
        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price' => '120.00',
            'duration_days' => 30,
            'included_hours' => '40.00',
        ]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.owner_type', 'user');
        $response->assertJsonPath('data.owner_id', $member->id);
        $response->assertJsonPath('data.plan.id', $plan->id);

        $this->assertSame('380.00', $this->rawGeneralBalance($wallet->fresh()));

        $membership = Membership::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame($plan->id, $membership->plan_id);
        $this->assertSame(
            $membership->current_period_start->copy()->addDays(30)->toDateTimeString(),
            $membership->current_period_end->toDateTimeString()
        );

        $hoursCredit = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('category', WalletTransactionCategory::SpaceSpecific)
            ->where('amount', '>', 0)
            ->first();

        $this->assertNotNull($hoursCredit);
        $this->assertSame('40.00', (string) $hoursCredit->amount);
        $this->assertSame(
            $membership->current_period_end->toDateTimeString(),
            $hoursCredit->expires_at->toDateTimeString()
        );
    }

    /**
     * docs/decisions/currency-header-conversion-scope.md: `PlanResource`'s
     * always-on conversion reaches the nested `data.plan` on
     * `MembershipResource` too, not just direct plan-listing responses —
     * the `currency` header (or a differing stored preference) must produce
     * `converted_amount`/`converted_currency` here as well.
     */
    public function test_the_purchase_response_includes_a_converted_amount_on_the_nested_plan(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '14700.0000', 'effective_from' => now()->subDay()]);

        $member = $this->makeFundedMember('500.00');

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price' => '10.00',
            'pricing_currency' => 'USD',
            'duration_days' => 30,
            'included_hours' => '0.00',
        ]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('currency', 'SYP')->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.plan.converted_amount', '147000.00');
        $response->assertJsonPath('data.plan.converted_currency', 'SYP');
    }

    public function test_insufficient_balance_fails_with_no_partial_state(): void
    {
        $member = $this->makeFundedMember('10.00');

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price' => '120.00',
            'duration_days' => 30,
            'included_hours' => '40.00',
        ]);

        $membershipCountBefore = Membership::count();
        $transactionCountBefore = WalletTransaction::count();

        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(422);

        $this->assertSame($membershipCountBefore, Membership::count());
        $this->assertSame($transactionCountBefore, WalletTransaction::count());
    }

    public function test_a_one_time_package_plan_cannot_be_purchased_as_a_membership(): void
    {
        $member = $this->makeFundedMember('500.00');

        $plan = Plan::factory()->create([
            'is_subscription' => false,
            'is_active' => true,
            'price' => '50.00',
        ]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('plan_id');

        $this->assertSame(0, Membership::count());
    }

    public function test_a_company_admin_can_buy_a_plan_on_behalf_of_their_company(): void
    {
        ['company' => $company, 'wallet' => $companyWallet, 'admin' => $admin] = $this->makeCompanyWithAdminAndMember('500.00');

        // The admin also has their own personal wallet, which must stay untouched.
        $personalWallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $admin->id]);
        $this->wallets->creditGeneral($personalWallet, '30.00', WalletTransactionSource::TopUp);

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price' => '120.00',
            'duration_days' => 30,
            'included_hours' => '0.00',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'company_id' => $company->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.owner_type', 'company');
        $response->assertJsonPath('data.owner_id', $company->id);

        $this->assertSame('380.00', $this->rawGeneralBalance($companyWallet->fresh()));
        $this->assertSame('30.00', $this->rawGeneralBalance($personalWallet->fresh()));

        $membership = Membership::where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame($plan->id, $membership->plan_id);
    }

    public function test_a_plain_company_member_cannot_buy_a_plan_on_behalf_of_the_company(): void
    {
        ['company' => $company, 'member' => $member] = $this->makeCompanyWithAdminAndMember('500.00');

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price' => '120.00',
            'duration_days' => 30,
        ]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'company_id' => $company->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Membership::count());
    }
}
