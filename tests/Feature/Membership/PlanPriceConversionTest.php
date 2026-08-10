<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        // assertJsonPath uses assertSame internally, which is too strict here:
        // json_encode(147000.0) drops the zero fraction (no
        // JSON_PRESERVE_ZERO_FRACTION flag set anywhere in this app), so the
        // decoded value comes back as PHP int 147000, not float 147000.0.
        // assertEquals checks the numeric value without requiring the exact
        // int/float type.
        $this->assertEquals(147000.0, $response->json('data.converted_amount'));
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

    public function test_no_converted_amount_when_preferred_currency_is_not_set(): void
    {
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => null]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('converted_amount', $response->json('data'));
    }
}
