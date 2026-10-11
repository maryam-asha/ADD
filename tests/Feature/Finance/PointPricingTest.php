<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PointPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
    }

    public function test_a_plan_reports_its_point_price_and_usd_equivalent(): void
    {
        Plan::factory()->create(['price_points' => 500, 'is_active' => true]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonPath('data.0.price_points', 500)
            ->assertJsonPath('data.0.price_usd', '50')
            ->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_a_private_plan_is_absent_from_the_public_plan_list(): void
    {
        $public = Plan::factory()->create(['price_points' => 500]);
        Plan::factory()->private()->create(['price_points' => 900]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($public->id, $response->json('data.0.id'));
    }

    public function test_the_currency_header_changes_display_but_not_the_stored_points(): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);
        Plan::factory()->create(['price_points' => 500, 'is_active' => true]);

        $response = $this->withHeader('currency', 'SYP')->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonPath('data.0.price_points', 500)
            ->assertJsonPath('data.0.currency', 'SYP');

        $this->assertNotSame('50', $response->json('data.0.price_display'));
    }

    public function test_changing_the_rate_revalues_the_display_without_touching_the_row(): void
    {
        $plan = Plan::factory()->create(['price_points' => 500, 'is_active' => true]);

        app(SettingService::class)->set('point.rate_usd', '0.12', SettingValueType::String);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.0.price_usd', '60')
            ->assertJsonPath('data.0.price_points', 500);

        $this->assertSame(500, $plan->fresh()->price_points);
    }

    public function test_a_fractional_point_price_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['dashboard']);

        $this->postJson('/api/v1/admin/plans', [
            'name' => ['en' => 'Test', 'ar' => 'تجربة'],
            'price_points' => 12.5,
            'duration_days' => 30,
        ])->assertJsonValidationErrors('price_points');
    }
}
