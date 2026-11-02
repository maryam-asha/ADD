<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use App\Notifications\GeneralNotification;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One notifications table, three inboxes: each lists only its own audience,
 * so a person who is both a member and staff never sees a staff notice on
 * their phone (docs/superpowers/specs/2026-11-02-notifications-design.md).
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_staff_inbox_lists_only_staff_notices(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole('admin');
        $staff->notify($this->notice(NotificationAudience::Staff));
        $staff->notify($this->notice(NotificationAudience::Member));
        Sanctum::actingAs($staff, ['dashboard']);

        $this->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/notifications/unread-count')
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_provider_inbox_lists_only_provider_notices(): void
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create(['status' => 'active']);
        $account->assignRole('provider');
        $provider->users()->attach($account->id);
        $account->notify($this->notice(NotificationAudience::Provider));
        $account->notify($this->notice(NotificationAudience::Member));
        Sanctum::actingAs($account, ['*']);

        $this->getJson('/api/v1/provider/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_staff_notice_stays_out_of_member_inbox_and_off_the_phone(): void
    {
        $person = User::factory()->create(['status' => 'active', 'fcm_token' => 'phone-token', 'preferred_language' => 'en']);
        $person->assignRole(['member', 'admin']);

        app(NotificationService::class)->sendToUser(
            $person,
            'inquiry.new_received',
            ['sender_name' => 'Sara', 'subject' => 'Offices'],
            audience: NotificationAudience::Staff,
        );

        Sanctum::actingAs($person, ['member-app']);
        $this->getJson('/api/v1/member/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_read_all_marks_only_this_inbox(): void
    {
        $person = User::factory()->create(['status' => 'active']);
        $person->assignRole(['member', 'admin']);
        $person->notify($this->notice(NotificationAudience::Staff));
        $person->notify($this->notice(NotificationAudience::Member));
        Sanctum::actingAs($person, ['dashboard']);

        $this->postJson('/api/v1/admin/notifications/read-all')->assertOk();

        $this->assertSame(1, $person->unreadNotifications()->count());
        $this->assertSame(NotificationAudience::Member, $person->unreadNotifications()->first()->audience);
    }

    public function test_a_staff_inbox_cannot_mark_a_member_notice_read(): void
    {
        $person = User::factory()->create(['status' => 'active']);
        $person->assignRole(['member', 'admin']);
        $person->notify($this->notice(NotificationAudience::Member));
        Sanctum::actingAs($person, ['dashboard']);

        $id = $person->notifications()->first()->id;

        $this->patchJson("/api/v1/admin/notifications/{$id}/read")->assertNotFound();
    }

    public function test_member_and_provider_accounts_cannot_open_the_staff_inbox(): void
    {
        $member = User::factory()->create(['status' => 'active']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['dashboard']);

        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
    }

    private function notice(NotificationAudience $audience): GeneralNotification
    {
        return new GeneralNotification('booking.approved', ['space' => 'Room A'], audience: $audience);
    }
}
