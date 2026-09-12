<?php

namespace Tests\Feature\Notification;

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
    }

    public function test_member_can_mark_notification_as_read(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'Room A']));
        $notification = $member->notifications()->first();

        $this->assertNull($notification->read_at);

        $response = $this->patchJson("/api/v1/member/notifications/{$notification->id}/read");

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

        $response = $this->postJson('/api/v1/member/notifications/read-all');

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
        $response->assertJson(['unread_count' => 1]);
    }

    public function test_notification_sent_in_user_preferred_language(): void
    {
        Notification::fake();

        $member = User::factory()->create(['preferred_language' => 'ar']);
        $member->assignRole('member');

        $member->notify(new GeneralNotification('booking.approved', ['space' => 'غرفة أ']));

        Notification::assertSentTo($member, GeneralNotification::class, function ($notification) use ($member) {
            $data = $notification->toArray($member);

            return $data['title'] === 'تمت الموافقة على الحجز'
                && str_contains($data['body'], 'غرفة أ');
        });
    }

    public function test_notification_falls_back_to_default_locale(): void
    {
        Notification::fake();

        $member = User::factory()->create(['preferred_language' => null]);
        $member->assignRole('member');

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
