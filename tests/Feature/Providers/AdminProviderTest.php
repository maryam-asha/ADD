<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class AdminProviderTest extends ProviderTestCase
{
    /** @return array<string, string> */
    private function account(array $overrides = []): array
    {
        return [
            'name' => 'Platform Desk',
            'email' => 'desk@platform.test',
            'phone' => '+963944555001',
            'password' => 'Secret-pass-123',
            'password_confirmation' => 'Secret-pass-123',
            ...$overrides,
        ];
    }

    public function test_an_admin_creates_a_provider_with_its_linked_account(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/providers', ['name' => 'Platform', 'contract_ref' => 'P-1', 'account' => $this->account()])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Platform')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.users_count', 1)
            ->assertJsonPath('data.users.0.email', 'desk@platform.test');

        $provider = Provider::query()->sole();
        $user = User::query()->where('email', 'desk@platform.test')->sole();
        $this->assertTrue($user->hasRole('provider'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertTrue($provider->users()->whereKey($user->id)->exists());
        $this->assertTrue(Hash::check('Secret-pass-123', $user->password));

        $this->getJson('/api/v1/admin/providers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_account_is_required_and_validated(): void
    {
        $this->actingAsAdmin();
        User::factory()->create(['email' => 'taken@platform.test']);

        $this->postJson('/api/v1/admin/providers', ['name' => 'Platform', 'contract_ref' => 'P-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->postJson('/api/v1/admin/providers', [
            'name' => 'Platform',
            'contract_ref' => 'P-1',
            'account' => $this->account(['email' => 'taken@platform.test', 'phone' => '0944', 'password_confirmation' => 'other']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['account.email', 'account.phone', 'account.password']);

        $this->assertSame(0, Provider::query()->count());
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
