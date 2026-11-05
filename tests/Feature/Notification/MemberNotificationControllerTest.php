<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_member_can_list_notifications(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        // Create some in-app notifications
        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $member->notify(new GeneralNotification('wallet.topped_up', ['amount' => '100', 'currency' => 'USD']));

        $response = $this->getJson('/api/v1/member/notifications');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertExactJson([
            'data' => $response->json('data'),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20],
        ]);
    }

    public function test_listing_a_page_marks_only_that_page_read(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        for ($i = 0; $i < 25; $i++) {
            $member->notify(new GeneralNotification('booking.approved', ['space' => "Room {$i}"]));
        }

        $first = $this->getJson('/api/v1/member/notifications')->assertOk();

        // The page still shows its notices as new; they are read only afterwards.
        $this->assertSame(array_fill(0, 20, null), array_column($first->json('data'), 'read_at'));

        $firstIds = array_column($first->json('data'), 'id');
        $this->assertSame(20, $member->notifications()->whereKey($firstIds)->whereNotNull('read_at')->count());
        $this->getJson('/api/v1/member/notifications/unread-count')
            ->assertJson(['data' => ['unread_count' => 5]]);

        $second = $this->getJson('/api/v1/member/notifications?page=2')->assertOk();
        $this->assertSame(array_fill(0, 5, null), array_column($second->json('data'), 'read_at'));
        $this->getJson('/api/v1/member/notifications/unread-count')
            ->assertJson(['data' => ['unread_count' => 0]]);
    }

    public function test_listing_keeps_the_original_read_time_of_read_notices(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $notification = $member->notifications()->first();
        $notification->forceFill(['read_at' => now()->subDay()])->save();
        $readAt = $notification->fresh()->read_at;

        $this->getJson('/api/v1/member/notifications')->assertOk();

        $this->assertEquals($readAt, $notification->fresh()->read_at);
    }

    public function test_listing_the_member_inbox_leaves_other_inboxes_unread(): void
    {
        $person = User::factory()->create();
        $person->assignRole(['member', 'admin']);
        $person->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $person->notifications()->first()->forceFill(['audience' => NotificationAudience::Staff])->save();
        $person->notify(new GeneralNotification('wallet.topped_up', ['amount' => '100', 'currency' => 'USD']));
        Sanctum::actingAs($person, ['member-app']);

        $this->getJson('/api/v1/member/notifications')->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(1, $person->unreadNotifications()->count());
        $this->assertSame(NotificationAudience::Staff, $person->unreadNotifications()->first()->audience);
    }

    public function test_listing_the_staff_inbox_does_not_mark_it_read(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $admin->notifications()->update(['audience' => NotificationAudience::Staff]);
        Sanctum::actingAs($admin, ['dashboard']);

        $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(1, $admin->unreadNotifications()->count());
    }

    public function test_member_can_mark_notification_as_read(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $notification = $member->notifications()->first();

        $this->assertNull($notification->read_at);

        $response = $this->patchJson("/api/v1/member/notifications/{$notification->id}/read", [], ['lang' => 'en']);

        $response->assertOk();
        $response->assertJson(['message' => 'Notification marked as read.']);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_member_can_mark_all_as_read(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $member->notify(new GeneralNotification('wallet.topped_up', ['amount' => '100', 'currency' => 'USD']));

        $this->assertEquals(2, $member->unreadNotifications()->count());

        $response = $this->postJson('/api/v1/member/notifications/read-all', [], ['lang' => 'en']);

        $response->assertOk();
        $response->assertJson(['message' => 'All notifications marked as read.']);

        $this->assertEquals(0, $member->fresh()->unreadNotifications()->count());
    }

    public function test_member_can_get_unread_count(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $member->notify(new GeneralNotification('wallet.topped_up', ['amount' => '100', 'currency' => 'USD']));

        // Mark one as read
        $member->notifications()->first()->markAsRead();

        $response = $this->getJson('/api/v1/member/notifications/unread-count');

        $response->assertOk();
        $response->assertJson(['data' => ['unread_count' => 1]]);
    }

    public function test_notification_sent_in_user_preferred_language(): void
    {
        Notification::fake();

        $member = User::factory()->create(['preferred_language' => 'ar']);
        $member->assignRole('member');

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'غرفة أ']));

        Notification::assertSentTo($member, GeneralNotification::class, function ($notification) use ($member) {
            $data = $notification->toArray($member);

            return $data['title'] === 'تمت الموافقة على حجزك'
                && str_contains($data['body'], 'غرفة أ');
        });
    }

    public function test_notification_falls_back_to_default_locale(): void
    {
        Notification::fake();

        // The column is NOT NULL (default 'ar'), so an account without a
        // preference only exists in memory; the notice still falls back.
        $member = User::factory()->create();
        $member->assignRole('member');
        $member->preferred_language = null;

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));

        Notification::assertSentTo($member, GeneralNotification::class, function ($notification) use ($member) {
            $data = $notification->toArray($member);

            return $data['title'] === 'Booking Approved'
                && str_contains($data['body'], 'Room A');
        });
    }

    public function test_member_cannot_read_another_users_notification(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        $otherMember = User::factory()->create();
        $otherMember->assignRole('member');

        $otherMember->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $notification = $otherMember->notifications()->first();

        Sanctum::actingAs($member, ['member-app']);

        $response = $this->patchJson("/api/v1/member/notifications/{$notification->id}/read");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_access_notifications(): void
    {
        $response = $this->getJson('/api/v1/member/notifications');

        $response->assertUnauthorized();
    }
}
