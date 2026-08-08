<?php

namespace Tests\Feature\Identity;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * PRD §5.1: every sensitive action is audit-logged with the actor and a
 * before/after payload. Covers the pre-existing user actions this phase
 * wired up, plus every new company/door-access action.
 */
class AuditLoggingTest extends IdentityTestCase
{
    public function test_a_role_change_is_audit_logged_with_before_and_after(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();
        $target->assignRole('member');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/users/{$target->id}/role", ['role' => 'operations'])->assertOk();

        $activity = Activity::where('description', 'user_role_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($target->id, $activity->subject_id);
        $this->assertSame(['member'], $activity->properties['before']);
        $this->assertSame(['operations'], $activity->properties['after']);
    }

    public function test_a_status_change_is_audit_logged_with_before_and_after(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();
        $target->assignRole('member');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/users/{$target->id}/status", ['status' => 'suspended'])->assertOk();

        $activity = Activity::where('description', 'user_status_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame('active', $activity->properties['before']);
        $this->assertSame('suspended', $activity->properties['after']);
    }

    public function test_company_creation_is_audit_logged(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $poRequest = PrivateOfficeRequest::factory()->quoted()->create();
        $branch = Branch::factory()->create();

        $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $poRequest->id,
            'legal_name' => 'ACME LLC',
            'contract_ref' => 'C-9001',
            'branch_id' => $branch->id,
        ])->assertCreated();

        $activity = Activity::where('description', 'company_created')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($operator->id, $activity->causer_id);
        $this->assertSame($poRequest->id, $activity->properties['private_office_request_id']);
    }

    public function test_door_access_toggle_is_audit_logged(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['door_access_enabled' => false]);

        $this->patchJson("/api/v1/admin/companies/{$company->id}/members/{$member->id}", [
            'door_access_enabled' => true,
        ])->assertOk();

        $activity = Activity::where('description', 'company_member_door_access_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($member->id, $activity->properties['user_id']);
        $this->assertTrue($activity->properties['door_access_enabled']);
    }

    public function test_company_member_added_and_removed_are_audit_logged(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $company = Company::factory()->create();
        $member = User::factory()->create();

        $this->postJson("/api/v1/admin/companies/{$company->id}/members", [
            'user_id' => $member->id,
        ])->assertCreated();

        $this->assertNotNull(Activity::where('description', 'company_member_added')->first());

        $this->deleteJson("/api/v1/admin/companies/{$company->id}/members/{$member->id}")->assertNoContent();

        $this->assertNotNull(Activity::where('description', 'company_member_removed')->first());
    }
}
