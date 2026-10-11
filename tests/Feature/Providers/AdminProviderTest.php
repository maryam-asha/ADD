<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class AdminProviderTest extends ProviderTestCase
{
    public function test_an_admin_creates_and_lists_providers(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/providers', ['name' => 'Platform', 'contract_ref' => 'P-1'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Platform')
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/admin/providers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_admin_updates_a_provider(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();

        $this->patchJson("/api/v1/admin/providers/{$provider->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('message', __('api.admin.provider_updated'));

        $this->assertSame('Renamed', $provider->fresh()->name);
    }

    public function test_an_admin_links_a_provider_account_and_unlinks_it(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('provider');

        $this->postJson("/api/v1/admin/providers/{$provider->id}/users/{$account->id}")->assertOk();
        $this->assertTrue(Provider::forUser($account)->is($provider));

        $this->deleteJson("/api/v1/admin/providers/{$provider->id}/users/{$account->id}")->assertOk();
        $this->assertNull(Provider::forUser($account));
    }

    public function test_only_a_provider_role_account_can_be_linked(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        $this->postJson("/api/v1/admin/providers/{$provider->id}/users/{$operator->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.provider_user_not_provider_role'));
    }

    public function test_an_account_already_working_for_a_provider_cannot_be_linked_to_another(): void
    {
        $this->actingAsAdmin();
        $account = User::factory()->create();
        $account->assignRole('provider');
        Provider::factory()->create()->users()->attach($account->id);

        $this->postJson('/api/v1/admin/providers/'.Provider::factory()->create()->id."/users/{$account->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.provider_user_taken'));
    }

    public function test_provider_management_follows_permissions_not_role_names(): void
    {
        $role = Role::findOrCreate('partners-desk', 'web');
        $role->syncPermissions(['providers.view']);
        $user = User::factory()->create();
        $user->assignRole('partners-desk');
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/admin/providers')->assertOk();
        $this->postJson('/api/v1/admin/providers', ['name' => 'X', 'contract_ref' => 'P-2'])->assertForbidden();
    }

    public function test_operations_without_the_permission_is_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/providers')->assertForbidden();
    }
}
