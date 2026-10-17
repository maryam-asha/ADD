<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Tests\TestCase;

class OrgUnitTest extends TestCase
{
    private function manager(): void
    {
        $this->actingAsWith([Abilities::ORG_VIEW, Abilities::ORG_MANAGE]);
    }

    public function test_manager_can_create_nested_units_and_read_them_as_a_tree(): void
    {
        $this->manager();

        $root = $this->postJson('/api/hr/org-units', [
            'name' => ['ar' => 'الإدارة', 'en' => 'Management'],
            'code' => 'MGT',
        ])->assertCreated()->json('data');

        $this->postJson('/api/hr/org-units', [
            'name' => ['ar' => 'الاستقبال'],
            'parent_id' => $root['id'],
        ])->assertCreated()->assertJsonPath('data.parent_id', $root['id']);

        $this->getJson('/api/hr/org-units?tree=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name.en', 'Management')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.children.0.name.ar', 'الاستقبال');
    }

    public function test_flat_index_lists_every_unit(): void
    {
        $this->manager();
        $root = OrgUnit::factory()->create();
        OrgUnit::factory()->create(['parent_id' => $root->id]);

        $this->getJson('/api/hr/org-units')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_unit_cannot_become_its_own_descendant(): void
    {
        $this->manager();
        $root = OrgUnit::factory()->create();
        $child = OrgUnit::factory()->create(['parent_id' => $root->id]);
        $grandchild = OrgUnit::factory()->create(['parent_id' => $child->id]);

        $this->patchJson("/api/hr/org-units/{$root->id}", ['parent_id' => $grandchild->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.org_unit_cycle'));

        $this->patchJson("/api/hr/org-units/{$root->id}", ['parent_id' => $root->id])
            ->assertStatus(422);
    }

    public function test_update_returns_a_message(): void
    {
        $this->manager();
        $unit = OrgUnit::factory()->create();

        $this->patchJson("/api/hr/org-units/{$unit->id}", ['name' => ['ar' => 'جديد'], 'is_active' => false])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.org_unit_updated')]);

        $this->assertSame('جديد', $unit->fresh()->name['ar']);
        $this->assertFalse($unit->fresh()->is_active);
    }

    public function test_unit_with_children_cannot_be_deleted(): void
    {
        $this->manager();
        $root = OrgUnit::factory()->create();
        OrgUnit::factory()->create(['parent_id' => $root->id]);

        $this->deleteJson("/api/hr/org-units/{$root->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('hr::core.org_unit_in_use'));
    }

    public function test_unit_with_positions_cannot_be_deleted(): void
    {
        $this->manager();
        $unit = OrgUnit::factory()->create();
        Position::factory()->create(['org_unit_id' => $unit->id]);

        $this->deleteJson("/api/hr/org-units/{$unit->id}")->assertStatus(409);
    }

    public function test_an_empty_unit_is_deleted(): void
    {
        $this->manager();
        $unit = OrgUnit::factory()->create();

        $this->deleteJson("/api/hr/org-units/{$unit->id}")->assertNoContent();
        $this->assertModelMissing($unit);
    }

    public function test_view_ability_cannot_write(): void
    {
        $this->actingAsWith([Abilities::ORG_VIEW]);

        $this->getJson('/api/hr/org-units')->assertOk();
        $this->postJson('/api/hr/org-units', ['name' => ['ar' => 'س']])->assertForbidden();
    }

    public function test_name_requires_arabic(): void
    {
        $this->manager();

        $this->postJson('/api/hr/org-units', ['name' => ['en' => 'Only English']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name.ar');
    }

    public function test_code_is_unique(): void
    {
        $this->manager();
        OrgUnit::factory()->create(['code' => 'OPS']);

        $this->postJson('/api/hr/org-units', ['name' => ['ar' => 'س'], 'code' => 'OPS'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }
}
