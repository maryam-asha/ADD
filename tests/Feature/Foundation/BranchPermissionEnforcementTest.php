<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task B2 pilot: Branches is the one resource switched from the coarse
 * role:admin|operations route-group check to granular `permission:`
 * middleware (docs/decisions/rbac-permission-pilot.md), proving a custom
 * role can reach an admin action on the strength of its granted permissions
 * alone, not by matching a hardcoded role name.
 */
class BranchPermissionEnforcementTest extends TestCase
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

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function actingAsCustomRole(string $roleName, array $permissions): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($roleName);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_a_custom_role_with_only_branches_view_can_list_branches(): void
    {
        $this->actingAsCustomRole('front-desk', ['branches.view']);
        Branch::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/branches')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_custom_role_without_branches_create_cannot_create_a_branch(): void
    {
        $this->actingAsCustomRole('front-desk', ['branches.view']);

        $response = $this->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'فرع', 'en' => 'Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_reaches_every_branches_action_via_the_sync_service_safeguard_alone(): void
    {
        $admin = $this->actingAsAdmin();

        // Prove the safeguard actually ran: admin's permission set includes
        // the branches permissions PermissionSeeder never grants it by name.
        $this->assertTrue($admin->hasPermissionTo('branches.view'));
        $this->assertTrue($admin->hasPermissionTo('branches.create'));
        $this->assertTrue($admin->hasPermissionTo('branches.update'));
        $this->assertTrue($admin->hasPermissionTo('branches.delete'));

        $branch = Branch::factory()->create();

        $this->getJson('/api/v1/admin/branches')->assertOk();
        $this->getJson("/api/v1/admin/branches/{$branch->id}")->assertOk();

        $this->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'فرع', 'en' => 'Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ])->assertCreated();

        $this->putJson("/api/v1/admin/branches/{$branch->id}", [
            'name' => $branch->name,
            'city' => $branch->city,
            'timezone' => 'Asia/Riyadh',
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")->assertNoContent();
    }

    public function test_operations_retains_exactly_its_current_branches_access_after_the_switch(): void
    {
        $this->actingAsOperations();
        $branch = Branch::factory()->create();

        $this->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'فرع', 'en' => 'Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ])->assertCreated();

        $this->putJson("/api/v1/admin/branches/{$branch->id}", [
            'name' => $branch->name,
            'city' => $branch->city,
            'timezone' => 'Asia/Riyadh',
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")->assertForbidden();
    }

    public function test_a_permission_denied_response_carries_a_translated_message_in_english(): void
    {
        $this->actingAsCustomRole('front-desk', ['branches.view']);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'فرع', 'en' => 'Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_a_permission_denied_response_carries_a_translated_message_in_arabic(): void
    {
        $this->actingAsCustomRole('front-desk', ['branches.view']);

        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'فرع', 'en' => 'Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'غير مخوّل بتنفيذ هذا الإجراء.');
    }

    public function test_permission_seeder_is_idempotent(): void
    {
        // Running PermissionSeeder twice (setUp already ran it once) must not
        // throw and must leave operations with exactly the same grant.
        $this->seed(PermissionSeeder::class);

        $operations = Role::findByName('operations', 'web');
        $this->assertEqualsCanonicalizing(
            ['branches.view', 'branches.create', 'branches.update'],
            $operations->permissions()->pluck('name')->all()
        );
    }
}
