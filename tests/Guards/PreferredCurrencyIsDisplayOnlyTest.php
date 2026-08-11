<?php

namespace Tests\Guards;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unit 1 design (2026-08-09): `preferred_currency` is display-only and
 * must never influence pricing, transactions, or wallet logic. This
 * proves it rather than trusting the prose rule — the currency-preference
 * endpoint only ever writes to the `users` table, and this asserts a
 * plan's pricing columns, a wallet transaction's amount, and a space's
 * pricing columns are all byte-for-byte unchanged after it runs.
 */
class PreferredCurrencyIsDisplayOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_changing_preferred_currency_does_not_mutate_pricing_records(): void
    {
        $plan = Plan::factory()->create(['pricing_currency' => 'USD', 'price' => '10.00']);
        $wallet = Wallet::factory()->create();
        $transaction = WalletTransaction::factory()->create(['wallet_id' => $wallet->id, 'amount' => '25.00']);
        $space = Space::factory()->create(['hourly_rate' => '15.00', 'pricing_currency' => 'USD']);
        $member = User::factory()->create(['preferred_currency' => 'USD']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $planBefore = $plan->only(['pricing_currency', 'price']);
        $transactionBefore = $transaction->only(['amount']);
        $spaceBefore = $space->only(['hourly_rate', 'pricing_currency']);

        $this->patchJson('/api/v1/member/preferences/currency', ['preferred_currency' => 'SYP'])
            ->assertOk();

        $plan->refresh();
        $transaction->refresh();
        $space->refresh();

        $this->assertSame($planBefore, $plan->only(['pricing_currency', 'price']));
        $this->assertSame($transactionBefore, $transaction->only(['amount']));
        $this->assertSame($spaceBefore, $space->only(['hourly_rate', 'pricing_currency']));

        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'pricing_currency' => 'USD', 'price' => '10.00']);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id, 'amount' => '25.00']);
        $this->assertDatabaseHas('spaces', ['id' => $space->id, 'hourly_rate' => '15.00', 'pricing_currency' => 'USD']);
    }

    /**
     * The `currency` request header (2026-08-11) is a second, unauthenticated
     * input channel that also reaches `PlanResource` — it must be just as
     * display-only as the stored preference above. Uses the public plan
     * listing since it's the simplest route that returns `PlanResource` with
     * no auth required at all.
     */
    public function test_the_currency_header_does_not_mutate_pricing_records(): void
    {
        $plan = Plan::factory()->create(['pricing_currency' => 'USD', 'price' => '10.00', 'is_active' => true]);

        $planBefore = $plan->only(['pricing_currency', 'price']);

        $this->withHeader('currency', 'SYP')
            ->getJson('/api/v1/plans')
            ->assertOk();

        $plan->refresh();

        $this->assertSame($planBefore, $plan->only(['pricing_currency', 'price']));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'pricing_currency' => 'USD', 'price' => '10.00']);
    }
}
