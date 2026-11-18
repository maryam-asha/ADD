<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\WalletService;
use Tests\Feature\Providers\ProviderTestCase;

class FutureMembershipTest extends ProviderTestCase
{
    private function company(): Company
    {
        $branch = $this->branchWithSeats(10);
        $company = Company::factory()->create(['branch_id' => $branch->id]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        return $company;
    }

    private function planWithBalance(int $points = 100): Plan
    {
        $plan = Plan::factory()->create(['price_points' => 500, 'duration_days' => 30]);
        PlanBalance::create(['plan_id' => $plan->id, 'category' => WalletTransactionCategory::General, 'amount_points' => $points]);

        return $plan->fresh('balances');
    }

    private function balance(Company $company): int
    {
        $wallets = app(WalletService::class);

        return $wallets->generalBalance($wallets->walletFor(OwnerType::Company, $company->id));
    }

    public function test_a_membership_that_has_not_started_is_not_live(): void
    {
        $company = $this->company();
        $current = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $next = Membership::factory()->create([
            'owner_type' => OwnerType::Company, 'owner_id' => $company->id,
            'current_period_start' => now()->addDays(30),
            'current_period_end' => now()->addDays(60),
        ]);

        $this->assertFalse($next->isLive());
        $this->assertFalse(Membership::query()->live()->whereKey($next->id)->exists());
        $this->assertTrue(app(MembershipResolver::class)->activeForOwner(OwnerType::Company, $company->id)->is($current));

        $this->travel(31)->days();
        $this->assertTrue($next->fresh()->isLive());
        $this->assertTrue(app(MembershipResolver::class)->activeForOwner(OwnerType::Company, $company->id)->is($next));
    }

    public function test_a_future_activation_grants_its_balances_only_when_it_starts(): void
    {
        $company = $this->company();
        $plan = $this->planWithBalance(100);
        $activations = app(MembershipActivationService::class);

        $membership = $activations->activate(
            $plan, OwnerType::Company, $company->id,
            charge: false,
            startsAt: now()->addDays(5),
            attributes: ['auto_renew' => true],
        );

        $this->assertTrue($membership->auto_renew);
        $this->assertSame(now()->addDays(5)->timestamp, $membership->current_period_start->timestamp);
        $this->assertSame(now()->addDays(35)->timestamp, $membership->current_period_end->timestamp);
        $this->assertNull($membership->balances_granted_at);
        $this->assertSame(0, $this->balance($company));

        $this->assertFalse($activations->grantDueBalances($membership), 'Not started yet.');

        $this->travel(5)->days();
        $this->assertTrue($activations->grantDueBalances($membership->fresh()));
        $this->assertFalse($activations->grantDueBalances($membership->fresh()), 'Granted once.');
        $this->assertSame(100, $this->balance($company));
        $this->assertNotNull($membership->fresh()->balances_granted_at);
    }

    public function test_an_immediate_activation_grants_at_once_as_before(): void
    {
        $company = $this->company();

        $membership = app(MembershipActivationService::class)->activate($this->planWithBalance(100), OwnerType::Company, $company->id, charge: false);

        $this->assertNotNull($membership->balances_granted_at);
        $this->assertSame(100, $this->balance($company));
    }
}
