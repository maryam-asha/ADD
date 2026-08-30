<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * App\Http\Controllers\Api\V1\Admin\RoleController — full dynamic-role CRUD
 * plus the grouped GET admin/permissions listing. RoleController deliberately
 * doesn't extend AdminResourceController (no `order` column, and destroy()
 * needs protected-role/in-use guards the generic destroy doesn't have) — see
 * the class docblock.
 */
class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_create_a_custom_role_without_permissions(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/roles', ['name' => 'front-desk']);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'front-desk');
        $response->assertJsonPath('data.protected', false);
        $response->assertJsonPath('data.permissions', []);
        $this->assertDatabaseHas('roles', ['name' => 'front-desk']);
    }

    public function test_admin_can_create_a_custom_role_with_an_initial_permission_list(): void
    {
        $this->actingAsAdmin();
        $permissionName = Permission::query()->value('name');

        $response = $this->postJson('/api/v1/admin/roles', [
            'name' => 'front-desk',
            'permissions' => [$permissionName],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.permissions', [$permissionName]);

        $role = Role::findByName('front-desk', 'web');
        $this->assertTrue($role->hasPermissionTo($permissionName));
    }

    public function test_admin_can_rename_a_non_protected_role_and_it_persists(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/roles/{$role->id}", [
            'name' => 'reception',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Role updated.']);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'reception']);
    }

    public function test_admin_can_sync_a_roles_permissions_via_update_and_index_reflects_it(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);
        $permissionName = Permission::query()->value('name');

        $this->patchJson("/api/v1/admin/roles/{$role->id}", [
            'permissions' => [$permissionName],
        ])->assertOk();

        $response = $this->getJson('/api/v1/admin/roles');

        $response->assertOk();
        $entry = collect($response->json('data'))->firstWhere('id', $role->id);
        $this->assertSame([$permissionName], $entry['permissions']);
    }

    public function test_admin_cannot_rename_admin_role_in_english(): void
    {
        $this->actingAsAdmin();
        $adminRole = Role::findByName('admin', 'web');

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/roles/{$adminRole->id}", [
            'name' => 'super-admin',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson(['message' => 'This role cannot be renamed.']);
        $this->assertDatabaseHas('roles', ['id' => $adminRole->id, 'name' => 'admin']);
    }

    public function test_admin_cannot_rename_admin_role_in_arabic(): void
    {
        $this->actingAsAdmin();
        $adminRole = Role::findByName('admin', 'web');

        $response = $this->withHeader('lang', 'ar')->patchJson("/api/v1/admin/roles/{$adminRole->id}", [
            'name' => 'super-admin',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson(['message' => 'لا يمكن إعادة تسمية هذا الدور.']);
    }

    public function test_admin_cannot_rename_operations_or_member_roles(): void
    {
        $this->actingAsAdmin();

        foreach (['operations', 'member'] as $protected) {
            $role = Role::findByName($protected, 'web');

            $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'renamed']);

            $response->assertStatus(422);
            $response->assertJsonPath('message', 'This role cannot be renamed.');
            $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => $protected]);
        }
    }

    public function test_admin_cannot_delete_admin_operations_or_member_roles(): void
    {
        $this->actingAsAdmin();

        foreach (['admin', 'operations', 'member'] as $protected) {
            $role = Role::findByName($protected, 'web');

            $response = $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/roles/{$role->id}");

            $response->assertStatus(422);
            $response->assertExactJson(['message' => 'This role cannot be deleted.']);
            $this->assertDatabaseHas('roles', ['id' => $role->id]);
        }
    }

    public function test_admin_cannot_delete_a_role_with_a_user_currently_assigned(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('front-desk');

        $response = $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/roles/{$role->id}");

        $response->assertStatus(422);
        $response->assertExactJson(['message' => 'This role is currently assigned to one or more users. Reassign them before deleting it.']);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_admin_can_delete_an_unused_custom_role(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);

        $response = $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/roles/{$role->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_get_permissions_groups_entries_by_module(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/permissions');

        $response->assertOk();
        $branchesGroup = collect($response->json('data'))->firstWhere('module', 'branches');
        $this->assertNotNull($branchesGroup);
        $actionNames = collect($branchesGroup['actions'])->pluck('action')->all();
        $this->assertContains('view', $actionNames);
        $this->assertContains('create', $actionNames);
        $this->assertContains('update', $actionNames);

        foreach ($branchesGroup['actions'] as $action) {
            $this->assertSame("branches.{$action['action']}", $action['name']);
        }
    }

    public function test_update_response_is_message_only_and_destroy_response_is_no_content(): void
    {
        // Every other admin destroy endpoint (AdminResourceController,
        // ErrorLogController, CompanyMemberController) returns 204 no
        // content, not a message — the CLAUDE.md "message not resource"
        // convention documents PATCH/PUT update endpoints specifically, not
        // DELETE.
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);

        $updateResponse = $this->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'reception']);
        $updateResponse->assertOk();
        $this->assertSame(['message'], array_keys($updateResponse->json()));

        $destroyResponse = $this->deleteJson('/api/v1/admin/roles/'.Role::findByName('reception', 'web')->id);
        $destroyResponse->assertNoContent();
    }

    public function test_a_non_admin_operations_role_gets_403_on_mutating_role_actions(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $role = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);

        $this->postJson('/api/v1/admin/roles', ['name' => 'new-role'])->assertForbidden();
        $this->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertForbidden();
        $this->getJson('/api/v1/admin/permissions')->assertForbidden();
    }

    public function test_admin_cannot_sync_permissions_onto_the_member_role_in_english(): void
    {
        $this->actingAsAdmin();
        $memberRole = Role::findByName('member', 'web');
        $permissionName = Permission::query()->value('name');

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/roles/{$memberRole->id}", [
            'permissions' => [$permissionName],
        ]);

        $response->assertStatus(422);
        $response->assertExactJson(['message' => "Member accounts don't participate in the admin permission system; the member role can't be assigned any admin-panel permissions."]);
        $this->assertFalse($memberRole->fresh()->hasPermissionTo($permissionName));
    }

    public function test_admin_cannot_sync_permissions_onto_the_member_role_in_arabic(): void
    {
        $this->actingAsAdmin();
        $memberRole = Role::findByName('member', 'web');
        $permissionName = Permission::query()->value('name');

        $response = $this->withHeader('lang', 'ar')->patchJson("/api/v1/admin/roles/{$memberRole->id}", [
            'permissions' => [$permissionName],
        ]);

        $response->assertStatus(422);
        $response->assertExactJson(['message' => 'حسابات الأعضاء (member) لا تشارك في نظام صلاحيات لوحة التحكم؛ لا يمكن إسناد أي صلاحيات إدارية لدور العضو.']);
    }

    public function test_renaming_or_deleting_the_member_role_is_still_protected_and_unaffected_by_the_permission_guard(): void
    {
        $this->actingAsAdmin();
        $memberRole = Role::findByName('member', 'web');

        $renameResponse = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/roles/{$memberRole->id}", [
            'name' => 'renamed-member',
        ]);
        $renameResponse->assertStatus(422);
        $renameResponse->assertExactJson(['message' => 'This role cannot be renamed.']);
        $this->assertDatabaseHas('roles', ['id' => $memberRole->id, 'name' => 'member']);

        $deleteResponse = $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/roles/{$memberRole->id}");
        $deleteResponse->assertStatus(422);
        $deleteResponse->assertExactJson(['message' => 'This role cannot be deleted.']);
        $this->assertDatabaseHas('roles', ['id' => $memberRole->id]);
    }

    public function test_admin_can_still_sync_permissions_onto_a_custom_role_and_onto_admin_and_operations(): void
    {
        $this->actingAsAdmin();
        $permissionName = Permission::query()->value('name');

        $customRole = Role::create(['name' => 'front-desk', 'guard_name' => 'web']);
        $this->patchJson("/api/v1/admin/roles/{$customRole->id}", ['permissions' => [$permissionName]])
            ->assertOk();
        $this->assertTrue($customRole->fresh()->hasPermissionTo($permissionName));

        $operationsRole = Role::findByName('operations', 'web');
        $this->patchJson("/api/v1/admin/roles/{$operationsRole->id}", ['permissions' => [$permissionName]])
            ->assertOk();
        $this->assertTrue($operationsRole->fresh()->hasPermissionTo($permissionName));

        $adminRole = Role::findByName('admin', 'web');
        $this->patchJson("/api/v1/admin/roles/{$adminRole->id}", ['permissions' => [$permissionName]])
            ->assertOk();
        $this->assertTrue($adminRole->fresh()->hasPermissionTo($permissionName));
    }
}
