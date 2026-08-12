<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * A company admin (company_user.is_admin) manages their own company's
 * members directly — a regular member cannot, even for a fellow member's
 * flags, and never for their own (CompanyPolicy::manageMembers,
 * docs/decisions/rbac-scoping.md). Operations bypasses this unconditionally
 * via the existing Gate::before, both through the admin-dashboard routes
 * (already covered by CompanyMemberDoorAccessTest) and here for the new
 * is_admin field specifically.
 */
class CompanyMemberAdminManagementTest extends IdentityTestCase
{
    public function test_a_company_admin_is_allowed_to_manage_members(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $company->members()->attach($admin->id, ['is_admin' => true]);

        $this->assertTrue(Gate::forUser($admin)->allows('manageMembers', $company));
    }

    public function test_a_regular_member_is_denied_managing_members(): void
    {
        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['is_admin' => false]);

        $this->assertFalse(Gate::forUser($member)->allows('manageMembers', $company));
    }

    public function test_a_non_member_is_denied_managing_members(): void
    {
        $company = Company::factory()->create();
        $outsider = User::factory()->create();

        $this->assertFalse(Gate::forUser($outsider)->allows('manageMembers', $company));
    }

    public function test_admin_of_one_company_cannot_manage_a_different_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminOfA = User::factory()->create();
        $companyA->members()->attach($adminOfA->id, ['is_admin' => true]);

        $this->assertFalse(Gate::forUser($adminOfA)->allows('manageMembers', $companyB));
    }

    public function test_a_company_admin_can_toggle_another_members_door_access_via_the_api(): void
    {
        $company = Company::factory()->create();
        $companyAdmin = User::factory()->create();
        $companyAdmin->assignRole('member');
        $company->members()->attach($companyAdmin->id, ['is_admin' => true]);

        $target = User::factory()->create();
        $company->members()->attach($target->id, ['door_access_enabled' => false]);

        Sanctum::actingAs($companyAdmin, ['*']);
        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/member/companies/{$company->id}/members/{$target->id}", [
            'door_access_enabled' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Door access updated.');
        $this->assertTrue(
            $company->members()->wherePivot('door_access_enabled', true)->where('users.id', $target->id)->exists()
        );
    }

    public function test_a_company_admin_can_grant_is_admin_to_another_member_via_the_api(): void
    {
        $company = Company::factory()->create();
        $companyAdmin = User::factory()->create();
        $companyAdmin->assignRole('member');
        $company->members()->attach($companyAdmin->id, ['is_admin' => true]);

        $target = User::factory()->create();
        $company->members()->attach($target->id, ['is_admin' => false]);

        Sanctum::actingAs($companyAdmin, ['*']);
        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/member/companies/{$company->id}/members/{$target->id}/admin", [
            'is_admin' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Admin status updated.');
        $this->assertTrue(Gate::forUser($target->fresh())->allows('manageMembers', $company));
    }

    public function test_a_regular_member_cannot_toggle_another_members_door_access_via_the_api(): void
    {
        $company = Company::factory()->create();
        $regularMember = User::factory()->create();
        $regularMember->assignRole('member');
        $company->members()->attach($regularMember->id, ['is_admin' => false]);

        $target = User::factory()->create();
        $company->members()->attach($target->id, ['door_access_enabled' => false]);

        Sanctum::actingAs($regularMember, ['*']);
        $response = $this->patchJson("/api/v1/member/companies/{$company->id}/members/{$target->id}", [
            'door_access_enabled' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(
            $company->members()->wherePivot('door_access_enabled', true)->where('users.id', $target->id)->exists()
        );
    }

    public function test_a_regular_member_cannot_grant_is_admin_to_another_member_via_the_api(): void
    {
        $company = Company::factory()->create();
        $regularMember = User::factory()->create();
        $regularMember->assignRole('member');
        $company->members()->attach($regularMember->id, ['is_admin' => false]);

        $target = User::factory()->create();
        $company->members()->attach($target->id, ['is_admin' => false]);

        Sanctum::actingAs($regularMember, ['*']);
        $response = $this->patchJson("/api/v1/member/companies/{$company->id}/members/{$target->id}/admin", [
            'is_admin' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse(Gate::forUser($target->fresh())->allows('manageMembers', $company));
    }

    public function test_a_regular_member_cannot_grant_themselves_admin_via_the_api(): void
    {
        $company = Company::factory()->create();
        $regularMember = User::factory()->create();
        $regularMember->assignRole('member');
        $company->members()->attach($regularMember->id, ['is_admin' => false]);

        Sanctum::actingAs($regularMember, ['*']);
        $response = $this->patchJson("/api/v1/member/companies/{$company->id}/members/{$regularMember->id}/admin", [
            'is_admin' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_managing_a_user_who_is_not_a_member_of_the_company_fails_validation(): void
    {
        $company = Company::factory()->create();
        $companyAdmin = User::factory()->create();
        $companyAdmin->assignRole('member');
        $company->members()->attach($companyAdmin->id, ['is_admin' => true]);

        $outsider = User::factory()->create();

        Sanctum::actingAs($companyAdmin, ['*']);
        $response = $this->patchJson("/api/v1/member/companies/{$company->id}/members/{$outsider->id}", [
            'door_access_enabled' => true,
        ]);

        $response->assertUnprocessable();
    }

    public function test_operations_bootstraps_a_companys_first_admin_via_store(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $newMember = User::factory()->create();

        $response = $this->postJson("/api/v1/admin/companies/{$company->id}/members", [
            'user_id' => $newMember->id,
            'is_admin' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_admin', true);
    }

    public function test_operations_admin_changed_is_audit_logged(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['is_admin' => false]);

        $this->patchJson("/api/v1/admin/companies/{$company->id}/members/{$member->id}/admin", [
            'is_admin' => true,
        ])->assertOk();

        $activity = Activity::where('description', 'company_member_admin_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($operator->id, $activity->causer_id);
        $this->assertSame($member->id, $activity->properties['user_id']);
        $this->assertTrue($activity->properties['is_admin']);
    }
}
