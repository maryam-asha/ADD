<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanModule;
use Database\Seeders\PlanModuleSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A plan's module (flexi desk / dedicated desk — the app's tabs, and which
 * services it may order) follows its seat; staff no longer pick it apart
 * from the seat (product owner, 2026-11-03). A plan without a seat has none.
 */
class PlanModuleFromSeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class, PlanModuleSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_the_module_follows_the_seat_whatever_is_sent(): void
    {
        $flexi = PlanModule::query()->where('slug', PlanModule::FLEXI_DESK)->value('id');
        $dedicated = PlanModule::query()->where('slug', PlanModule::DEDICATED_DESK)->value('id');

        $id = $this->postJson('/api/v1/admin/plans', $this->payload(['coworking_seat' => 'dedicated', 'plan_module_id' => $flexi]))
            ->assertCreated()->json('data.id');
        $this->assertSame($dedicated, Plan::query()->findOrFail($id)->plan_module_id);

        $this->putJson("/api/v1/admin/plans/{$id}", $this->payload(['coworking_seat' => 'flexible']))->assertOk();
        $this->assertSame($flexi, Plan::query()->findOrFail($id)->plan_module_id);

        $this->putJson("/api/v1/admin/plans/{$id}", $this->payload(['coworking_seat' => 'none', 'plan_module_id' => $dedicated]))->assertOk();
        $this->assertNull(Plan::query()->findOrFail($id)->plan_module_id);
    }

    public function test_existing_plans_are_aligned_with_their_seat(): void
    {
        $flexi = PlanModule::query()->where('slug', PlanModule::FLEXI_DESK)->value('id');
        $dedicated = PlanModule::query()->where('slug', PlanModule::DEDICATED_DESK)->value('id');
        $desk = Plan::factory()->dedicatedDesk()->create(['plan_module_id' => $flexi]);
        $seat = Plan::factory()->create(['plan_module_id' => null]);
        $none = Plan::factory()->notCoworking()->create(['plan_module_id' => $flexi]);

        (require database_path('migrations/2026_11_06_100000_align_plan_modules_with_seats.php'))->up();

        $this->assertSame($dedicated, $desk->fresh()->plan_module_id);
        $this->assertSame($flexi, $seat->fresh()->plan_module_id);
        $this->assertNull($none->fresh()->plan_module_id);
        $this->assertSame(CoworkingSeat::None, $none->fresh()->coworking_seat);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Flex Desk', 'ar' => 'مكتب مرن'],
            'is_subscription' => true,
            'price_points' => 500,
            'duration_days' => 30,
            'coworking_seat' => 'flexible',
            'coworking_hours' => null,
            'allowance_cycle' => 'period',
        ], $overrides);
    }
}
