<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Proves the AssignRoleRequest fix (Rule::exists('roles', 'name') instead of
 * a hardcoded Rule::in(['member', 'operations', 'admin'])) makes a brand new
 * custom role usable end-to-end through the existing
 * PATCH admin/users/{user}/role endpoint — not just creatable via
 * RoleController.
 */
class CustomRoleAssignmentTest extends IdentityTestCase
{
    public function test_a_newly_created_custom_role_can_be_assigned_to_a_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/roles', ['name' => 'front-desk'])->assertCreated();

        $target = User::factory()->create();
        $target->assignRole('operations');

        $response = $this->patchJson("/api/v1/admin/users/{$target->id}/role", ['role' => 'front-desk']);

        $response->assertOk();
        $this->assertTrue($target->fresh()->hasRole('front-desk'));
    }
}
