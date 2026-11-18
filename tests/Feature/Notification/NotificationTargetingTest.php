<?php

namespace Tests\Feature\Notification;

use App\Domain\Hr\Adapters\AddHrNotifier;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * Staff are reached by the permissions they hold, whatever their role is
 * called (docs/decisions/rbac-permission-pilot.md), and their notices land in
 * the dashboard.
 */
class NotificationTargetingTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_holders_of_the_permission_are_notified_whatever_their_role(): void
    {
        Role::create(['name' => 'desk_clerk', 'guard_name' => 'web'])->givePermissionTo('site-inquiries.view');
        $clerk = User::factory()->create(['status' => 'active', 'preferred_language' => 'en']);
        $clerk->assignRole('desk_clerk');
        $member = User::factory()->create(['status' => 'active', 'preferred_language' => 'en']);
        $member->assignRole('member');

        $this->postJson('/api/v1/site-inquiries', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+963912345678',
            'subject' => 'Private Office Pricing',
            'message' => 'I would like to inquire about monthly desk pricing.',
        ])->assertCreated();

        $this->assertSame(1, $clerk->notifications()->forAudience(NotificationAudience::Staff)->count());
        $this->assertSame(0, $member->notifications()->count());
    }

    public function test_unknown_permission_notifies_nobody(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        app(NotificationService::class)->sendToHolders('nothing.here', 'inquiry.new_received', ['sender_name' => 'Sara', 'subject' => 'Offices']);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_hr_employee_notice_follows_the_account(): void
    {
        $staff = User::factory()->create(['status' => 'active', 'preferred_language' => 'en']);
        $staff->assignRole('admin');
        $member = User::factory()->create(['status' => 'active', 'preferred_language' => 'en']);
        $member->assignRole('member');

        $notifier = app(AddHrNotifier::class);
        $notifier->notifyEmployee($this->hireFor($staff), 'payslip_issued', ['month' => '2026-10']);
        $notifier->notifyEmployee($this->hireFor($member), 'payslip_issued', ['month' => '2026-10']);

        $this->assertSame(NotificationAudience::Staff, $staff->notifications()->first()->audience);
        $this->assertSame(NotificationAudience::Member, $member->notifications()->first()->audience);
    }
}
