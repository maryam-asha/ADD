<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Tests\TestCase;

class PositionTest extends TestCase
{
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

    public function test_headcount_must_be_positive(): void
    {
        $position = Position::factory()->create();

        $this->patchJson("/api/hr/positions/{$position->id}", ['headcount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('headcount');
    }
}
