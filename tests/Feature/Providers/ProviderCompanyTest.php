<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;

class ProviderCompanyTest extends ProviderTestCase
{
    public function test_a_provider_lists_only_its_own_companies(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $mine = $this->clientCompany($provider);
        $this->clientCompany(Provider::factory()->create());
        Company::factory()->create(); // a direct company

        $this->getJson('/api/v1/provider/companies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_another_providers_company_and_a_direct_company_are_not_found(): void
    {
        $this->providerAccount();
        $theirs = $this->clientCompany(Provider::factory()->create());
        $direct = Company::factory()->create();

        $this->getJson("/api/v1/provider/companies/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/provider/companies/{$direct->id}")->assertNotFound();
    }

    public function test_a_company_shows_its_members_and_open_invitations(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $company = $this->clientCompany($provider);
        $member = User::factory()->create(['phone' => '0933333333']);
        $company->members()->attach($member->id, ['is_admin' => true, 'door_access_enabled' => false]);

        $this->getJson("/api/v1/provider/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.members.0.id', $member->id)
            ->assertJsonPath('data.members.0.is_admin', true)
            ->assertJsonPath('data.invitations', []);
    }

    public function test_a_provider_removes_an_employee_without_approval(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $company = $this->clientCompany($provider);
        $member = User::factory()->create();
        $company->members()->attach($member->id);

        $this->deleteJson("/api/v1/provider/companies/{$company->id}/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', __('api.provider.member_removed'));

        $this->assertFalse($company->members()->where('users.id', $member->id)->exists());
    }

    public function test_an_inactive_provider_is_refused_everywhere(): void
    {
        $this->providerAccount(Provider::factory()->inactive()->create());

        $this->getJson('/api/v1/provider/companies')
            ->assertForbidden()
            ->assertJsonPath('message', __('api.provider.inactive'));
    }

    public function test_a_provider_account_unlinked_from_its_provider_is_refused(): void
    {
        $user = User::factory()->create();
        $user->assignRole('provider');
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_a_member_app_token_never_reaches_the_provider_surface(): void
    {
        $user = $this->providerAccount();
        Sanctum::actingAs($user, ['member-app']);

        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_operations_and_members_cannot_use_the_provider_surface(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_a_provider_cannot_reach_the_admin_or_member_api(): void
    {
        $this->providerAccount();

        $this->getJson('/api/v1/admin/companies')->assertForbidden();
        $this->getJson('/api/v1/member/memberships')->assertForbidden();
    }
}
