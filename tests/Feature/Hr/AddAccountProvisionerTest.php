<?php

namespace Tests\Feature\Hr;

use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * In ADD every employee is an ADD ID (config hr.require_user): hiring links
 * the account that already has the phone or the work email, or creates a
 * dashboard account — with no role, since being an employee is not one —
 * and lets the employee set their own password. Termination takes back the
 * staff roles and dashboard sessions, never the account: it may be a
 * member's.
 */
class AddAccountProvisionerTest extends TestCase
{
    use HiresAddEmployees;
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

    public function test_hire_links_existing_member_by_phone(): void
    {
        $member = User::factory()->create(['phone' => '+963944000001']);
        $member->assignRole('member');

        $id = $this->postJson('/api/v1/hr/employees', $this->hirePayload(['phone' => '+963944000001']))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($member->id, (int) Employee::query()->findOrFail($id)->user_id);
        $this->assertSame(1, User::query()->where('phone', '+963944000001')->count());
    }

    public function test_hire_links_existing_account_by_work_email(): void
    {
        $operator = User::factory()->create(['email' => 'rami@add.sy']);

        $id = $this->postJson('/api/v1/hr/employees', $this->hirePayload(['work_email' => 'rami@add.sy', 'phone' => '+963944000099']))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($operator->id, (int) Employee::query()->findOrFail($id)->user_id);
    }

    public function test_hire_creates_account_without_roles_and_sends_reset_link(): void
    {
        Notification::fake();

        $id = $this->postJson('/api/v1/hr/employees', $this->hirePayload([
            'name' => ['ar' => 'عمر الشامي', 'en' => 'Omar Shami'],
            'phone' => '+963933190664',
            'work_email' => 'omar@add.sy',
        ]))->assertCreated()->json('data.id');

        $user = User::query()->where('email', 'omar@add.sy')->sole();
        $this->assertSame($user->id, (int) Employee::query()->findOrFail($id)->user_id);
        $this->assertSame('+963933190664', $user->phone);
        // ADD accounts default to Arabic, and Arabic is the one name HR always has.
        $this->assertSame('عمر الشامي', $user->name);
        $this->assertSame('active', $user->status);
        $this->assertSame([], $user->getRoleNames()->all());

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_new_account_needs_a_work_email_and_a_phone(): void
    {
        // Each on its field, so the hire form can show it there.
        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['phone' => '+963933000000']))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.validation.failed'))
            ->assertJsonPath('errors.work_email.0', __('api.hr.work_email_required'));

        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['work_email' => 'nophone@add.sy']))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.validation.failed'))
            ->assertJsonPath('errors.phone.0', __('api.hr.phone_required'));

        // Member sign-in is by phone, so a new ADD ID needs the E.164 form.
        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['work_email' => 'local@add.sy', 'phone' => '0944 210 118']))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.validation.failed'))
            ->assertJsonPath('errors.phone.0', __('api.hr.phone_invalid'));

        $this->assertDatabaseCount('hr_employees', 0);
    }

    public function test_conflicting_phone_and_email_owners_are_rejected(): void
    {
        User::factory()->create(['phone' => '+963944000002']);
        User::factory()->create(['email' => 'someone@add.sy']);

        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['phone' => '+963944000002', 'work_email' => 'someone@add.sy']))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.hr.account_conflict'));

        $this->assertDatabaseCount('hr_employees', 0);
    }

    public function test_hr_manager_cannot_link_a_staff_account_but_admin_can(): void
    {
        Role::create(['name' => 'hr_manager', 'guard_name' => 'web'])->givePermissionTo(['hr.employees.create', 'hr.lifecycle.manage']);
        $hrManager = User::factory()->create();
        $hrManager->assignRole('hr_manager');
        $administrator = User::factory()->create(['phone' => '+963944000777']);
        $administrator->assignRole('admin');
        Sanctum::actingAs($hrManager, ['*']);

        // Linking gives HR the power to strip the account's roles at termination.
        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['user_id' => $administrator->id]))
            ->assertForbidden()
            ->assertJsonPath('message', __('api.hr.account_link_forbidden'));
        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['phone' => '+963944000777']))
            ->assertForbidden();
        $this->assertDatabaseCount('hr_employees', 0);

        // A member's account carries no staff role: HR may link it.
        $member = User::factory()->create();
        $member->assignRole('member');
        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['user_id' => $member->id]))->assertCreated();

        // Someone who may assign roles may link a staff account.
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['user_id' => $administrator->id]))->assertCreated();
    }

    public function test_termination_never_strips_admin_or_provider(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['admin', 'operations', 'member']);
        $employee = $this->hireFor($user, ['hired_on' => now()->subYear()->toDateString()]);

        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::Termination, [
            'effective_on' => now()->subDay()->toDateString(),
            'reason' => 'retirement',
        ], null);

        $this->assertEqualsCanonicalizing(['admin', 'member'], $user->fresh()->getRoleNames()->all());
    }

    public function test_a_failing_reset_link_does_not_fail_the_saved_hire(): void
    {
        $broker = Mockery::mock(PasswordBroker::class);
        $broker->shouldReceive('sendResetLink')->andThrow(new RuntimeException('smtp down'));
        Password::shouldReceive('broker')->andReturn($broker);

        $this->postJson('/api/v1/hr/employees', $this->hirePayload(['phone' => '+963933000123', 'work_email' => 'nomail@add.sy']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'nomail@add.sy']);
    }

    public function test_termination_strips_staff_roles_keeps_member_and_ends_sessions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['operations', 'member']);
        DB::table('sessions')->insert([
            'id' => 'dashboard-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        $employee = $this->hireFor($user, ['hired_on' => now()->subYear()->toDateString()]);

        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::Termination, [
            'effective_on' => now()->subDay()->toDateString(),
            'reason' => 'resignation',
        ], null);

        $user->refresh();
        $this->assertSame(['member'], $user->getRoleNames()->all());
        $this->assertSame('active', $user->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }
}
