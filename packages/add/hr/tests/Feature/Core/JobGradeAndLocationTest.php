<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\WorkLocation;
use Add\Hr\Tests\TestCase;

class JobGradeAndLocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith([Abilities::ORG_VIEW, Abilities::ORG_MANAGE]);
    }

    public function test_grades_crud_and_index_is_ordered_by_rank(): void
    {
        $this->postJson('/api/hr/job-grades', ['code' => 'G2', 'name' => ['ar' => 'الثانية'], 'rank' => 2])->assertCreated();
        $this->postJson('/api/hr/job-grades', ['code' => 'G1', 'name' => ['ar' => 'الأولى'], 'rank' => 1])->assertCreated();

        $this->getJson('/api/hr/job-grades')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'G1')
            ->assertJsonPath('data.1.code', 'G2');

        $grade = JobGrade::query()->where('code', 'G1')->firstOrFail();

        $this->patchJson("/api/hr/job-grades/{$grade->id}", ['rank' => 5])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.job_grade_updated')]);

        $this->postJson('/api/hr/job-grades', ['code' => 'G2', 'name' => ['ar' => 'س'], 'rank' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->deleteJson("/api/hr/job-grades/{$grade->id}")->assertNoContent();
    }

    public function test_locations_crud(): void
    {
        $id = $this->postJson('/api/hr/work-locations', [
            'name' => ['ar' => 'حلب — السكك الحديدية'],
            'address' => 'Aleppo',
        ])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');

        $this->getJson("/api/hr/work-locations/{$id}")->assertOk()->assertJsonPath('data.address', 'Aleppo');

        $this->patchJson("/api/hr/work-locations/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.work_location_updated')]);
        $this->assertFalse(WorkLocation::query()->findOrFail($id)->is_active);

        $this->deleteJson("/api/hr/work-locations/{$id}")->assertNoContent();
    }

    public function test_reading_requires_the_view_ability(): void
    {
        $this->actingAsWith([]);

        $this->getJson('/api/hr/job-grades')->assertForbidden();
        $this->getJson('/api/hr/work-locations')->assertForbidden();
    }
}
