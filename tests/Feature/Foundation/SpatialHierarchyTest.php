<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 acceptance criteria (docs/architecture/2026-08-08-backend-build-plan.md):
 * the full seven-level hierarchy is creatable; maintenance on one space
 * doesn't touch its floor or siblings; a non-active space disappears from
 * results regardless of calendar availability. District was removed after
 * Phase 1 shipped (docs/decisions/district-removed.md) — Branch is the top
 * level now.
 */
class SpatialHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_seven_level_hierarchy_is_creatable_and_resolves_to_one_branch(): void
    {
        $zone = Zone::factory()->create();
        $space = Space::factory()->room()->create([
            'building_id' => $zone->floor->building_id,
            'zone_id' => $zone->id,
        ]);
        $resource = Resource::factory()->for($space)->create(['category' => ResourceCategory::Projector]);
        $seatDesk = SeatDesk::factory()->for($space)->create();

        $space->refresh();

        $this->assertTrue($zone->floor->building->branch->is($space->building->branch));
        $this->assertTrue($resource->space->is($space));
        $this->assertTrue($seatDesk->space->is($space));
        $this->assertSame(SpaceType::Room, $space->space_type);
        $this->assertTrue($space->space_type->isLockable());
    }

    public function test_setting_maintenance_on_one_space_does_not_touch_its_floor_or_sibling_spaces(): void
    {
        $zone = Zone::factory()->create();
        $maintained = Space::factory()->create(['building_id' => $zone->floor->building_id, 'zone_id' => $zone->id]);
        $sibling = Space::factory()->create(['building_id' => $zone->floor->building_id, 'zone_id' => $zone->id]);

        $maintained->update([
            'status' => OperationalStatus::Maintenance,
            'status_reason' => 'Carpet replacement',
            'status_from' => now(),
        ]);

        $sibling->refresh();
        $zone->refresh();

        $this->assertSame(OperationalStatus::Maintenance, $maintained->status);
        $this->assertSame(OperationalStatus::Active, $sibling->status);
        // Floor/Zone have no status column at all (tests/Guards/SpatialHierarchyGuardTest.php) —
        // there is nothing on them that a maintenance flag could have escalated into.
        $this->assertFalse(Schema::hasColumn('zones', 'status'));
    }

    public function test_a_non_active_space_disappears_from_the_active_scope_regardless_of_dates(): void
    {
        $active = Space::factory()->create();
        $maintenance = Space::factory()->maintenance()->create([
            // Even a maintenance window that has already ended must not
            // resurrect the space in results — decision #8 has no
            // hierarchical/temporal escalation logic, only the flag itself.
            'status_from' => now()->subDays(5),
            'status_until' => now()->subDay(),
        ]);
        $retired = Space::factory()->create(['status' => OperationalStatus::Retired]);

        $activeIds = Space::query()->active()->pluck('id');

        $this->assertTrue($activeIds->contains($active->id));
        $this->assertFalse($activeIds->contains($maintenance->id));
        $this->assertFalse($activeIds->contains($retired->id));
    }

    public function test_resources_carry_their_own_operational_status_independent_of_their_space(): void
    {
        $space = Space::factory()->create();
        $resource = Resource::factory()->for($space)->maintenance()->create();

        $space->refresh();

        $this->assertSame(OperationalStatus::Maintenance, $resource->status);
        $this->assertSame(OperationalStatus::Active, $space->status);
    }

    public function test_building_floor_and_zone_chain_is_navigable_both_directions(): void
    {
        $building = Building::factory()->create();
        $floor = Floor::factory()->for($building)->create();
        $zone = Zone::factory()->for($floor)->create();

        $this->assertTrue($building->floors->pluck('id')->contains($floor->id));
        $this->assertTrue($floor->zones->pluck('id')->contains($zone->id));
        $this->assertTrue($zone->floor->is($floor));
        $this->assertTrue($floor->building->is($building));
    }
}
