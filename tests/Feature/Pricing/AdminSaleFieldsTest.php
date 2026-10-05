<?php

namespace Tests\Feature\Pricing;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin puts a space or a package on sale through the forms it is
 * already edited with: a sale price and the window it runs, all three
 * together or none, typed in local time, and below the normal price
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class AdminSaleFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Damascus')->utc());

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    /** @return array<string, mixed> */
    private function spacePayload(array $overrides = []): array
    {
        return array_merge([
            'building_id' => Building::factory()->create()->id,
            'name' => ['ar' => 'غرفة', 'en' => 'Room'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
            'hourly_rate_points' => 100,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function planPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Flex Desk', 'ar' => 'مكتب مرن'],
            'is_subscription' => true,
            'price_points' => 120,
            'duration_days' => 30,
        ], $overrides);
    }

    public function test_a_space_is_put_on_sale_in_local_time(): void
    {
        $response = $this->postJson('/api/v1/admin/spaces', $this->spacePayload([
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => '2026-10-05 09:00',
            'sale_ends_at' => '2026-10-12 09:00',
        ]))->assertCreated();

        $response->assertJsonPath('data.sale_hourly_rate_points', 80);
        $space = Space::findOrFail($response->json('data.id'));
        $this->assertSame('2026-10-05 06:00:00', $space->sale_starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-10-12 06:00:00', $space->sale_ends_at->utc()->toDateTimeString());
        $this->assertSame($space->sale_ends_at->toISOString(), $response->json('data.sale_ends_at'));
    }

    public function test_a_space_sale_is_ended_by_clearing_all_three(): void
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => now(),
            'sale_ends_at' => now()->addWeek(),
        ]);

        $this->putJson("/api/v1/admin/spaces/{$space->id}", $this->spacePayload([
            'building_id' => $space->building_id,
            'sale_hourly_rate_points' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]))->assertOk();

        $space->refresh();
        $this->assertNull($space->sale_hourly_rate_points);
        $this->assertNull($space->sale_ends_at);
    }

    public function test_an_update_without_the_sale_keeps_it(): void
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => now(),
            'sale_ends_at' => now()->addWeek(),
        ]);

        $this->putJson("/api/v1/admin/spaces/{$space->id}", $this->spacePayload([
            'building_id' => $space->building_id,
        ]))->assertOk();

        $this->assertSame(80, $space->refresh()->sale_hourly_rate_points);
    }

    public function test_the_sale_fields_go_together(): void
    {
        $this->postJson('/api/v1/admin/spaces', $this->spacePayload([
            'sale_hourly_rate_points' => 80,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['sale_starts_at', 'sale_ends_at']);

        $this->postJson('/api/v1/admin/plans', $this->planPayload([
            'sale_ends_at' => '2026-10-07 10:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['sale_price_points', 'sale_starts_at']);
    }

    public function test_a_sale_ends_after_it_starts(): void
    {
        $this->postJson('/api/v1/admin/spaces', $this->spacePayload([
            'sale_hourly_rate_points' => 80,
            'sale_starts_at' => '2026-10-12 09:00',
            'sale_ends_at' => '2026-10-05 09:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['sale_ends_at']);
    }

    public function test_a_sale_is_below_the_price_sent_or_stored(): void
    {
        $sale = ['sale_starts_at' => '2026-10-05 09:00', 'sale_ends_at' => '2026-10-12 09:00'];

        $this->postJson('/api/v1/admin/spaces', $this->spacePayload($sale + ['sale_hourly_rate_points' => 100]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sale_hourly_rate_points']);

        // A space with no hourly rate has nothing to put on sale.
        $this->postJson('/api/v1/admin/spaces', $this->spacePayload($sale + ['hourly_rate_points' => null, 'sale_hourly_rate_points' => 50]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sale_hourly_rate_points']);

        $this->postJson('/api/v1/admin/plans', $this->planPayload($sale + ['sale_price_points' => 130]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sale_price_points']);

        // Lowering the price under a sale already stored is refused too.
        $plan = Plan::factory()->create(['price_points' => 120, 'sale_price_points' => 100, 'sale_starts_at' => now(), 'sale_ends_at' => now()->addDays(2)]);
        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->planPayload(['price_points' => 90]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sale_price_points']);
    }

    public function test_a_sale_that_has_ended_does_not_hold_the_price_up(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120, 'sale_price_points' => 100, 'sale_starts_at' => now()->subWeek(), 'sale_ends_at' => now()->subDay()]);

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->planPayload(['price_points' => 90]))->assertOk();

        $this->assertSame(90, $plan->refresh()->price_points);
    }

    public function test_a_scheduled_sale_is_not_announced_on_the_public_listing(): void
    {
        Plan::factory()->create(['price_points' => 120, 'sale_price_points' => 100, 'sale_starts_at' => now()->addWeek(), 'sale_ends_at' => now()->addWeeks(2), 'is_active' => true, 'is_public' => true]);

        $plan = $this->getJson('/api/v1/plans')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('sale_price_points', $plan);
        $this->assertArrayNotHasKey('sale_starts_at', $plan);
    }

    public function test_a_package_is_put_on_sale_for_two_days(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120]);

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->planPayload([
            'sale_price_points' => 100,
            'sale_starts_at' => '2026-10-05 10:00',
            'sale_ends_at' => '2026-10-07 10:00',
        ]))->assertOk();

        $plan->refresh();
        $this->assertSame(100, $plan->sale_price_points);
        $this->assertSame('2026-10-07 07:00:00', $plan->sale_ends_at->utc()->toDateTimeString());

        $this->getJson("/api/v1/admin/plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.sale_price_points', 100)
            ->assertJsonPath('data.sale_starts_at', $plan->sale_starts_at->toISOString())
            ->assertJsonPath('data.sale_ends_at', $plan->sale_ends_at->toISOString());
    }
}
