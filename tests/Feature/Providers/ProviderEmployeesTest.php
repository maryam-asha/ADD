<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\CompanyInvitationAcceptor;
use Laravel\Sanctum\Sanctum;

class ProviderEmployeesTest extends ProviderTestCase
{
    private Provider $provider;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->company = $this->clientCompany($this->provider);
    }

    private function memberRequest(string $phone, bool $door = true): ProviderRequest
    {
        return ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->company->id,
            'type' => ProviderRequestType::CompanyMember,
            'payload' => ['phone' => $phone, 'name' => 'Sara', 'door_access_enabled' => $door],
        ]);
    }

    public function test_an_approved_employee_with_an_account_joins_the_company(): void
    {
        $member = User::factory()->create(['phone' => '+963911111111']);
        $member->assignRole('member');
        $request = $this->memberRequest('+963911111111');
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $pivot = $this->company->members()->where('users.id', $member->id)->sole()->pivot;
        $this->assertTrue((bool) $pivot->door_access_enabled);
        $this->assertFalse((bool) $pivot->is_admin);
        $this->assertSame($member->id, $request->fresh()->result_id);
    }

    public function test_an_approved_employee_without_an_account_joins_when_they_sign_up(): void
    {
        $request = $this->memberRequest('+963922222222');
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();
        $this->assertNull($request->fresh()->result_id);
        $this->assertSame(1, CompanyInvitation::query()->open()->count());

        $member = User::factory()->create(['phone' => '+963922222222']);
        app(CompanyInvitationAcceptor::class)->acceptFor($member);

        $this->assertTrue($this->company->members()->where('users.id', $member->id)->exists());
        $this->assertSame(0, CompanyInvitation::query()->open()->count());
    }

    public function test_a_phone_held_by_a_staff_account_is_never_linked(): void
    {
        $operator = User::factory()->create(['phone' => '+963933333333']);
        $operator->assignRole('operations');
        $request = $this->memberRequest('+963933333333');
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->assertFalse($this->company->members()->where('users.id', $operator->id)->exists());
    }

    public function test_approving_an_admin_request_makes_the_employee_admin(): void
    {
        $member = User::factory()->create();
        $this->company->members()->attach($member->id);
        $request = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->company->id,
            'type' => ProviderRequestType::CompanyAdmin,
            'payload' => ['user_id' => $member->id],
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->assertTrue((bool) $this->company->members()->where('users.id', $member->id)->sole()->pivot->is_admin);
    }

    public function test_a_company_admin_of_a_provider_company_asks_instead_of_granting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $colleague = User::factory()->create();
        $this->company->members()->attach($admin->id, ['is_admin' => true]);
        $this->company->members()->attach($colleague->id);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$this->company->id}/members/{$colleague->id}/admin", ['is_admin' => true])
            ->assertStatus(202)
            ->assertJsonPath('message', __('api.member.company_admin_requested'));

        $this->assertFalse((bool) $this->company->members()->where('users.id', $colleague->id)->sole()->pivot->is_admin);
        $request = ProviderRequest::sole();
        $this->assertSame(ProviderRequestType::CompanyAdmin, $request->type);
        $this->assertSame($admin->id, $request->submitted_by);
    }

    public function test_a_company_admin_of_a_provider_company_still_revokes_directly(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $other = User::factory()->create();
        $this->company->members()->attach($admin->id, ['is_admin' => true]);
        $this->company->members()->attach($other->id, ['is_admin' => true]);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$this->company->id}/members/{$other->id}/admin", ['is_admin' => false])
            ->assertOk();

        $this->assertFalse((bool) $this->company->members()->where('users.id', $other->id)->sole()->pivot->is_admin);
    }

    public function test_a_direct_company_admin_still_grants_directly(): void
    {
        $direct = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $colleague = User::factory()->create();
        $direct->members()->attach($admin->id, ['is_admin' => true]);
        $direct->members()->attach($colleague->id);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$direct->id}/members/{$colleague->id}/admin", ['is_admin' => true])
            ->assertOk();

        $this->assertTrue((bool) $direct->members()->where('users.id', $colleague->id)->sole()->pivot->is_admin);
        $this->assertSame(0, ProviderRequest::count());
    }
}
