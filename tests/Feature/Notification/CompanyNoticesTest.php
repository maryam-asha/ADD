<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\CompanyInvitationAcceptor;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * Joining a company, leaving it, and the two flags on that membership (door
 * access, company admin): each change reaches the member it is about —
 * unless they made it themselves, or nothing actually changed.
 */
class CompanyNoticesTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    private Company $company;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->company = Company::factory()->create(['legal_name' => 'Acme']);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
    }

    public function test_staff_adding_removing_and_changing_flags_tells_the_member(): void
    {
        $this->actingAsStaff();
        $members = "/api/v1/admin/companies/{$this->company->id}/members";

        $this->postJson($members, ['user_id' => $this->member->id])->assertCreated();
        $this->patchJson("{$members}/{$this->member->id}", ['door_access_enabled' => true])->assertOk();
        $this->patchJson("{$members}/{$this->member->id}/admin", ['is_admin' => true])->assertOk();
        $this->patchJson("{$members}/{$this->member->id}/admin", ['is_admin' => false])->assertOk();
        $this->patchJson("{$members}/{$this->member->id}", ['door_access_enabled' => false])->assertOk();
        $this->deleteJson("{$members}/{$this->member->id}")->assertNoContent();

        $notice = $this->assertNoticed($this->member, 'company.member_joined');
        $this->assertStringContainsString('Acme', $this->noticeText($notice, 'en')['title']);
        $this->assertNoticed($this->member, 'company.door_access_granted');
        $this->assertNoticed($this->member, 'company.admin_assigned');
        $this->assertNoticed($this->member, 'company.admin_removed');
        $this->assertNoticed($this->member, 'company.door_access_revoked');
        $this->assertNoticed($this->member, 'company.member_removed');
    }

    public function test_an_unchanged_flag_tells_nobody(): void
    {
        $this->company->members()->attach($this->member->id, ['door_access_enabled' => true, 'is_admin' => false]);
        $this->actingAsStaff();

        $this->patchJson("/api/v1/admin/companies/{$this->company->id}/members/{$this->member->id}", ['door_access_enabled' => true])->assertOk();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_changing_your_own_flags_tells_nobody(): void
    {
        $this->company->members()->attach($this->member->id, ['door_access_enabled' => true, 'is_admin' => true]);
        Sanctum::actingAs($this->member, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$this->company->id}/members/{$this->member->id}", ['door_access_enabled' => false])->assertOk();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_company_admin_changing_a_colleague_tells_the_colleague(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $this->company->members()->attach($admin->id, ['door_access_enabled' => true, 'is_admin' => true]);
        $this->company->members()->attach($this->member->id, ['door_access_enabled' => false, 'is_admin' => false]);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$this->company->id}/members/{$this->member->id}", ['door_access_enabled' => true])->assertOk();

        $this->assertNoticed($this->member, 'company.door_access_granted');
    }

    public function test_provider_paths_tell_the_member(): void
    {
        $provider = Provider::factory()->create();
        $this->company->update(['provider_id' => $provider->id]);
        $reviewer = User::factory()->create();
        $reviewer->assignRole('admin');

        // An employee request for an existing member joins them now…
        $employee = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'type' => ProviderRequestType::CompanyMember,
            'company_id' => $this->company->id,
            'payload' => ['phone' => $this->member->phone, 'door_access_enabled' => false],
        ]);
        app(ProviderRequestService::class)->approve($employee, $reviewer);
        // …an admin request makes them admin…
        app(ProviderRequestService::class)->approve(ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'type' => ProviderRequestType::CompanyAdmin,
            'company_id' => $this->company->id,
            'payload' => ['user_id' => $this->member->id],
        ]), $reviewer);
        // …and an invitation is accepted when its phone signs up.
        $newcomer = User::factory()->create();
        CompanyInvitation::query()->create([
            'company_id' => $this->company->id,
            'phone' => $newcomer->phone,
            'door_access_enabled' => true,
            'provider_request_id' => $employee->id,
        ]);
        app(CompanyInvitationAcceptor::class)->acceptFor($newcomer);

        $this->assertNoticed($this->member, 'company.member_joined');
        $this->assertNoticed($this->member, 'company.admin_assigned');
        $this->assertNoticed($newcomer, 'company.member_joined');
    }

    private function actingAsStaff(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('admin');
        Sanctum::actingAs($staff, ['dashboard']);
    }
}
