<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\TestCase;

class PositionTest extends TestCase
{
    use HiresEmployees;
    use TravelsOnTheOrganisationsClock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith([Abilities::ORG_VIEW, Abilities::ORG_MANAGE]);
    }

    public function test_position_belongs_to_an_existing_unit_and_defaults_headcount_to_one(): void
    {
        $unit = OrgUnit::factory()->create();
        $grade = JobGrade::factory()->create();

        $this->postJson('/api/hr/positions', [
            'org_unit_id' => $unit->id,
            'job_grade_id' => $grade->id,
            'title' => ['ar' => 'موظف استقبال', 'en' => 'Receptionist'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.org_unit_id', $unit->id)
            ->assertJsonPath('data.job_grade_id', $grade->id)
            ->assertJsonPath('data.headcount', 1)
            ->assertJsonPath('data.title.en', 'Receptionist');

        $this->postJson('/api/hr/positions', ['org_unit_id' => 9999, 'title' => ['ar' => 'س']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('org_unit_id');
    }

    public function test_index_filters_by_unit(): void
    {
        $a = OrgUnit::factory()->create();
        $b = OrgUnit::factory()->create();
        Position::factory()->count(2)->create(['org_unit_id' => $a->id]);
        Position::factory()->create(['org_unit_id' => $b->id]);

        $this->getJson("/api/hr/positions?org_unit_id={$a->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/hr/positions')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_update_and_delete(): void
    {
        $position = Position::factory()->create();

        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 3])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.position_updated')]);
        $this->assertSame(3, $position->fresh()->headcount);

        $this->deleteJson("/api/hr/positions/{$position->id}")->assertNoContent();
    }

    public function test_position_held_by_an_employee_cannot_be_deleted(): void
    {
        $position = Position::factory()->create();
        $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);

        $this->deleteJson("/api/hr/positions/{$position->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('hr::core.position_in_use'));
    }

    public function test_position_shows_occupied_seats(): void
    {
        $position = Position::factory()->create(['headcount' => 3]);
        $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);

        $this->getJson("/api/hr/positions/{$position->id}")->assertOk()->assertJsonPath('data.occupied', 1);
        $this->getJson("/api/hr/positions?org_unit_id={$position->org_unit_id}")->assertOk()->assertJsonPath('data.0.occupied', 1);
        $this->assertSame(1, $position->occupiedCount());
    }

    public function test_a_holder_with_a_later_change_in_the_same_position_takes_one_seat(): void
    {
        $position = Position::factory()->create(['headcount' => 2]);
        $holder = $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);
        // A promotion scheduled next month keeps her in the same seat: two
        // assignment rows open from today, one person.
        app(EmploymentLifecycleService::class)->record($holder, EmploymentEventType::Promotion, [
            'effective_on' => now()->addMonth()->toDateString(),
            'assignment' => ['job_grade_id' => JobGrade::factory()->create()->id],
        ], null);
        $this->assertSame(2, $position->assignments()->openFrom(now())->count());

        $this->assertSame(1, $position->occupiedCount());
        $this->getJson("/api/hr/positions/{$position->id}")->assertOk()->assertJsonPath('data.occupied', 1);
        $this->getJson("/api/hr/positions?org_unit_id={$position->org_unit_id}")->assertOk()->assertJsonPath('data.0.occupied', 1);
        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 1])->assertOk();
        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 2])->assertOk();

        $this->hireEmployee(['hired_on' => now()->toDateString(), 'assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);
        $this->assertSame(2, $position->occupiedCount());
    }

    public function test_a_held_position_cannot_move_to_another_unit_or_shrink_below_its_holders(): void
    {
        $position = Position::factory()->create(['headcount' => 2]);
        $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);
        $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);

        $this->patchJson("/api/hr/positions/{$position->id}", ['org_unit_id' => OrgUnit::factory()->create()->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.position_held'));

        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.headcount_below_occupancy', ['count' => 2]));

        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 2, 'org_unit_id' => $position->org_unit_id])->assertOk();
    }

    public function test_headcount_must_be_positive(): void
    {
        $position = Position::factory()->create();

        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('headcount');
    }

    public function test_a_seat_is_free_from_the_organisations_day_after_the_last(): void
    {
        $this->travelToLocal('2026-03-01 12:00');
        $position = Position::factory()->create(['headcount' => 1]);
        $holder = $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);
        app(EmploymentLifecycleService::class)->record($holder, EmploymentEventType::Termination, [
            'effective_on' => '2026-03-10',
            'reason' => 'resignation',
        ], null);

        // 01:00 on 11 March locally, still 10 March in UTC.
        $this->travelToLocal('2026-03-11 01:00');

        $this->assertSame(0, $position->occupiedCount());
        $this->getJson("/api/hr/positions?org_unit_id={$position->org_unit_id}")->assertOk()->assertJsonPath('data.0.occupied', 0);
        $this->getJson("/api/hr/positions/{$position->id}")->assertOk()->assertJsonPath('data.occupied', 0);
    }
}
