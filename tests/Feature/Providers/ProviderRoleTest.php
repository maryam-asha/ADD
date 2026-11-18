<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProviderRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_the_provider_role_is_seeded_and_protected(): void
    {
        $role = Role::findByName('provider', 'web');

        $this->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'partner'])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertStatus(422);
    }

    public function test_the_provider_role_never_holds_an_admin_permission(): void
    {
        $role = Role::findByName('provider', 'web');

        $this->patchJson("/api/v1/admin/roles/{$role->id}", ['permissions' => ['branches.view']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.role.provider_out_of_scope'));

        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_giving_an_account_the_provider_role_replaces_its_other_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('operations');

        $this->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'provider'])->assertOk();

        $this->assertSame(['provider'], $user->fresh()->getRoleNames()->all());
    }
}
