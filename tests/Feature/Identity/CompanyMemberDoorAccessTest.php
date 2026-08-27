<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

/**
 * D.8 (docs/decisions/rbac-scoping.md): the one scoped capability in the
 * app. Membership alone is not enough, and the flag alone is impossible by
 * construction — both conditions are exercised explicitly.
 */
class CompanyMemberDoorAccessTest extends IdentityTestCase
{
    public function test_a_member_with_the_flag_enabled_is_allowed_door_access(): void
    {
        $company = Company::factory()->create();
        $member = User::factory()->create();
        $member->assignRole('member');
        $company->members()->attach($member->id, ['door_access_enabled' => true]);

        $this->assertTrue(Gate::forUser($member)->allows('useDoorAccess', $company));
    }

    public function test_a_member_with_the_flag_disabled_is_denied_door_access(): void
    {
        $company = Company::factory()->create();
        $member = User::factory()->create();
        $member->assignRole('member');
        $company->members()->attach($member->id, ['door_access_enabled' => false]);

        $this->assertFalse(Gate::forUser($member)->allows('useDoorAccess', $company));
    }

    public function test_a_non_member_is_denied_door_access_regardless_of_other_companies(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $nonMember = User::factory()->create();
        $nonMember->assignRole('member');
        // A member of a *different* company must not gain access to this one.
        $otherCompany->members()->attach($nonMember->id, ['door_access_enabled' => true]);

        $this->assertFalse(Gate::forUser($nonMember)->allows('useDoorAccess', $company));
    }

    /**
     * The dynamic-permission pilot removes AppServiceProvider's unconditional
     * `Gate::before` admin bypass (see App\Providers\AppServiceProvider) —
     * CompanyPolicy itself is untouched, but it no longer has an implicit
     * admin-bypass caller in front of it. An admin with no actual
     * relationship to this company is correctly denied, same as anyone else;
     * this closes a blanket-access gap rather than regressing one.
     */
    public function test_admin_no_longer_bypasses_the_door_access_check_without_actual_company_membership(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertFalse(Gate::forUser($admin)->allows('useDoorAccess', $company));
    }

    public function test_operations_can_add_a_member_with_door_access_via_the_api(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $newMember = User::factory()->create();
        $newMember->assignRole('member');

        $response = $this->postJson("/api/v1/admin/companies/{$company->id}/members", [
            'user_id' => $newMember->id,
            'door_access_enabled' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.door_access_enabled', true);
        $this->assertTrue($company->members()->where('users.id', $newMember->id)->exists());
    }

    public function test_adding_the_same_member_twice_fails_validation(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['door_access_enabled' => false]);

        $response = $this->postJson("/api/v1/admin/companies/{$company->id}/members", [
            'user_id' => $member->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('user_id');
    }

    public function test_operations_can_toggle_door_access_via_the_api(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['door_access_enabled' => false]);

        $response = $this->patchJson("/api/v1/admin/companies/{$company->id}/members/{$member->id}", [
            'door_access_enabled' => true,
        ]);

        $response->assertOk();
        $this->assertTrue(Gate::forUser($member)->allows('useDoorAccess', $company));
    }

    public function test_operations_can_remove_a_company_member_via_the_api(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['door_access_enabled' => true]);

        $response = $this->deleteJson("/api/v1/admin/companies/{$company->id}/members/{$member->id}");

        $response->assertNoContent();
        $this->assertFalse($company->members()->where('users.id', $member->id)->exists());
    }
}
