<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Services\PlanSavingCalculator;
use Database\Seeders\PlanModuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LegacyPlanCatalogSeeder;
use Tests\TestCase;

/**
 * The real plan catalog (price sheet of 2026-10-08): its prices, its hours,
 * the savings the sheet's "current discount" column shows, and the old demo
 * catalog gone.
 */
class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    private Space $hotDesk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanModuleSeeder::class, ResourceTypeSeeder::class]);
        $this->hotDesk = Space::factory()->create(['space_type' => SpaceType::CoSpace, 'hourly_rate_points' => 30]);
    }

    private function plan(string $en): Plan
    {
        return Plan::query()->where('name->en', $en)->sole();
    }

    private function hours(Plan $plan): float
    {
        return (float) $plan->resourceGrants()->sole()->quantity;
    }

    public function test_the_catalog_is_the_price_sheet_in_points(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(13, Plan::count());
        $this->assertSame(15, $this->hotDesk->refresh()->hourly_rate_points);

        $expected = [
            '25 Hours a Month' => [300, 30],
            '50 Hours a Month' => [500, 30],
            'Hot Desk — Daily' => [60, 1],
            'Hot Desk — Weekly' => [350, 7],
            'Hot Desk — Monthly' => [1250, 30],
            'Hot Desk — Evening Monthly (5 PM – 12 AM)' => [500, 30],
            'Hot Desk — 3 Months' => [3000, 90],
            'Hot Desk — 6 Months' => [5500, 180],
            'Hot Desk — 12 Months' => [9000, 360],
            'Dedicated Desk — Monthly' => [1750, 30],
            'Dedicated Desk — 3 Months' => [4500, 90],
            'Dedicated Desk — 6 Months' => [8500, 180],
            'Dedicated Desk — 12 Months' => [15000, 360],
        ];

        foreach ($expected as $name => [$points, $days]) {
            $plan = $this->plan($name);
            $this->assertSame($points, $plan->price_points, $name);
            $this->assertSame($days, (int) $plan->duration_days, $name);
            $this->assertTrue($plan->is_subscription && $plan->is_active && $plan->is_public, $name);
            $this->assertTrue($plan->hasAccessWindow(), $name);
        }

        $this->assertTrue($this->plan('Dedicated Desk — Monthly')->isDedicatedDesk());
        $this->assertFalse($this->plan('Hot Desk — Monthly')->isDedicatedDesk());
    }

    public function test_hours_are_the_packages_quota_or_every_hour_of_the_period(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(25.0, $this->hours($this->plan('25 Hours a Month')));
        $this->assertSame(50.0, $this->hours($this->plan('50 Hours a Month')));
        $this->assertSame(24.0, $this->hours($this->plan('Hot Desk — Daily')));
        $this->assertSame(720.0, $this->hours($this->plan('Hot Desk — Monthly')));

        $evening = $this->plan('Hot Desk — Evening Monthly (5 PM – 12 AM)');
        $this->assertSame('17:00', substr((string) $evening->access_window_start, 0, 5));
        $this->assertSame('00:00', substr((string) $evening->access_window_end, 0, 5));
        $this->assertSame(210.0, $this->hours($evening));
    }

    public function test_savings_match_the_sheets_discount_column(): void
    {
        $this->seed(PlanSeeder::class);
        $savings = app(PlanSavingCalculator::class);

        $expected = [
            '25 Hours a Month' => 20,
            '50 Hours a Month' => 33,
            'Hot Desk — Daily' => 50,
            'Hot Desk — Weekly' => 16,
            'Hot Desk — 3 Months' => 20,
            'Hot Desk — 6 Months' => 26,
            'Hot Desk — 12 Months' => 40,
            'Dedicated Desk — 3 Months' => 14,
            'Dedicated Desk — 6 Months' => 19,
            'Dedicated Desk — 12 Months' => 28,
        ];

        foreach ($expected as $name => $percent) {
            $this->assertSame($percent, $savings->for($this->plan($name))?->savingPercent, $name);
        }

        $this->assertNull($savings->for($this->plan('Hot Desk — Monthly')));
        $this->assertNull($savings->for($this->plan('Hot Desk — Evening Monthly (5 PM – 12 AM)')));
    }

    public function test_the_old_catalog_is_deleted_with_its_memberships(): void
    {
        $this->seed(LegacyPlanCatalogSeeder::class);
        Membership::factory()->create(['plan_id' => 1]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(13, Plan::count());
        $this->assertFalse(Plan::query()->where('name->en', 'The Smart Starter')->exists());
        $this->assertSame(0, Membership::count());
    }

    public function test_a_second_run_updates_in_place(): void
    {
        $this->seed(PlanSeeder::class);
        $ids = Plan::query()->orderBy('id')->pluck('id')->all();

        $this->seed(PlanSeeder::class);

        $this->assertSame($ids, Plan::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, $this->plan('Hot Desk — Monthly')->resourceGrants()->count());
    }
}
