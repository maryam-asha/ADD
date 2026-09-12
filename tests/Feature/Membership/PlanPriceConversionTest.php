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

/**
 * Points replaced the old `price`/`pricing_currency` pair, so a plan is
 * always denominated in points (USD-equivalent at the point rate) rather
 * than in a per-plan currency. `price_display`/`currency` supersede the
 * `converted_amount`/`converted_currency` pair these tests used to read;
 * the behaviour each one pins — which currency is resolved, and that the
 * display follows it — is unchanged.
 */
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
        $plan = Plan::factory()->create(['price_points' => 100]);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        // price_display is formatted as a decimal string (number_format) —
        // not a raw JSON number, which would silently drop the fractional
        // part for whole-number amounts. 10 / 0.0000680272 ≈ 147000.02, not
        // a clean 147000.00, because the stored rate is 1/14700 rounded to
        // 10 decimal places, not the exact repeating fraction — expected of
        // a real rate.
        $response->assertJsonPath('data.price_display', '147000.02');
        $response->assertJsonPath('data.currency', 'SYP');
    }

    public function test_no_converted_amount_when_preferred_currency_matches_pricing_currency(): void
    {
        $plan = Plan::factory()->create(['price_points' => 100]);
        $admin = User::factory()->create(['preferred_currency' => 'USD']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.currency', 'USD');
        // 100 points at the 0.10 point rate is $10.00. Pinned as a literal
        // rather than compared against price_usd from the same response —
        // a self-comparison holds whatever value both fields carry, so it
        // could not tell a correct price from a 10x-inflated one.
        $response->assertJsonPath('data.price_usd', '10.00');
        $response->assertJsonPath('data.price_display', '10.00');
    }

    public function test_no_converted_amount_when_no_exchange_rate_exists(): void
    {
        $plan = Plan::factory()->create(['price_points' => 100]);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        // No SYP rate exists, so pointsToCurrency() returns null and the
        // display falls back to the unconverted USD value — 100 points at
        // the 0.10 point rate, i.e. $10.00. Pinned as a literal rather than
        // compared against price_usd from the same response, which would
        // hold for any value the two fields happened to share.
        $response->assertJsonPath('data.price_usd', '10.00');
        $response->assertJsonPath('data.price_display', '10.00');
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
        $plan = Plan::factory()->create(['price_points' => 100]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.price_display', '10.00');
        $response->assertJsonPath('data.currency', 'USD');
    }

    public function test_the_currency_header_overrides_the_stored_preference(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price_points' => 100]);
        $admin = User::factory()->create(['preferred_currency' => 'USD']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('currency', 'SYP')->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.price_display', '147000.02');
        $response->assertJsonPath('data.currency', 'SYP');
    }

    public function test_the_currency_header_works_for_anonymous_requests_too(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        Plan::factory()->create(['price_points' => 100, 'is_active' => true]);

        $response = $this->withHeader('currency', 'USD')->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonPath('data.0.price_display', '10.00');
        $response->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_an_invalid_currency_header_value_falls_back_to_the_stored_preference(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price_points' => 100]);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('currency', 'EUR')->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.currency', 'SYP');
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
     * (which has an exchange rate here, so honoring it would produce a
     * visibly different SYP display) so that seeing the plain USD default
     * can only mean the request was correctly treated as anonymous — not
     * that their stored preference leaked through despite the failed auth.
     */
    public function test_a_blocked_members_stale_token_is_treated_as_anonymous_and_gets_the_usd_default(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        Plan::factory()->create(['price_points' => 100, 'is_active' => true]);
        $member = User::factory()->create(['status' => 'active', 'preferred_currency' => 'SYP']);
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $member->update(['status' => 'blocked']);
        Auth::forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonPath('data.0.price_display', '10.00');
        $response->assertJsonPath('data.0.currency', 'USD');
    }
}
