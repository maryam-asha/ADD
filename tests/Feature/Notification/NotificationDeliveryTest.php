<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationCreated;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * A notification is delivered in the request (the host runs no queue
 * worker), only once the change it announces is saved, and nudges the
 * reader's open screens over Pusher.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->member = User::factory()->create(['status' => 'active', 'preferred_language' => 'en', 'fcm_token' => 'phone']);
        $this->member->assignRole('member');
    }

    public function test_rolled_back_change_sends_nothing(): void
    {
        // The row would roll back with the transaction anyway; the push and
        // the nudge would not — they must wait for the commit.
        $pushes = new class extends MockPushProvider
        {
            public int $calls = 0;

            public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): array
            {
                $this->calls++;

                return parent::sendToDevices($fcmTokens, $title, $body, $data);
            }
        };
        $this->app->instance(PushProvider::class, $pushes);
        Event::fake([NotificationCreated::class]);

        try {
            DB::transaction(function () {
                app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

                throw new RuntimeException('the booking was not saved');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $pushes->calls);
        Event::assertNotDispatched(NotificationCreated::class);
    }

    public function test_notification_is_written_without_a_queue(): void
    {
        Queue::fake();

        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

        $this->assertDatabaseCount('notifications', 1);
        Queue::assertNothingPushed();
    }

    public function test_member_notice_nudges_the_member_channel(): void
    {
        Event::fake([NotificationCreated::class]);

        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => $event->broadcastOn()[0]->name === 'private-member.'.$this->member->id
            && $event->broadcastAs() === 'notification.created'
            && $event->broadcastWith() === ['audience' => 'member']);
    }

    public function test_staff_notice_nudges_the_dashboard_channel(): void
    {
        Event::fake([NotificationCreated::class]);

        app(NotificationService::class)->sendToUser(
            $this->member,
            'inquiry.new_received',
            ['sender_name' => 'Sara', 'subject' => 'Offices'],
            audience: NotificationAudience::Staff,
        );

        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => $event->broadcastOn()[0]->name === 'private-dashboard.'.$this->member->id);
    }
}
