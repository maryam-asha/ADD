<?php
// tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php

namespace Tests\Guards;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unit 1 design (2026-08-09): `preferred_currency` is display-only and
 * must never influence pricing, transactions, or wallet logic. This
 * proves it rather than trusting the prose rule — the currency-preference
 * endpoint only ever writes to the `users` table, and this asserts a
 * plan's pricing columns are byte-for-byte unchanged after it runs.
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
        $member = User::factory()->create(['preferred_currency' => 'USD']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $planBefore = $plan->only(['pricing_currency', 'price']);

        $this->patchJson('/api/v1/member/preferences/currency', ['preferred_currency' => 'SYP'])
            ->assertOk();

        $plan->refresh();

        $this->assertSame($planBefore, $plan->only(['pricing_currency', 'price']));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'pricing_currency' => 'USD', 'price' => '10.00']);
    }
}
