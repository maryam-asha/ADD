<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Laravel\Sanctum\Sanctum;

class ProviderLifecycleTest extends ProviderTestCase
{
    public function test_deactivating_a_provider_cancels_its_pending_requests_and_keeps_its_companies_running(): void
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('provider');
        $provider->users()->attach($account->id);
        $company = $this->clientCompany($provider);
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $pending = ProviderRequest::factory()->create(['provider_id' => $provider->id, 'company_id' => $company->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/providers/{$provider->id}/status", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('message', __('api.admin.provider_status_updated'));

        $this->assertSame(ProviderStatus::Inactive, $provider->fresh()->status);
        $this->assertSame(ProviderRequestStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame('provider_deactivated', $pending->fresh()->rejection_reason);
        $this->assertTrue(Membership::query()->live()->whereKey($membership->id)->exists());
        $this->assertSame($provider->id, $company->fresh()->provider_id);

        Sanctum::actingAs($account, ['*']);
        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_reactivating_does_not_bring_cancelled_requests_back(): void
    {
        $provider = Provider::factory()->inactive()->create();
        $cancelled = ProviderRequest::factory()->create(['provider_id' => $provider->id, 'status' => ProviderRequestStatus::Cancelled]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/providers/{$provider->id}/status", ['status' => 'active'])->assertOk();

        $this->assertSame(ProviderRequestStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_a_company_moves_to_another_provider_and_the_old_one_loses_it(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $company = $this->clientCompany($old);
        $pending = ProviderRequest::factory()->create(['provider_id' => $old->id, 'company_id' => $company->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/companies/{$company->id}/provider", ['provider_id' => $new->id])
            ->assertOk()->assertJsonPath('message', __('api.admin.company_provider_updated'));

        $this->assertSame($new->id, $company->fresh()->provider_id);
        $this->assertSame(ProviderRequestStatus::Cancelled, $pending->fresh()->status);

        $this->providerAccount($old);
        $this->getJson("/api/v1/provider/companies/{$company->id}")->assertNotFound();
    }

    public function test_a_company_moved_to_add_directly_behaves_like_a_direct_company(): void
    {
        $company = $this->clientCompany(Provider::factory()->create());
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/companies/{$company->id}/provider", ['provider_id' => null])->assertOk();

        $this->assertNull($company->fresh()->provider_id);
    }

    public function test_status_and_transfer_follow_their_permissions(): void
    {
        $provider = Provider::factory()->create();
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->patchJson("/api/v1/admin/providers/{$provider->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->patchJson('/api/v1/admin/companies/'.$this->clientCompany($provider)->id.'/provider', ['provider_id' => null])->assertForbidden();
    }
}
