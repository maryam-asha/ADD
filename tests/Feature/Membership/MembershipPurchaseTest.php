<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
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
use Illuminate\Support\Facades\Cache;
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

    private function makeFundedMember(int $generalBalance = 5000): User
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
    private function makeCompanyWithAdminAndMember(int $generalBalance = 5000): array
    {
        $company = Company::factory()->create();
        // Seats for the company's subscription to hold, in its branch's
        // co-working pool (docs/decisions/coworking-seat-pool.md).
        $this->coSpaceIn($company->branch_id, 10);
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

    private function coSpaceIn(int $branchId, int $capacity): Space
    {
        return Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branchId])->id,
            'capacity' => $capacity,
        ]);
    }

    /**
     * A subscription holds a seat for its whole period, so it is not sold
     * when its branch's co-working seats are all held — and nothing is
     * charged for the attempt.
     */
    public function test_a_plan_is_not_sold_when_its_branch_has_no_seat_left(): void
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        $this->coSpaceIn($branch->id, 1);
        Membership::factory()->create(['branch_id' => $branch->id]);
        $member = $this->makeFundedMember(5000);
        $plan = Plan::factory()->create(['price_points' => 1200, 'duration_days' => 30]);
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.membership.seat_pool_full', [], 'en'))
            ->assertJsonStructure(['message', 'first_shortfall_at']);

        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();
        $this->assertSame(5000, $this->rawGeneralBalance($wallet));
        $this->assertSame(0, Membership::where('owner_id', $member->id)->where('owner_type', OwnerType::User)->count());
    }

    private function rawGeneralBalance(Wallet $wallet): int
    {
        $total = 0;

        foreach (WalletTransaction::where('wallet_id', $wallet->id)->where('category', WalletTransactionCategory::General)->get() as $transaction) {
            $total += (int) $transaction->amount;
        }

        return $total;
    }

    public function test_a_funded_member_can_buy_a_subscription_plan_for_themselves(): void
    {
        $member = $this->makeFundedMember(5000);
        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price_points' => 1200,
            'duration_days' => 30,
        ]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.owner_type', 'user');
        $response->assertJsonPath('data.owner_id', $member->id);
        $response->assertJsonPath('data.plan.id', $plan->id);

        $this->assertSame(3800, $this->rawGeneralBalance($wallet->fresh()));

        $membership = Membership::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame($plan->id, $membership->plan_id);
        $this->assertSame(
            $membership->current_period_start->copy()->addDays(30)->toDateTimeString(),
            $membership->current_period_end->toDateTimeString()
        );

        // A plan with no `plan_balances` rows grants nothing. The old
        // `included_hours` fallback that used to credit a SpaceSpecific
        // allowance here is gone — it counted hours and spent points 1:1.
        // Grants now come only from balances, or from plan_resource_grants
        // for natural-unit quotas.
        $this->assertSame(
            0,
            WalletTransaction::where('wallet_id', $wallet->id)
                ->where('category', WalletTransactionCategory::SpaceSpecific)
                ->where('amount', '>', 0)
                ->count()
        );
    }

    /**
     * docs/decisions/currency-header-conversion-scope.md: `PlanResource`'s
     * always-on conversion reaches the nested `data.plan` on
     * `MembershipResource` too, not just direct plan-listing responses —
     * the `currency` header (or a differing stored preference) must produce
     * a converted `price_display`/`currency` here as well.
     */
    public function test_the_purchase_response_includes_a_converted_amount_on_the_nested_plan(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);

        $member = $this->makeFundedMember(5000);

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price_points' => 100,
            'duration_days' => 30,
        ]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('currency', 'SYP')->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.plan.price_display', '147000.02');
        $response->assertJsonPath('data.plan.currency', 'SYP');
        $response->assertJsonPath('data.plan.price_points', 100);
    }

    public function test_insufficient_balance_fails_with_no_partial_state(): void
    {
        $member = $this->makeFundedMember(100);

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price_points' => 1200,
            'duration_days' => 30,
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
        $member = $this->makeFundedMember(5000);

        $plan = Plan::factory()->create([
            'is_subscription' => false,
            'is_active' => true,
            'price_points' => 500,
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
        ['company' => $company, 'wallet' => $companyWallet, 'admin' => $admin] = $this->makeCompanyWithAdminAndMember(5000);

        // The admin also has their own personal wallet, which must stay untouched.
        $personalWallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $admin->id]);
        $this->wallets->creditGeneral($personalWallet, 300, WalletTransactionSource::TopUp);

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price_points' => 1200,
            'duration_days' => 30,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'company_id' => $company->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.owner_type', 'company');
        $response->assertJsonPath('data.owner_id', $company->id);

        $this->assertSame(3800, $this->rawGeneralBalance($companyWallet->fresh()));
        $this->assertSame(300, $this->rawGeneralBalance($personalWallet->fresh()));

        $membership = Membership::where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame($plan->id, $membership->plan_id);
    }

    public function test_a_plain_company_member_cannot_buy_a_plan_on_behalf_of_the_company(): void
    {
        ['company' => $company, 'member' => $member] = $this->makeCompanyWithAdminAndMember(5000);

        $plan = Plan::factory()->create([
            'is_subscription' => true,
            'is_active' => true,
            'price_points' => 1200,
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
