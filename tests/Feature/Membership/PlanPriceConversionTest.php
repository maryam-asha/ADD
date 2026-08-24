<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanPriceConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_converted_amount_is_added_when_the_users_preferred_currency_differs(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        // converted_amount is formatted as a decimal string (number_format),
        // matching every other money field on this resource (price,
        // pricing_currency, overage_rate) — not a raw JSON number, which
        // would silently drop the fractional part for whole-number amounts.
        // 10 / 0.0000680272 ≈ 147000.02, not a clean 147000.00, because the
        // stored rate is 1/14700 rounded to 10 decimal places, not the exact
        // repeating fraction — expected of a real rate.
        $response->assertJsonPath('data.converted_amount', '147000.02');
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    public function test_no_converted_amount_when_preferred_currency_matches_pricing_currency(): void
    {
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'USD']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('converted_amount', $response->json('data'));
    }

    public function test_no_converted_amount_when_no_exchange_rate_exists(): void
    {
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('converted_amount', $response->json('data'));
    }

    /**
     * Replaces the old `preferred_currency: null` test — that state no
     * longer exists after the USD-default migration (the column is
     * NOT NULL). A member created with no override now genuinely has
     * `preferred_currency === 'USD'` (UserFactory sets it explicitly,
     * matching the DB default), so this proves the *default*, not a
     * missing value.
     */
    public function test_a_new_member_with_no_currency_override_gets_usd_by_default(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '14700.00', 'pricing_currency' => 'SYP']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.converted_amount', '1.00');
        $response->assertJsonPath('data.converted_currency', 'USD');
    }

    public function test_the_currency_header_overrides_the_stored_preference(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'USD']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('currency', 'SYP')->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.converted_amount', '147000.02');
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    public function test_the_currency_header_works_for_anonymous_requests_too(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        Plan::factory()->create(['price' => '14700.00', 'pricing_currency' => 'SYP', 'is_active' => true]);

        $response = $this->withHeader('currency', 'USD')->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonPath('data.0.converted_amount', '1.00');
        $response->assertJsonPath('data.0.converted_currency', 'USD');
    }

    public function test_an_invalid_currency_header_value_falls_back_to_the_stored_preference(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('currency', 'EUR')->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    /**
     * Resolving `$request->user('sanctum')` inside PlanResource fires
     * Sanctum's TokenAuthenticated event, which
     * EnsureAuthenticatedUserIsActive listens to and aborts (403) for a
     * suspended/blocked account. The public plans route has no
     * auth:sanctum middleware and was always a 200 regardless of token
     * state before conversion was added — a leftover token from a
     * since-blocked account must not turn it into an occasional 403 or
     * silently use that account's own preference.
     *
     * The blocked member's own preference is deliberately set to `SYP`
     * (matching the plan's pricing currency, which would mean NO
     * conversion if their preference were somehow honored) so that seeing
     * a converted USD amount here can only mean the request was correctly
     * treated as anonymous and given the USD default — not that their
     * stored preference leaked through despite the failed auth.
     */
    public function test_a_blocked_members_stale_token_is_treated_as_anonymous_and_gets_the_usd_default(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        Plan::factory()->create(['price' => '14700.00', 'pricing_currency' => 'SYP', 'is_active' => true]);
        $member = User::factory()->create(['status' => 'active', 'preferred_currency' => 'SYP']);
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $member->update(['status' => 'blocked']);
        Auth::forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonPath('data.0.converted_amount', '1.00');
        $response->assertJsonPath('data.0.converted_currency', 'USD');
    }
}
